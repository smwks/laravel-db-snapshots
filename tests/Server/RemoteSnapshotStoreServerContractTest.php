<?php

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use SMWks\LaravelDbSnapshots\Stores\RemoteSnapshotStore;
use Symfony\Component\HttpFoundation\StreamedResponse;

// This suite proves the client (RemoteSnapshotStore) and server
// (SnapshotServerController, reached via the real routes.php + the real
// AuthenticateSnapshotProject middleware) actually agree on wire format —
// list JSON shape, multipart field names, status codes — something neither
// tests/Stores/RemoteSnapshotStoreTest.php (asserts only against
// Http::fake()) nor tests/Server/*Test.php (asserts only against synthetic
// Testbench requests) can catch on their own.
//
// Approach used, and why: a true loopback HTTP call (RemoteSnapshotStore
// making a real TCP connection back into this same test process) would
// require standing up a real listening HTTP server for the Testbench app
// (there is no public/index.php or workbench skeleton in this package to
// serve, and spawning + managing a subprocess bound to a port is exactly
// the kind of thing that's fragile/unavailable in a sandboxed CI
// environment). Instead, Http::fake() is used as a pure *transport* shim:
// its callback takes the exact outgoing request RemoteSnapshotStore built
// (method, URL, headers, raw multipart body — nothing hand-constructed)
// and replays it, byte for byte, through the real HTTP kernel: real
// routes.php constraints, real AuthenticateSnapshotProject middleware, real
// SnapshotServerController, real Storage disk. The response that comes back
// is the actual controller's real output, fed straight back into
// RemoteSnapshotStore's own response-parsing code. No response body/shape
// is hand-authored anywhere in this file — both sides are the real
// production code, only the socket is swapped for an in-process dispatch.

function makeContractStore(): RemoteSnapshotStore
{
    return new RemoteSnapshotStore(
        endpoint: 'http://localhost/api/db-snapshots',
        project: 'my-app',
        plan: 'daily',
        token: 'my-app-token',
    );
}

/**
 * Minimal RFC 1867 multipart/form-data parser for the exact shape Guzzle's
 * MultipartStream (used by Http::attach()) produces. Needed because
 * Symfony's Request::create() does not parse a raw multipart body the way
 * the real PHP SAPI would from superglobals — so this stands in for that.
 */
function parseGuzzleMultipart(string $body, string $boundary): array
{
    $fields = [];
    $files = [];

    $parts = preg_split('/--'.preg_quote($boundary, '/').'(--)?\r\n/', $body);

    foreach ($parts as $part) {
        $part = trim($part, "\r\n");

        if ($part === '' || $part === '--') {
            continue;
        }

        [$rawHeaders, $content] = array_pad(explode("\r\n\r\n", $part, 2), 2, '');

        $name = null;
        $filename = null;

        foreach (explode("\r\n", $rawHeaders) as $headerLine) {
            if (stripos($headerLine, 'Content-Disposition:') === 0) {
                if (preg_match('/name="([^"]+)"/', $headerLine, $m)) {
                    $name = $m[1];
                }

                if (preg_match('/filename="([^"]+)"/', $headerLine, $m)) {
                    $filename = $m[1];
                }
            }
        }

        if ($name === null) {
            continue;
        }

        if ($filename !== null) {
            // Illuminate\Http\UploadedFile::fake() (rather than a plain
            // Symfony UploadedFile) is required here: Laravel's own file
            // conversion (InteractsWithInput::convertUploadedFiles()) calls
            // UploadedFile::createFromBase($file) with $test hardcoded to
            // false, which would make a hand-built Symfony UploadedFile
            // fail the controller's 'file' validation rule (isValid() then
            // requires is_uploaded_file(), which is never true for a file
            // that didn't arrive via a real PHP upload). An already-fake
            // Illuminate UploadedFile is passed through createFromBase()
            // unchanged (it's already `instanceof static`), preserving its
            // test-mode validity.
            $files[$name] = UploadedFile::fake()->createWithContent($filename, $content);
        } else {
            $fields[$name] = $content;
        }
    }

    return [$fields, $files];
}

/**
 * Replays an outgoing Illuminate\Http\Client\Request through this app's own
 * real HTTP kernel — using the exact same TestCase::call() mechanism that
 * $this->get()/post()/delete() already use elsewhere in this suite (real
 * routing, real middleware, real controller) — and returns the real
 * response. Used as the body of an Http::fake() callback, bound to the
 * running test case so it can reuse $this->call().
 */
function dispatchThroughRealServer($testCase, ClientRequest $request)
{
    $psrRequest = $request->toPsrRequest();
    $uri = $psrRequest->getUri();

    $contentType = $request->header('Content-Type')[0] ?? '';
    $bodyContents = $request->body();

    $fields = [];
    $files = [];

    if (str_starts_with($contentType, 'multipart/form-data')) {
        preg_match('/boundary=(.*)$/', $contentType, $m);
        [$fields, $files] = parseGuzzleMultipart($bodyContents, trim($m[1] ?? '', '"'));
    }

    $serverHeaders = [];
    foreach ($request->headers() as $name => $values) {
        if (in_array(strtolower($name), ['content-type', 'content-length', 'host'])) {
            continue;
        }

        $serverHeaders['HTTP_'.strtoupper(str_replace('-', '_', $name))] = implode(', ', $values);
    }

    $path = $uri->getPath().($uri->getQuery() ? '?'.$uri->getQuery() : '');

    $response = $testCase->call(
        $request->method(),
        $path,
        $fields,
        [],
        $files,
        $serverHeaders,
        str_starts_with($contentType, 'multipart/form-data') ? null : $bodyContents,
    );

    // download() returns a StreamedResponse (real controller behavior, so
    // the client can handle large snapshot files without buffering them in
    // memory); Symfony's getContent() deliberately returns false for those
    // since the body is only produced by the streaming callback, so it must
    // be captured through output buffering instead.
    $body = $response->baseResponse instanceof StreamedResponse
        ? $response->streamedContent()
        : $response->getContent();

    return Http::response($body, $response->getStatusCode(), $response->headers->all());
}

test('publish, list, download, and delete all round-trip through the real server routes and controller', function () {
    Http::fake(fn (ClientRequest $request) => dispatchThroughRealServer($this, $request));

    $store = makeContractStore();

    $localFile = tempnam(sys_get_temp_dir(), 'contract-snapshot');
    file_put_contents($localFile, 'real snapshot bytes from the contract test');

    try {
        // publish() -> real store() controller action, real disk writes.
        $store->publish('daily-20250209.sql.gz', $localFile, ['size' => 44, 'tags' => ['contract' => true]]);
    } finally {
        unlink($localFile);
    }

    expect(Storage::disk('local')->get('server-snapshots/my-app/daily/daily-20250209.sql.gz'))
        ->toBe('real snapshot bytes from the contract test');
    expect(json_decode(Storage::disk('local')->get('server-snapshots/my-app/daily/daily-20250209.sql.gz.json'), true))
        ->toBe(['size' => 44, 'tags' => ['contract' => true]]);

    // allFiles()/metadata() -> real index() controller action's JSON shape.
    expect($store->allFiles())->toBe(['daily-20250209.sql.gz']);
    expect($store->metadata('daily-20250209.sql.gz'))->toBe(['size' => 44, 'tags' => ['contract' => true]]);
    expect($store->exists('daily-20250209.sql.gz'))->toBeTrue();
    expect($store->size('daily-20250209.sql.gz'))->toBe(44);

    // get()/readStream() -> real download() controller action (streamed response).
    expect($store->get('daily-20250209.sql.gz'))->toBe('real snapshot bytes from the contract test');

    // delete() -> real destroy() controller action, real disk removal.
    expect($store->delete('daily-20250209.sql.gz'))->toBeTrue();
    expect(Storage::disk('local')->exists('server-snapshots/my-app/daily/daily-20250209.sql.gz'))->toBeFalse();
    expect(Storage::disk('local')->exists('server-snapshots/my-app/daily/daily-20250209.sql.gz.json'))->toBeFalse();
    expect($store->allFiles())->toBe([]);
});

test('a wrong token is rejected by the real AuthenticateSnapshotProject middleware, not synthesized', function () {
    Http::fake(fn (ClientRequest $request) => dispatchThroughRealServer($this, $request));

    $store = new RemoteSnapshotStore(
        endpoint: 'http://localhost/api/db-snapshots',
        project: 'my-app',
        plan: 'daily',
        token: 'wrong-token',
    );

    expect(fn () => $store->allFiles())->toThrow(RuntimeException::class, 'Failed to list remote snapshots');
});

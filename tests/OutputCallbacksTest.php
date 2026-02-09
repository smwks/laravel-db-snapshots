<?php

use Illuminate\Support\Facades\Storage;
use SMWks\LaravelDbSnapshots\PlanGroup;
use SMWks\LaravelDbSnapshots\SnapshotPlan;

test('snapshot plan can set messaging callback', function () {
    $snapshotPlan = new SnapshotPlan('daily', defaultDailyConfig());

    $messages = [];
    $snapshotPlan->displayMessagesUsing(function ($message) use (&$messages) {
        $messages[] = $message;
    });

    // Create a snapshot which should trigger messaging callbacks
    $snapshotPlan->create();

    // Verify that messages were captured
    expect($messages)->not->toBeEmpty();
    expect(count($messages))->toBeGreaterThan(0);

    // Verify that mysqldump command message was captured
    expect(collect($messages)->contains(fn ($msg) => str_contains($msg, 'Running:')))->toBeTrue();
});

test('snapshot can set progress callback', function () {
    // First create a snapshot to have something to download
    $snapshotPlan = new SnapshotPlan('daily', defaultDailyConfig());
    $snapshot = $snapshotPlan->create();

    // Now test downloading with progress callback
    $progressCalls = [];
    $snapshot->displayProgressUsing(function ($current, $total) use (&$progressCalls) {
        $progressCalls[] = ['current' => $current, 'total' => $total];
    });

    $snapshot->download(false);

    // Verify that progress callback was called
    expect($progressCalls)->not->toBeEmpty();
    expect(count($progressCalls))->toBeGreaterThan(0);

    // Verify the last call has current == total (completed)
    $lastCall = end($progressCalls);
    expect($lastCall['current'])->toBe($lastCall['total']);
});

test('snapshot plan has messaging callback for drop tables', function () {
    $snapshotPlan = new SnapshotPlan('daily', defaultDailyConfig());

    $messages = [];
    $snapshotPlan->displayMessagesUsing(function ($message) use (&$messages) {
        $messages[] = $message;
    });

    // Verify the callback is set up correctly by checking the property
    $reflection = new ReflectionClass($snapshotPlan);
    $property = $reflection->getProperty('messagingCallback');
    $property->setAccessible(true);

    expect($property->getValue($snapshotPlan))->not->toBeNull();
});

test('snapshot plan execute post load commands triggers messaging', function () {
    $config = defaultDailyConfig();
    $config['post_load_sqls'] = [
        'SET FOREIGN_KEY_CHECKS=0',
        'TRUNCATE TABLE sessions',
    ];

    $snapshotPlan = new SnapshotPlan('daily', $config);

    $messages = [];
    $snapshotPlan->displayMessagesUsing(function ($message) use (&$messages) {
        $messages[] = $message;
    });

    $snapshotPlan->executePostLoadCommands();

    // Verify that SQL command messages were captured
    expect($messages)->not->toBeEmpty();
    expect(count($messages))->toBeGreaterThanOrEqual(2);

    $sqlMessages = collect($messages)->filter(fn ($msg) => str_contains($msg, 'Running SQL:'));
    expect($sqlMessages->count())->toBeGreaterThanOrEqual(2);
});

test('plan group can set messaging callback', function () {
    // Create some test snapshots first
    $plan1 = new SnapshotPlan('daily', defaultDailyConfig());
    $plan1->create();

    config()->set('db-snapshots.plans', [
        'daily' => defaultDailyConfig(),
    ]);

    config()->set('db-snapshots.plan_groups', [
        'all' => [
            'plans' => ['daily'],
        ],
    ]);

    $planGroup = PlanGroup::find('all');

    $messages = [];
    $planGroup->displayMessagesUsing(function ($message) use (&$messages) {
        $messages[] = $message;
    });

    // Test createAll
    $planGroup->createAll();

    expect($messages)->not->toBeEmpty();
    expect(collect($messages)->contains(fn ($msg) => str_contains($msg, 'Creating snapshot for plan:')))->toBeTrue();
});

test('plan group load all triggers messaging', function () {
    // Create some test snapshots first
    $plan1 = new SnapshotPlan('daily', defaultDailyConfig());
    $plan1->create();

    config()->set('db-snapshots.plans', [
        'daily' => defaultDailyConfig(),
    ]);

    config()->set('db-snapshots.plan_groups', [
        'all' => [
            'plans' => ['daily'],
        ],
    ]);

    $planGroup = PlanGroup::find('all');

    $messages = [];
    $planGroup->displayMessagesUsing(function ($message) use (&$messages) {
        $messages[] = $message;
    });

    // Set environment for loading
    app()->detectEnvironment(fn () => 'local');

    $planGroup->loadAll(false, false, true); // skipPostCommands = true to avoid SQL execution

    expect($messages)->not->toBeEmpty();
    expect(collect($messages)->contains(fn ($msg) => str_contains($msg, 'Loading plan:')))->toBeTrue();
});

test('plan group has messaging callback for drop tables', function () {
    config()->set('db-snapshots.plans', [
        'daily' => defaultDailyConfig(),
    ]);

    config()->set('db-snapshots.plan_groups', [
        'all' => [
            'plans' => ['daily'],
        ],
    ]);

    $planGroup = PlanGroup::find('all');

    $messages = [];
    $planGroup->displayMessagesUsing(function ($message) use (&$messages) {
        $messages[] = $message;
    });

    // Verify the callback is set up correctly by checking the property
    $reflection = new ReflectionClass($planGroup);
    $property = $reflection->getProperty('messagingCallback');
    $property->setAccessible(true);

    expect($property->getValue($planGroup))->not->toBeNull();
});

test('plan group execute post load commands triggers messaging', function () {
    config()->set('db-snapshots.plans', [
        'daily' => defaultDailyConfig(),
    ]);

    config()->set('db-snapshots.plan_groups', [
        'all' => [
            'plans'          => ['daily'],
            'post_load_sqls' => [
                'SET FOREIGN_KEY_CHECKS=0',
            ],
        ],
    ]);

    $planGroup = PlanGroup::find('all');

    $messages = [];
    $planGroup->displayMessagesUsing(function ($message) use (&$messages) {
        $messages[] = $message;
    });

    $planGroup->executePostLoadCommands();

    expect($messages)->not->toBeEmpty();
    expect(collect($messages)->contains(fn ($msg) => str_contains($msg, 'Running SQL:')))->toBeTrue();
});

test('callbacks are optional', function () {
    // Test that operations work without callbacks set
    $snapshotPlan = new SnapshotPlan('daily', defaultDailyConfig());

    // Should not throw exception when no callback is set
    $snapshot = $snapshotPlan->create();
    expect($snapshot)->not->toBeNull();

    // Download without progress callback should work
    $snapshot->download(false);
    expect($snapshot->existsLocally())->toBeTrue();
});

test('messaging callback propagates to child plans', function () {
    config()->set('db-snapshots.plans', [
        'daily'   => defaultDailyConfig(),
        'monthly' => defaultDailyConfig(),
    ]);

    config()->set('db-snapshots.plan_groups', [
        'all' => [
            'plans' => ['daily', 'monthly'],
        ],
    ]);

    $planGroup = PlanGroup::find('all');

    $messages = [];
    $planGroup->displayMessagesUsing(function ($message) use (&$messages) {
        $messages[] = $message;
    });

    $planGroup->createAll();

    // Should have messages from both plans
    expect(collect($messages)->contains(fn ($msg) => str_contains($msg, 'plan: daily')))->toBeTrue();
    expect(collect($messages)->contains(fn ($msg) => str_contains($msg, 'plan: monthly')))->toBeTrue();
});

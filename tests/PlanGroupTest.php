<?php

use SMWks\LaravelDbSnapshots\PlanGroup;

test('find throws exception when name is empty', function () {
    config()->set('db-snapshots.plan_groups', [
        'daily-group' => [
            'plans' => ['daily'],
        ],
    ]);

    config()->set('db-snapshots.plans', [
        'daily' => defaultDailyConfig(),
    ]);

    PlanGroup::find('');
})->throws(InvalidArgumentException::class, 'Plan group name cannot be empty');

test('find returns null when plan group does not exist', function () {
    config()->set('db-snapshots.plan_groups', [
        'daily-group' => [
            'plans' => ['daily'],
        ],
    ]);

    config()->set('db-snapshots.plans', [
        'daily' => defaultDailyConfig(),
    ]);

    $planGroup = PlanGroup::find('non-existent-group');

    expect($planGroup)->toBeNull();
});

test('find returns plan group when it exists', function () {
    config()->set('db-snapshots.plan_groups', [
        'daily-group' => [
            'plans'          => ['daily'],
            'post_load_sqls' => ['SELECT 1'],
        ],
    ]);

    config()->set('db-snapshots.plans', [
        'daily' => defaultDailyConfig(),
    ]);

    $planGroup = PlanGroup::find('daily-group');

    expect($planGroup)->toBeInstanceOf(PlanGroup::class);
    expect($planGroup->name)->toBe('daily-group');
    expect($planGroup->planNames)->toBe(['daily']);
    expect($planGroup->postLoadSqls)->toBe(['SELECT 1']);
});

test('all returns empty collection when no plan groups configured', function () {
    config()->set('db-snapshots.plan_groups', []);

    $planGroups = PlanGroup::all();

    expect($planGroups)->toHaveCount(0);
});

test('all returns all plan groups', function () {
    config()->set('db-snapshots.plan_groups', [
        'group-1' => [
            'plans' => ['daily'],
        ],
        'group-2' => [
            'plans' => ['daily'],
        ],
    ]);

    config()->set('db-snapshots.plans', [
        'daily' => defaultDailyConfig(),
    ]);

    $planGroups = PlanGroup::all();

    expect($planGroups)->toHaveCount(2);
    expect($planGroups->first())->toBeInstanceOf(PlanGroup::class);
});

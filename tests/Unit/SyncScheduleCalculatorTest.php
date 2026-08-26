<?php

use App\Enums\ApiOperationSyncFrequency;
use App\Services\Sync\SyncScheduleCalculator;
use Carbon\CarbonImmutable;

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

test('manual synchronization has no automatic next run', function () {
    $now = CarbonImmutable::parse('2026-08-24 12:00:00', 'Asia/Tbilisi');
    CarbonImmutable::setTestNow($now);

    expect(app(SyncScheduleCalculator::class)->nextFromNow(ApiOperationSyncFrequency::Manual))->toBeNull();
});

test('recurring frequencies calculate the next future run', function (ApiOperationSyncFrequency $frequency, string $expected) {
    $now = CarbonImmutable::parse('2026-08-24 12:00:00', 'Asia/Tbilisi');
    CarbonImmutable::setTestNow($now);

    expect(app(SyncScheduleCalculator::class)->nextFromNow($frequency)?->toDateTimeString())->toBe($expected);
})->with([
    [ApiOperationSyncFrequency::Every15Minutes, '2026-08-24 08:15:00'],
    [ApiOperationSyncFrequency::Hourly, '2026-08-24 09:00:00'],
    [ApiOperationSyncFrequency::Every6Hours, '2026-08-24 14:00:00'],
    [ApiOperationSyncFrequency::Every12Hours, '2026-08-24 20:00:00'],
    [ApiOperationSyncFrequency::Daily, '2026-08-25 08:00:00'],
]);

test('overdue calculations skip missed historical intervals', function () {
    $now = CarbonImmutable::parse('2026-08-24 12:30:00', 'Asia/Tbilisi');
    CarbonImmutable::setTestNow($now);

    $next = app(SyncScheduleCalculator::class)->nextRunAt(
        ApiOperationSyncFrequency::Hourly,
        CarbonImmutable::parse('2026-08-24 10:00:00', 'Asia/Tbilisi'),
    );

    expect($next?->toDateTimeString())->toBe('2026-08-24 13:00:00');
});

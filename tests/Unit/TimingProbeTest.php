<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\Support\TimingProbe;

it('parses explicit truthy timing env values', function () {
    expect(TimingProbe::isEnabled('1'))->toBeTrue()
        ->and(TimingProbe::isEnabled('true'))->toBeTrue()
        ->and(TimingProbe::isEnabled('on'))->toBeTrue();
});

it('parses explicit false timing env values', function () {
    expect(TimingProbe::isEnabled('0'))->toBeFalse()
        ->and(TimingProbe::isEnabled('false'))->toBeFalse()
        ->and(TimingProbe::isEnabled('off'))->toBeFalse();
});

it('returns elapsed milliseconds since start time', function () {
    $start = microtime(true) - 0.025;

    expect(TimingProbe::elapsedMs($start))->toBeGreaterThanOrEqual(20);
});

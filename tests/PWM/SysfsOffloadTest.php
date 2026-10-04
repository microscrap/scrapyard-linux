<?php

use GeneralPurposeIO\Contracts\PWM\PWMException;
use Microscrap\ScrapyardLinux\PWM\PosixPWMConnectionDriver;
use Voyager\Contracts\IOPools\Loop;
use Microscrap\ScrapyardLinux\Tests\Fixtures\InlinePool;
use Voyager\Contracts\IOPools\WorkerPools\WorkerPool;

afterEach(fn () => removePwmTrees());

/** A driver on the tree at $root with chip 0 connected, bound to $loop, offloading in-process. */
function loopedPWM(string $root, Loop $loop, int $ready_timeout_ms = 500): PosixPWMConnectionDriver
{
    $driver = (new PosixPWMConnectionDriver($root))
        ->resolvesLoopWith(fn (): Loop => $loop)
        ->resolvesPoolsWith(fn (?string $pool): WorkerPool => new InlinePool($loop));
    $driver->connectTo(0)->readyTimeout($ready_timeout_ms)->register();

    return $driver;
}

it('offloads to a worker-side driver on the same sysfs tree', function () {
    $root = pwmTree();
    $loop = testLoop();
    $servo = loopedPWM($root, $loop)->device(0, 0);

    expect($servo->via()->setPeriod(20_000_000)->wait())->toBe(20_000_000)
        ->and(file_get_contents("{$root}/pwmchip0/pwm0/period"))->toBe('20000000')
        ->and($servo->via()->getPeriod()->wait())->toBe(20_000_000)
        ->and($servo->getPeriod())->toBe(20_000_000);
});

it('waits for a freshly exported channel on the loop, and other timers keep firing meanwhile', function () {
    $root = pwmTree(channels: []);
    $loop = testLoop();
    $driver = loopedPWM($root, $loop);
    $ticks = 0;
    $ticker = $loop->every(0.005, function () use (&$ticks) { $ticks++; }, 'test-ticker');
    $loop->at(0.05, fn () => pwmChannelAppears($root, 0, 0));

    $started = hrtime(true);
    $servo = $driver->device(0, 0);
    $waited_ms = (hrtime(true) - $started) / 1e6;
    $ticker->cancel();

    expect($waited_ms)->toBeGreaterThanOrEqual(45.0)
        ->and($ticks)->toBeGreaterThanOrEqual(5)
        ->and(file_get_contents("{$root}/pwmchip0/export"))->toBe('0')
        ->and($servo->setPeriod(20_000_000))->toBe(20_000_000);
});

it('suspends only the fiber that opens the channel', function () {
    $root = pwmTree(channels: []);
    $loop = testLoop();
    $driver = loopedPWM($root, $loop);

    $started = hrtime(true);
    $opened = $loop->async(fn () => $driver->device(0, 0));
    $returned_ms = (hrtime(true) - $started) / 1e6;
    $loop->at(0.03, fn () => pwmChannelAppears($root, 0, 0));
    $loop->until(fn (): bool => $opened->settled());

    expect($returned_ms)->toBeLessThan(20.0)
        ->and($opened->wait()->channel())->toBe(0);
});

it('gives up after the ready timeout and leaves no timer behind', function () {
    $root = pwmTree(channels: []);
    $loop = testLoop();
    $driver = loopedPWM($root, $loop, 30);

    expect(fn () => $driver->device(0, 0))
        ->toThrow(PWMException::class, "PWM channel attribute is not writable yet: {$root}/pwmchip0/pwm0/period");

    // nothing is due once the wait is over: a poll timer left behind would keep every later run() turning
    expect($loop->registry->soonestDue())->toBeNull();
});

it('hands one transport to two fibers that open the same channel while it comes up', function () {
    $root = pwmTree(channels: []);
    $loop = testLoop();
    $driver = loopedPWM($root, $loop);

    $first = $loop->async(fn () => $driver->device(0, 0));
    $second = $loop->async(fn () => $driver->device(0, 0));
    $loop->at(0.03, fn () => pwmChannelAppears($root, 0, 0));
    $loop->until(fn (): bool => $first->settled() && $second->settled());

    expect($first->wait())->toBe($second->wait())
        ->and($driver->device(0, 0))->toBe($first->wait());
});

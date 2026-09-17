<?php

use GeneralPurposeIO\Contracts\PWM\PWMException;
use Microscrap\ScrapyardLinux\PWM\PosixPWMConnectionDriver;
use Microscrap\ScrapyardLinux\PWM\PosixPWMTransport;

/** A fake /sys/class/pwm with one chip. Channels appear only when the test pre-exports them. */
function fakeSysfs(array $exported_channels = []): string
{
    $root = sys_get_temp_dir().'/scrapyard-pwm-'.bin2hex(random_bytes(4));
    $chip = "{$root}/pwmchip0";
    mkdir($chip, 0777, true);
    file_put_contents("{$chip}/export", '');
    file_put_contents("{$chip}/unexport", '');
    file_put_contents("{$chip}/npwm", '2');

    foreach ($exported_channels as $channel) {
        $path = "{$chip}/pwm{$channel}";
        mkdir($path);
        file_put_contents("{$path}/period", "0\n");
        file_put_contents("{$path}/duty_cycle", "0\n");
        file_put_contents("{$path}/enable", "0\n");
        file_put_contents("{$path}/polarity", "normal\n");
    }

    return $root;
}

function connectedChannel(string $root, int $channel = 0): PosixPWMTransport
{
    $driver = new PosixPWMConnectionDriver($root);
    $driver->connectTo(0)->readyTimeout(20)->register();

    return $driver->device(0, $channel);
}

it('refuses a chip that is not under the sysfs root', function (): void {
    $driver = new PosixPWMConnectionDriver(fakeSysfs());

    expect(fn () => $driver->connectTo(7))
        ->toThrow(PWMException::class, 'pwmchip7 was not found');
});

it('refuses to connect the same chip twice', function (): void {
    $driver = new PosixPWMConnectionDriver(fakeSysfs());
    $driver->connectTo(0)->register();

    expect(fn () => $driver->connectTo(0))->toThrow(PWMException::class, 'already connected');
});

it('returns null for a chip that was never connected', function (): void {
    $driver = new PosixPWMConnectionDriver(fakeSysfs([0]));

    expect($driver->device(0, 0))->toBeNull();
});

it('hands out an already exported channel without touching export', function (): void {
    $root = fakeSysfs([0]);

    $channel = connectedChannel($root);

    expect($channel)->toBeInstanceOf(PosixPWMTransport::class)
        ->and($channel->channel())->toBe(0)
        ->and($channel->handle())->toBe("{$root}/pwmchip0/pwm0")
        ->and(file_get_contents("{$root}/pwmchip0/export"))->toBe('');
});

it('exports a missing channel and fails when the kernel never publishes it', function (): void {
    $root = fakeSysfs();

    expect(fn () => connectedChannel($root, 1))
        ->toThrow(PWMException::class, 'not writable yet')
        ->and(file_get_contents("{$root}/pwmchip0/export"))->toBe('1');
});

it('caches one transport per chip and channel', function (): void {
    $driver = new PosixPWMConnectionDriver(fakeSysfs([0, 1]));
    $driver->connectTo(0)->register();

    expect($driver->device(0, 0))->toBe($driver->device(0, 0))
        ->and($driver->device(0, 1))->not->toBe($driver->device(0, 0));
});

it('writes period, duty cycle, enable and polarity through sysfs and reads them back', function (): void {
    $root = fakeSysfs([0]);
    $channel = connectedChannel($root);
    $path = "{$root}/pwmchip0/pwm0";

    expect($channel->setPeriod(20_000_000))->toBe(20_000_000)
        ->and($channel->setDutyCycle(1_500_000))->toBe(1_500_000)
        ->and($channel->setEnable(true))->toBeTrue()
        ->and($channel->setPolarity(true))->toBeTrue()
        ->and(file_get_contents("{$path}/period"))->toBe('20000000')
        ->and(file_get_contents("{$path}/duty_cycle"))->toBe('1500000')
        ->and(file_get_contents("{$path}/enable"))->toBe('1')
        ->and(file_get_contents("{$path}/polarity"))->toBe('inversed')
        ->and($channel->setPolarity(false))->toBeFalse()
        ->and(file_get_contents("{$path}/polarity"))->toBe('normal')
        ->and($channel->setEnable(false))->toBeFalse();
});

it('rejects a polarity the kernel should never report', function (): void {
    $root = fakeSysfs([0]);
    $channel = connectedChannel($root);
    file_put_contents("{$root}/pwmchip0/pwm0/polarity", "sideways\n");

    expect(fn () => $channel->getPolarity())->toThrow(PWMException::class, "Invalid PWM polarity 'sideways'");
});

it('reports an attribute it cannot read', function (): void {
    $root = fakeSysfs([0]);
    $channel = connectedChannel($root);
    unlink("{$root}/pwmchip0/pwm0/duty_cycle");

    expect(fn () => $channel->getDutyCycle())->toThrow(PWMException::class, 'Could not read');
});

it('disables the output and unexports the channel on close', function (): void {
    $root = fakeSysfs([0]);
    $channel = connectedChannel($root);
    $channel->setEnable(true);

    $channel->close();

    expect(file_get_contents("{$root}/pwmchip0/pwm0/enable"))->toBe('0')
        ->and(file_get_contents("{$root}/pwmchip0/unexport"))->toBe('0');
});

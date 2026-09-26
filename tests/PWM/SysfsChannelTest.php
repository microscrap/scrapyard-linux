<?php

use GeneralPurposeIO\Contracts\PWM\PWMException;
use Microscrap\ScrapyardLinux\PWM\PosixPWMConnectionDriver;

afterEach(fn () => removePwmTrees());

/** A driver on the tree at $root with chip 0 connected. */
function sysfsPWM(string $root, int $ready_timeout_ms = 500): PosixPWMConnectionDriver
{
    $driver = new PosixPWMConnectionDriver($root);
    $driver->connectTo(0)->readyTimeout($ready_timeout_ms)->register();

    return $driver;
}

it('writes each attribute to its sysfs file and answers what the file reads back', function () {
    $root = pwmTree();
    $servo = sysfsPWM($root)->device(0, 0);

    expect($servo->setPeriod(20_000_000))->toBe(20_000_000)
        ->and($servo->setDutyCycle(1_500_000))->toBe(1_500_000)
        ->and($servo->setEnable(true))->toBeTrue()
        ->and($servo->setPolarity(true))->toBeTrue()
        ->and(file_get_contents("{$root}/pwmchip0/pwm0/period"))->toBe('20000000')
        ->and(file_get_contents("{$root}/pwmchip0/pwm0/duty_cycle"))->toBe('1500000')
        ->and(file_get_contents("{$root}/pwmchip0/pwm0/enable"))->toBe('1')
        ->and(file_get_contents("{$root}/pwmchip0/pwm0/polarity"))->toBe('inversed');
});

it('reads the kernel\'s newline-terminated values', function () {
    $root = pwmTree();
    $channel = "{$root}/pwmchip0/pwm0";
    file_put_contents("{$channel}/period", "20000000\n");
    file_put_contents("{$channel}/duty_cycle", "1000000\n");
    file_put_contents("{$channel}/enable", "1\n");

    $servo = sysfsPWM($root)->device(0, 0);

    expect([$servo->getPeriod(), $servo->getDutyCycle(), $servo->getEnable(), $servo->getPolarity()])
        ->toBe([20_000_000, 1_000_000, true, false]);
});

it('refuses a polarity the kernel never writes', function () {
    $root = pwmTree();
    file_put_contents("{$root}/pwmchip0/pwm0/polarity", "sideways\n");

    expect(fn () => sysfsPWM($root)->device(0, 0)->getPolarity())
        ->toThrow(PWMException::class, "Invalid PWM polarity 'sideways'. Expected 'normal' or 'inversed'.");
});

it('says which file a refused write was for', function () {
    $root = pwmTree();
    $servo = sysfsPWM($root)->device(0, 0);
    chmod("{$root}/pwmchip0/pwm0/period", 0444);

    expect(fn () => $servo->setPeriod(20_000_000))
        ->toThrow(PWMException::class, "Could not write PWM sysfs attribute: {$root}/pwmchip0/pwm0/period");
});

it('says which file a refused read was for', function () {
    $root = pwmTree();
    $servo = sysfsPWM($root)->device(0, 0);
    unlink("{$root}/pwmchip0/pwm0/duty_cycle");

    expect(fn () => $servo->getDutyCycle())
        ->toThrow(PWMException::class, "Could not read PWM sysfs attribute: {$root}/pwmchip0/pwm0/duty_cycle");
});

it('refuses a chip that is not there', function () {
    $root = pwmTree();

    expect(fn () => (new PosixPWMConnectionDriver($root))->connectTo(3))
        ->toThrow(PWMException::class, "PWM chip pwmchip3 was not found under {$root}.");
});

it('exports a channel that is not there yet by writing its number to export, then waits for it', function () {
    $root = pwmTree(channels: []);
    $driver = sysfsPWM($root, 30);

    expect(fn () => $driver->device(0, 2))
        ->toThrow(PWMException::class, "PWM channel attribute is not writable yet: {$root}/pwmchip0/pwm2/period")
        ->and(file_get_contents("{$root}/pwmchip0/export"))->toBe('2');
});

it('leaves export alone for a channel that is already there', function () {
    $root = pwmTree();

    sysfsPWM($root)->device(0, 0);

    expect(file_get_contents("{$root}/pwmchip0/export"))->toBe('');
});

it('says so when the chip refuses the export', function () {
    $root = pwmTree(channels: []);
    chmod("{$root}/pwmchip0/export", 0444);

    expect(fn () => sysfsPWM($root)->device(0, 1))
        ->toThrow(PWMException::class, 'Could not export PWM channel 1 on pwmchip0.');
});

it('close() disables the channel and hands it back to the kernel', function () {
    $root = pwmTree();
    $servo = sysfsPWM($root)->device(0, 0);
    $servo->setPeriod(20_000_000);
    $servo->setEnable(true);

    $servo->close();

    expect(file_get_contents("{$root}/pwmchip0/pwm0/enable"))->toBe('0')
        ->and(file_get_contents("{$root}/pwmchip0/unexport"))->toBe('0')
        ->and($servo->closed())->toBeTrue()
        ->and(fn () => $servo->getPeriod())->toThrow(PWMException::class, 'PWM channel 0 is closed.');
});

it('disconnect() hands every channel back and forgets the chip', function () {
    $root = pwmTree(channels: [0, 1]);
    $driver = sysfsPWM($root);
    $servo = $driver->device(0, 0);
    $fan = $driver->device(0, 1);

    $driver->disconnect(0);

    expect($servo->closed())->toBeTrue()
        ->and($fan->closed())->toBeTrue()
        ->and(file_get_contents("{$root}/pwmchip0/unexport"))->toBe('1')
        ->and($driver->connections->has(0))->toBeFalse()
        ->and(is_dir("{$root}/pwmchip0"))->toBeTrue();
});

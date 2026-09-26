<?php

use Microscrap\Bindings\POSIX\Enums\FileControlFlag;
use Microscrap\ScrapyardLinux\UART\PosixUARTConnectionDriver;
use Microscrap\ScrapyardLinux\UART\PosixUARTTransport;
use Posi\System;
use Voyager\Contracts\IOPools\Loop;

pest()->in('I2C', 'SPI', 'PWM', 'UART');

/** Every tree pwmTree() made; removePwmTrees() deletes them after each PWM test. */
$GLOBALS['pwm_trees'] = [];

/** A sysfs PWM tree in a temp dir: pwmchip$chip with export, unexport and npwm, and $channels already exported. */
function pwmTree(int $chip = 0, array $channels = [0]): string
{
    $root = sys_get_temp_dir().'/scrapyard-pwm-'.bin2hex(random_bytes(4));
    mkdir("{$root}/pwmchip{$chip}", 0775, true);

    foreach (['export' => '', 'unexport' => '', 'npwm' => "4\n"] as $file => $contents) {
        file_put_contents("{$root}/pwmchip{$chip}/{$file}", $contents);
    }

    foreach ($channels as $channel) {
        pwmChannelAppears($root, $chip, $channel);
    }

    $GLOBALS['pwm_trees'][] = $root;

    return $root;
}

/** What the kernel makes on export: the channel directory with its attributes, in the kernel's own format. */
function pwmChannelAppears(string $root, int $chip, int $channel): void
{
    $path = "{$root}/pwmchip{$chip}/pwm{$channel}";
    mkdir($path, 0775);

    foreach (['period' => "0\n", 'duty_cycle' => "0\n", 'enable' => "0\n", 'polarity' => "normal\n"] as $attribute => $value) {
        file_put_contents("{$path}/{$attribute}", $value);
    }
}

function removePwmTrees(): void
{
    foreach ($GLOBALS['pwm_trees'] as $root) {
        exec('rm -rf '.escapeshellarg($root));
    }

    $GLOBALS['pwm_trees'] = [];
}

/** Every pty master ptyPair() opened; closePtys() closes them after each UART test. */
$GLOBALS['ptys'] = [];

/**
 * A pseudo-terminal standing in for a serial device: the slave path opens as a port, the master fd plays the device.
 * @return array{int, string} [master fd, slave path]
 */
function ptyPair(): array
{
    $master = posix_open('/dev/ptmx', FileControlFlag::O_RDWR->value | FileControlFlag::O_NOCTTY->value | FileControlFlag::O_CLOEXEC->value);
    expect($master)->toBeGreaterThanOrEqual(0);

    System::ioctl($master, 0x40045431, ['value' => 0]);                   // TIOCSPTLCK: unlock the slave
    $number = System::ioctl($master, 0x80045430, ['value' => 0])['val'];   // TIOCGPTN: which /dev/pts/N it is
    $GLOBALS['ptys'][] = $master;

    return [$master, "/dev/pts/{$number}"];
}

function closePtys(): void
{
    foreach ($GLOBALS['ptys'] as $master) {
        posix_close($master);
    }

    $GLOBALS['ptys'] = [];
}

/** The pty slave at $path open as a port on a native driver, 8N1 at $baud, bound to $loop when given. */
function ptyPort(string $path, ?Loop $loop = null, int $baud = 115_200): PosixUARTTransport
{
    $driver = new PosixUARTConnectionDriver;

    if (! is_null($loop)) {
        $driver->resolvesLoopWith(fn (): Loop => $loop);
    }

    $driver->connectTo($path)->baud($baud)->register();

    return $driver->device($path);
}

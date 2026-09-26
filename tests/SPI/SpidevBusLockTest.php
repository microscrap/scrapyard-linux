<?php

use GeneralPurposeIO\Contracts\SPI\SPIException;
use Microscrap\Bindings\SPI\DataObjects\SPIDevice;
use Microscrap\ScrapyardLinux\SPI\PosixSPITransport;
use Microscrap\ScrapyardLinux\SPI\SpidevBusLock;

/** A slave on made-up bus $bus; no fd, nothing is sent. */
function lockSlave(int $bus, int $chip_select): PosixSPITransport
{
    return new PosixSPITransport($chip_select, new SPIDevice(-1, "/dev/spidev{$bus}.{$chip_select}", 0, 1_000_000, 8), $bus);
}

/**
 * Another PHP process holding bus $bus's lock for $seconds; returns once it has it.
 * @return array{resource, array<int, resource>}
 */
function lockHeldElsewhere(int $bus, float $seconds): array
{
    $code = sprintf(
        'require %s; $slave = new %s(1, new %s(-1, "/dev/null", 0, 1000000, 8), %d); %s::for(%d)->around($slave, function () { echo "held\n"; usleep(%d); });',
        var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true),
        PosixSPITransport::class,
        SPIDevice::class,
        $bus,
        SpidevBusLock::class,
        $bus,
        (int) ($seconds * 1_000_000),
    );
    $child = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w']], $pipes);
    fgets($pipes[1]);                   // "held": the child has the lock now

    return [$child, $pipes];
}

afterEach(function () {
    foreach ([90, 91, 93, 94] as $bus) {
        $path = SpidevBusLock::path($bus);
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it('keeps the lock where every process on this machine finds it', function () {
    expect(SpidevBusLock::path(0))->toBe('/run/lock/scrapyard-spi0.lock');
});

it('makes a call wait while another process holds the bus', function () {
    [$child, $pipes] = lockHeldElsewhere(90, 0.3);
    $started = hrtime(true);

    SpidevBusLock::for(90)->around(lockSlave(90, 0), fn () => null);
    $waited = (hrtime(true) - $started) / 1e9;

    fclose($pipes[1]);
    proc_close($child);

    expect($waited)->toBeGreaterThan(0.2);
});

it('lets the holding slave nest, and lets go only when the outermost call ends', function () {
    $flash = lockSlave(91, 0);

    $inner = SpidevBusLock::for(91)->around($flash, fn () => SpidevBusLock::for(91)->around($flash, fn (): string => 'inner'));

    expect($inner)->toBe('inner')
        ->and(SpidevBusLock::for(91)->around(lockSlave(91, 1), fn (): string => 'free'))->toBe('free');
});

it('refuses another slave of this process while one holds the bus', function () {
    $lock = SpidevBusLock::for(91);

    expect(fn () => $lock->around(lockSlave(91, 0), fn () => $lock->around(lockSlave(91, 1), fn () => null)))
        ->toThrow(SPIException::class, 'SPI device 91 is held by chip select 0');
});

it('lets go when the call throws', function () {
    $lock = SpidevBusLock::for(93);

    expect(fn () => $lock->around(lockSlave(93, 0), fn () => throw new RuntimeException('boom')))->toThrow(RuntimeException::class, 'boom')
        ->and($lock->around(lockSlave(93, 1), fn (): string => 'free'))->toBe('free');
});

it('makes a bus open wait while another process holds the lock', function () {
    [$child, $pipes] = lockHeldElsewhere(94, 0.3);
    $started = hrtime(true);

    SpidevBusLock::for(94)->during(fn () => null);
    $waited = (hrtime(true) - $started) / 1e9;

    fclose($pipes[1]);
    proc_close($child);

    expect($waited)->toBeGreaterThan(0.2);
});

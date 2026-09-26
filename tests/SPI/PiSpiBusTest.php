<?php

use GeneralPurposeIO\Contracts\SPI\SPIEndianness;
use GeneralPurposeIO\Contracts\SPI\SPIException;
use Microscrap\ScrapyardLinux\SPI\PosixSPIConnectionDriver;
use PHPUnit\Framework\SkippedWithMessageException;

/** A Pi 5 with spi0 (CE0, CE1) and spi10 enabled. Nothing on CE1: these tests clock bytes out on it. */
function piSpiBench(): void
{
    if (! extension_loaded('posi') || ! file_exists('/dev/spidev0.1') || ! file_exists('/dev/spidev10.0')) {
        throw new SkippedWithMessageException('No Pi SPI bench: needs ext-posi, /dev/spidev0.0, /dev/spidev0.1 and /dev/spidev10.0, with nothing on CE1.');
    }
}

function piSPI(): PosixSPIConnectionDriver
{
    piSpiBench();

    $driver = new PosixSPIConnectionDriver;
    $driver->connectTo(0)->register();

    return $driver;
}

it('opens its own fd per bus and chip select, so bus 0 and bus 10 never share one', function () {
    $driver = piSPI();
    $driver->connectTo(10)->register();

    try {
        expect($driver->device(0, 0)->handle()->path)->toBe('/dev/spidev0.0')
            ->and($driver->device(10, 0)->handle()->path)->toBe('/dev/spidev10.0')
            ->and($driver->device(0, 0)->handle()->fd)->not->toBe($driver->device(10, 0)->handle()->fd);
    } finally {
        $driver->disconnect(0);
        $driver->disconnect(10);
    }
});

it('names a missing chip select on a two-digit bus by its real path', function () {
    $driver = piSPI();
    $driver->connectTo(10)->register();

    try {
        expect(fn () => $driver->device(10, 1))->toThrow(SPIException::class, '/dev/spidev10.1 could not be opened.');
    } finally {
        $driver->disconnect(0);
        $driver->disconnect(10);
    }
});

it('closes one chip select without touching the other, and disconnect() closes the rest and lets the bus reopen', function () {
    $driver = piSPI();
    $ce0 = $driver->device(0, 0);
    $ce1 = $driver->device(0, 1);

    $ce0->close();

    expect(spi_get_mode($ce1->handle()))->not->toBe(-1)
        ->and(fn () => $ce0->write([0x00]))->toThrow(SPIException::class, 'SPI chip select 0 is closed.');

    $driver->disconnect(0);

    expect($ce1->closed())->toBeTrue()
        ->and($driver->connections->has(0))->toBeFalse();

    $driver->connectTo(0)->register();

    try {
        expect($driver->device(0, 1)->write([0x00]))->toBe(1);
    } finally {
        $driver->disconnect(0);
    }
});

it('writes more than the kernel takes in one message', function () {
    $driver = piSPI();

    try {
        expect($driver->device(0, 1)->write(str_repeat("\x00", 10_000)))->toBe(10_000);
    } finally {
        $driver->disconnect(0);
    }
});

it('does not leak an spidev fd into child processes', function () {
    $driver = piSPI();
    $driver->device(0, 0);
    $driver->device(0, 1);

    try {
        expect((string) shell_exec('ls -l /proc/self/fd 2>/dev/null'))->not->toContain('/dev/spidev');
    } finally {
        $driver->disconnect(0);
    }
});

it('refuses LSB first with 16-bit words on a controller that cannot send it', function () {
    piSpiBench();

    $driver = new PosixSPIConnectionDriver;
    $driver->connectTo(0)->bitsPerByte(16)->endianness(SPIEndianness::LSB)->register();

    try {
        expect(fn () => $driver->device(0, 1))->toThrow(SPIException::class, 'cannot send 16-bit words LSB first');
    } finally {
        $driver->disconnect(0);
    }
});

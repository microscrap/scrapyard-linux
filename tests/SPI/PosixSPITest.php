<?php

use GeneralPurposeIO\Contracts\SPI\SPIEndianness;
use GeneralPurposeIO\Contracts\SPI\SPIException;
use GeneralPurposeIO\Contracts\SPI\SPIMode;
use Microscrap\ScrapyardLinux\SPI\PosixSPIConnectionDriver;
use Microscrap\ScrapyardLinux\SPI\PosixSPIConnectionFactory;

it('refuses a spidev master that does not exist, as int or numeric string', function (): void {
    $driver = new PosixSPIConnectionDriver;

    expect(fn () => $driver->connectTo(9))->toThrow(SPIException::class, 'Device 9 does not exist')
        ->and(fn () => $driver->connectTo('9'))->toThrow(SPIException::class, 'Device 9 does not exist');
});

it('refuses a chip select that has no device node', function (): void {
    $factory = new PosixSPIConnectionFactory(9, new PosixSPIConnectionDriver);

    expect(fn () => $factory->chipSelect(1))->toThrow(SPIException::class, '/dev/spidev9.1 could not be opened');
});

it('starts at mode 0, 800 kHz, MSB first, 8 bits per word, chip select 0', function (): void {
    $factory = new PosixSPIConnectionFactory(0, new PosixSPIConnectionDriver);

    expect($factory->spi_mode)->toBe(SPIMode::MODE_0)
        ->and($factory->speed)->toBe(800_000)
        ->and($factory->endianness)->toBe(SPIEndianness::MSB)
        ->and($factory->bits_per_word)->toBe(8)
        ->and($factory->chip_select)->toBe(0);
});

it('carries mode, speed, endianness and word size as fluent state', function (): void {
    $factory = new PosixSPIConnectionFactory(0, new PosixSPIConnectionDriver);

    $same = $factory->mode(3)->speed(4_000_000)->endianness(SPIEndianness::LSB)->bitsPerByte(16);

    expect($same)->toBe($factory)
        ->and($factory->spi_mode)->toBe(SPIMode::MODE_3)
        ->and($factory->speed)->toBe(4_000_000)
        ->and($factory->endianness)->toBe(SPIEndianness::LSB)
        ->and($factory->bits_per_word)->toBe(16)
        ->and($factory->mode(SPIMode::MODE_1)->spi_mode)->toBe(SPIMode::MODE_1);
});

it('returns null for a device on a master that was never connected', function (): void {
    expect((new PosixSPIConnectionDriver)->device(0, 0))->toBeNull();
});

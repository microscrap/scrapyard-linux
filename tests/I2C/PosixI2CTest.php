<?php

use GeneralPurposeIO\Contracts\I2C\I2CException;
use Microscrap\ScrapyardLinux\I2C\PosixI2CConnectionDriver;
use Microscrap\ScrapyardLinux\I2C\PosixI2CTransport;

it('refuses an i2c bus that does not exist, as int or numeric string', function (): void {
    $driver = new PosixI2CConnectionDriver;

    expect(fn () => $driver->connectTo(9))->toThrow(I2CException::class, 'Device 9 does not exist')
        ->and(fn () => $driver->connectTo('9'))->toThrow(I2CException::class, 'Device 9 does not exist');
});

it('returns null for a bus that was never connected', function (): void {
    expect((new PosixI2CConnectionDriver)->device(1, 0x3C))->toBeNull();
});

it('hands out a transport bound to the slave address and the registered descriptor', function (): void {
    $driver = new PosixI2CConnectionDriver;
    $driver->register(1, 5);

    $slave = $driver->device(1, 0x3C);

    expect($slave)->toBeInstanceOf(PosixI2CTransport::class)
        ->and($slave->address())->toBe(0x3C)
        ->and($slave->handle())->toBe(5)
        ->and($slave->fd)->toBe(5);
});

it('rejects a slave address outside the 7-bit range', function (): void {
    $driver = new PosixI2CConnectionDriver;
    $driver->register(1, 5);

    expect(fn () => $driver->device(1, 0x02))->toThrow(I2CException::class, 'Only valid address')
        ->and(fn () => $driver->device(1, 0x78))->toThrow(I2CException::class, 'Only valid address');
});

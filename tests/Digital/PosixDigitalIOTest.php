<?php

use GeneralPurposeIO\Contracts\Digital\DigitalIOException;
use Microscrap\ScrapyardLinux\Digital\PosixDigitalIOConnectionDriver;
use Microscrap\ScrapyardLinux\Digital\PosixDigitalIOConnectionFactory;

it('refuses a gpiochip that does not exist, as int or numeric string', function (): void {
    $driver = new PosixDigitalIOConnectionDriver;

    expect(fn () => $driver->connectTo(9))->toThrow(DigitalIOException::class, 'Device 9 does not exist')
        ->and(fn () => $driver->connectTo('9'))->toThrow(DigitalIOException::class, 'Device 9 does not exist');
});

it('returns null for pins on a chip that was never connected', function (): void {
    $driver = new PosixDigitalIOConnectionDriver;

    expect($driver->output(0, 17))->toBeNull()
        ->and($driver->input(0, 17))->toBeNull();
});

it('lets the consumer label be set before the chip is opened', function (): void {
    $factory = new PosixDigitalIOConnectionFactory(0, new PosixDigitalIOConnectionDriver);

    expect($factory->consumer)->toBe('scrapyard-io-digital-io')
        ->and($factory->consumer('front-panel'))->toBe($factory)
        ->and($factory->consumer)->toBe('front-panel');
});

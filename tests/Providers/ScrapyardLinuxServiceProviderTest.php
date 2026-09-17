<?php

use GeneralPurposeIO\Digital\DigitalIOServiceProvider;
use GeneralPurposeIO\Digital\DigitalOConnectionManager;
use GeneralPurposeIO\Digital\NoneDigitalIOConnectionDriver;
use GeneralPurposeIO\I2C\I2CConnectionManager;
use GeneralPurposeIO\I2C\I2CServiceProvider;
use GeneralPurposeIO\I2C\NoneI2CConnectionDriver;
use GeneralPurposeIO\PWM\PWMConnectionManager;
use GeneralPurposeIO\PWM\PWMServiceProvider;
use GeneralPurposeIO\SPI\SPIConnectionManager;
use GeneralPurposeIO\SPI\SPIServiceProvider;
use GeneralPurposeIO\UART\UARTConnectionManager;
use GeneralPurposeIO\UART\UARTServiceProvider;
use Microscrap\ScrapyardLinux\Digital\PosixDigitalIOConnectionDriver;
use Microscrap\ScrapyardLinux\I2C\PosixI2CConnectionDriver;
use Microscrap\ScrapyardLinux\Providers\ScrapyardLinuxServiceProvider;
use Microscrap\ScrapyardLinux\PWM\PosixPWMConnectionDriver;
use Microscrap\ScrapyardLinux\SPI\PosixSPIConnectionDriver;
use Microscrap\ScrapyardLinux\UART\PosixUARTConnectionDriver;
use Voyager\Config\Repository;
use Voyager\Vessel\Vessel;

/*
| The framework's protocol providers bind the managers; this provider only
| extends them with `native`. So the container here is the framework's own
| Vessel with those providers registered, nothing hand-bound.
*/
function frameworkVessel(array $gpio = []): Vessel
{
    $vessel = new Vessel;
    Vessel::setInstance($vessel);
    $vessel->instance('config', new Repository(['gpio' => $gpio]));

    foreach ([I2CServiceProvider::class, SPIServiceProvider::class, UARTServiceProvider::class, PWMServiceProvider::class, DigitalIOServiceProvider::class] as $provider) {
        (new $provider($vessel))->register();
    }

    return $vessel;
}

it('extends every protocol manager with the native posix driver', function (): void {
    $vessel = frameworkVessel();

    (new ScrapyardLinuxServiceProvider($vessel))->boot();

    expect($vessel->make(I2CConnectionManager::class)->driver('native'))->toBeInstanceOf(PosixI2CConnectionDriver::class)
        ->and($vessel->make(SPIConnectionManager::class)->driver('native'))->toBeInstanceOf(PosixSPIConnectionDriver::class)
        ->and($vessel->make(UARTConnectionManager::class)->driver('native'))->toBeInstanceOf(PosixUARTConnectionDriver::class)
        ->and($vessel->make(PWMConnectionManager::class)->driver('native'))->toBeInstanceOf(PosixPWMConnectionDriver::class)
        ->and($vessel->make(DigitalOConnectionManager::class)->driver('native'))->toBeInstanceOf(PosixDigitalIOConnectionDriver::class);
});

it('leaves none as the default until the app opts into native', function (): void {
    $vessel = frameworkVessel();
    (new ScrapyardLinuxServiceProvider($vessel))->boot();

    expect($vessel->make('gpio.i2c')->driver())->toBeInstanceOf(NoneI2CConnectionDriver::class)
        ->and($vessel->make('gpio.digital')->driver())->toBeInstanceOf(NoneDigitalIOConnectionDriver::class);
});

it('becomes the default when configured', function (): void {
    $vessel = frameworkVessel(['protocols' => ['i2c' => ['default' => 'native'], 'digital-in' => ['default' => 'native']]]);
    (new ScrapyardLinuxServiceProvider($vessel))->boot();

    expect($vessel->make('gpio.i2c')->driver())->toBeInstanceOf(PosixI2CConnectionDriver::class)
        ->and($vessel->make('gpio.digital')->driver())->toBeInstanceOf(PosixDigitalIOConnectionDriver::class);
});

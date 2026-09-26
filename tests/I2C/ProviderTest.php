<?php

use GeneralPurposeIO\Digital\DigitalOConnectionManager;
use GeneralPurposeIO\I2C\I2CConnectionManager;
use GeneralPurposeIO\PWM\PWMConnectionManager;
use GeneralPurposeIO\SPI\SPIConnectionManager;
use GeneralPurposeIO\UART\UARTConnectionManager;
use Microscrap\ScrapyardLinux\Digital\PosixDigitalIOConnectionDriver;
use Microscrap\ScrapyardLinux\I2C\PosixI2CConnectionDriver;
use Microscrap\ScrapyardLinux\Providers\ScrapyardLinuxServiceProvider;
use Microscrap\ScrapyardLinux\PWM\PosixPWMConnectionDriver;
use Microscrap\ScrapyardLinux\SPI\PosixSPIConnectionDriver;
use Microscrap\ScrapyardLinux\UART\PosixUARTConnectionDriver;
use Voyager\Config\Repository;
use Voyager\Contracts\Core\FrameworkCore;
use Voyager\Vessel\ControlPanel;

it('boots native I2C, SPI, DigitalIO, PWM and UART drivers', function () {
    $container = new ControlPanel;
    $container->registerInstance('config', new Repository(['gpio' => ['protocols' => [
        'i2c' => ['default' => 'native'],
        'spi' => ['default' => 'native'],
        'digital-in' => ['default' => 'native'],
        'pwm' => ['default' => 'native'],
        'uart' => ['default' => 'native'],
    ]]]));
    $digital = new DigitalOConnectionManager($container);
    $i2c = new I2CConnectionManager($container);
    $spi = new SPIConnectionManager($container);
    $pwm = new PWMConnectionManager($container);
    $uart = new UARTConnectionManager($container);

    $app = $this->createMock(FrameworkCore::class);
    $app->method('make')->willReturnCallback(fn (string $abstract): object => match ($abstract) {
        DigitalOConnectionManager::class => $digital,
        I2CConnectionManager::class => $i2c,
        SPIConnectionManager::class => $spi,
        PWMConnectionManager::class => $pwm,
        UARTConnectionManager::class => $uart,
    });

    (new ScrapyardLinuxServiceProvider($app))->boot();

    expect($i2c->driver())->toBeInstanceOf(PosixI2CConnectionDriver::class)
        ->and($spi->driver())->toBeInstanceOf(PosixSPIConnectionDriver::class)
        ->and($digital->driver())->toBeInstanceOf(PosixDigitalIOConnectionDriver::class)
        ->and($pwm->driver())->toBeInstanceOf(PosixPWMConnectionDriver::class)
        ->and($uart->driver())->toBeInstanceOf(PosixUARTConnectionDriver::class);
});

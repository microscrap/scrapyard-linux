<?php

namespace Microscrap\ScrapyardLinux\Providers;

use GeneralPurposeIO\Digital\DigitalOConnectionManager;
use GeneralPurposeIO\I2C\I2CConnectionManager;
use GeneralPurposeIO\PWM\PWMConnectionManager;
use GeneralPurposeIO\SPI\SPIConnectionManager;
use GeneralPurposeIO\UART\UARTConnectionDriver;
use GeneralPurposeIO\UART\UARTConnectionManager;
use Microscrap\ScrapyardLinux\Digital\PosixDigitalIOConnectionDriver;
use Microscrap\ScrapyardLinux\I2C\PosixI2CConnectionDriver;
use Microscrap\ScrapyardLinux\PWM\PosixPWMConnectionDriver;
use Microscrap\ScrapyardLinux\SPI\PosixSPIConnectionDriver;
use Microscrap\ScrapyardLinux\UART\PosixUARTConnectionDriver;
use Voyager\NutsAndBolts\ServiceProvider;

class ScrapyardLinuxServiceProvider extends ServiceProvider
{
    public function register(): void
    {

    }

    public function boot(): void
    {
        $this->bootI2C();
        $this->bootSPI();
        $this->bootUART();
        $this->bootDigitalIO();
        $this->bootPWM();
    }


    private function bootPWM(): void
    {
        /** @var PWMConnectionManager $manager */
        $manager = $this->app->make(PWMConnectionManager::class);
        $manager->extend('native', fn() => new PosixPWMConnectionDriver);
    }

    private function bootDigitalIO(): void
    {
        /** @var DigitalOConnectionManager $manager */
        $manager = $this->app->make(DigitalOConnectionManager::class);
        $manager->extend('native', fn() => new PosixDigitalIOConnectionDriver);
    }

    private function bootUART(): void
    {
        /** @var UARTConnectionManager $manager */
        $manager = $this->app->make(UARTConnectionManager::class);
        $manager->extend('native', fn() => new PosixUARTConnectionDriver);
    }

    private function bootSPI(): void
    {
        /** @var SPIConnectionManager $manager */
        $manager = $this->app->make(SPIConnectionManager::class);
        $manager->extend('native', fn() => new PosixSPIConnectionDriver);
    }

    private function bootI2C(): void
    {
        /** @var I2CConnectionManager $manager */
        $manager = $this->app->make(I2CConnectionManager::class);
        $manager->extend('native', fn() => new PosixI2CConnectionDriver);
    }
}
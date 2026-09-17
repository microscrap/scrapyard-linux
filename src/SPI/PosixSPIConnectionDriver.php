<?php

namespace Microscrap\ScrapyardLinux\SPI;

use GeneralPurposeIO\Contracts\SPI\SPIException;
use GeneralPurposeIO\Contracts\SPI\SPITransport;
use GeneralPurposeIO\SPI\SPIConnectionDriver;
use GeneralPurposeIO\SPI\SPIConnectionFactory;

class PosixSPIConnectionDriver extends SPIConnectionDriver
{
    protected array $chips = [];

    protected function newConnection(int|string $device): PosixSPIConnectionFactory
    {
        if(is_string($device)) {
            $device = intVal($device);
        }

        if (empty(glob("/dev/spidev{$device}.*"))) {
            throw new SPIException("Device {$device} does not exist");
        }

        return new PosixSPIConnectionFactory($device, $this);
    }

    protected function getTransport(int|string $device, int $chip_select): SPITransport
    {
        if(isset($this->chips[$chip_select])) {
            $handle = $this->chips[$chip_select];
        }
        else
        {
            /** @var PosixSPIConnectionFactory $factory */
            $factory = $this->connections->get($device)->chipSelect($chip_select);
            $this->chips[$chip_select] = $handle = $factory->getHandle();
        }


        return new PosixSPITransport($chip_select, $handle);
    }
}
<?php

namespace Microscrap\ScrapyardLinux\I2C;

use GeneralPurposeIO\Contracts\I2C\I2CException;
use GeneralPurposeIO\I2C\I2CConnectionDriver;
use GeneralPurposeIO\I2C\I2CTransport;

class PosixI2CConnectionDriver extends I2CConnectionDriver
{
    protected function newConnection(int|string $device): PosixI2CConnectionFactory
    {
        if(is_string($device)) {
            $device = intVal($device);
        }

        if(!file_exists("/dev/i2c-{$device}"))
        {
            throw new I2CException("Device {$device} does not exist");
        }

        return new PosixI2CConnectionFactory($device, $this);
    }

    protected function getTransport(int|string $device, int $slave_address): I2CTransport
    {
        /** @var int $fd */
        $fd = $this->connections->get($device);

        return new PosixI2CTransport($slave_address, $fd);
    }
}
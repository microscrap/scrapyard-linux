<?php

namespace Microscrap\ScrapyardLinux\I2C;

use GeneralPurposeIO\Contracts\I2C\I2CException;
use GeneralPurposeIO\I2C\I2CConnectionFactory;

class PosixI2CConnectionFactory extends I2CConnectionFactory
{
    public function __construct(
        int $device,
        PosixI2CConnectionDriver $driver
    ) {
        parent::__construct($device, $driver);
    }

    protected function device(): int
    {
        return $this->device;
    }

    protected function getHandle(): int
    {
        $path = "/dev/i2c-{$this->device}";
        // close-on-exec: pool workers and other children open their own bus, never inherit this one
        $fd = posix_open($path, O_RDWR | O_CLOEXEC);

        if($fd < 0)
        {
            throw new I2CException("POSIX I2C handle for [i2c-{$this->device}] could not be opened.");
        }

        return $fd;
    }
}
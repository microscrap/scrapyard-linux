<?php

namespace Microscrap\ScrapyardLinux\SPI;

use GeneralPurposeIO\Contracts\SPI\SPIException;
use GeneralPurposeIO\SPI\SPIConnectionDriver;

class PosixSPIConnectionDriver extends SPIConnectionDriver
{
    protected function newConnection(int|string $device): PosixSPIConnectionFactory
    {
        $device = (int) $device;

        if (empty(glob("/dev/spidev{$device}.*"))) {
            throw new SPIException("SPI device {$device} does not exist");
        }

        return new PosixSPIConnectionFactory($device, $this);
    }

    protected function getTransport(int|string $device, int $chip_select): PosixSPITransport
    {
        /** @var PosixSPIConnectionFactory $bus */
        $bus = $this->connections->get($device);

        [$handle, $reversed] = $bus->chipSelect($chip_select)->open();

        return new PosixSPITransport($chip_select, $handle, (int) $device, $bus->maxMessage(), $reversed);
    }

    /** The bus holds no fd of its own: each chip select closed its fd with its slave. */
    protected function closeConnection(mixed $handle): void {}
}

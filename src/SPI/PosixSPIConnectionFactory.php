<?php

namespace Microscrap\ScrapyardLinux\SPI;

use GeneralPurposeIO\Contracts\SPI\SPIException;
use GeneralPurposeIO\SPI\SPIConnectionDriver;
use GeneralPurposeIO\SPI\SPIConnectionFactory;
use Microscrap\Bindings\SPI\DataObjects\SPIDevice;

class PosixSPIConnectionFactory extends SPIConnectionFactory
{
    public int $bits_per_word = 8;

    public function __construct(
        int $device,
        PosixSPIConnectionDriver $driver
    ) {
        parent::__construct($device, $driver);
    }


    public function chipSelect(int $chip_select): static
    {
        $partial_path = "/dev/spidev{$this->device}.";
        $path = "{$partial_path}{$chip_select}";
        if(!file_exists($path))
        {
            $master = substr($partial_path, -2, 1);
            throw SPIException::couldNotOpenSPIDevice($master, $chip_select);
        }

        $this->chip_select = $chip_select;
        return $this;
    }

    public function bitsPerByte(int $value): static
    {
        $this->bits_per_word = $value;

        return $this;
    }

    protected function device(): int
    {
        return $this->device;
    }

    public function getHandle(): SPIDevice
    {
        $partial_path = "/dev/spidev{$this->device}.";
        $path = "{$partial_path}{$this->chip_select}";

        $posix_spi_device = spi_open($path, $this->spi_mode->value, $this->speed, $this->bits_per_word);

        if (is_null($posix_spi_device)) {
            $master = substr($partial_path, -2, 1);
            throw SPIException::couldNotOpenSPIDevice($master, $this->chip_select);
        }

        return $posix_spi_device;
    }

    public function register(): SPIConnectionDriver
    {
        return $this->driver->register($this->device, $this);
    }
}
<?php

namespace Microscrap\ScrapyardLinux\SPI;

use GeneralPurposeIO\SPI\SPITransport;
use Microscrap\Bindings\SPI\DataObjects\SPIDevice;
use Microscrap\Bindings\SPI\DataObjects\SPITransfer;

class PosixSPITransport extends SPITransport
{
    public function __construct(
        int $chip_select,
        protected readonly SPIDevice $handle,
    ) {
        parent::__construct($chip_select);
    }

    public function handle(): SPIDevice
    {
        return $this->handle;
    }

    public function close(): void
    {
        spi_close($this->handle);
    }

    public function read(int $len): array|false
    {
        $rx = spi_read($this->handle, $len);

        if ($rx === false) {
            return false;
        }

        return bytes2array($rx);
    }

    public function write(array|string $data): int
    {
        if (is_array($data)) {
            $data = array2bytes($data);
        }

        return spi_write($this->handle, $data);
    }

    public function transfer(array|string $data): array|false
    {
        if (is_array($data)) {
            $data = array2bytes($data);
        }

        $rx = spi_transfer($this->handle, new SPITransfer(tx: $data, len: strlen($data)));

        if ($rx === false) {
            return false;
        }

        return bytes2array($rx);
    }
}
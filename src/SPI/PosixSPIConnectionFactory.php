<?php

namespace Microscrap\ScrapyardLinux\SPI;

use GeneralPurposeIO\Contracts\SPI\SPIEndianness;
use GeneralPurposeIO\Contracts\SPI\SPIException;
use GeneralPurposeIO\SPI\SPIBusSettings;
use GeneralPurposeIO\SPI\SPIConnectionFactory;
use Microscrap\Bindings\SPI\DataObjects\SPIDevice;

/**
 * One spidev bus. The bus holds no fd of its own: this factory is what the driver registers, and every chip select
 * opens its own /dev/spidev<bus>.<cs> with these settings.
 */
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
        if (! file_exists($this->path($chip_select))) {
            throw SPIException::couldNotOpenSPIDevice($this->device, $chip_select);
        }

        $this->chip_select = $chip_select;

        return $this;
    }

    public function bitsPerByte(int $value): static
    {
        $this->bits_per_word = $value;

        return $this;
    }

    /** The shared settings plus the word size, which only spidev has. */
    public function settings(): SPIBusSettings
    {
        return new SPIBusSettings($this->spi_mode, $this->speed, $this->endianness, $this->bits_per_word);
    }

    public function configure(SPIBusSettings $settings): static
    {
        return parent::configure($settings)->bitsPerByte($settings->bits_per_word);
    }

    protected function device(): int
    {
        return $this->device;
    }

    public function getHandle(): static
    {
        return $this;
    }

    /**
     * Opens the current chip select with the bus settings. LSB first is asked of the kernel; a controller that refuses
     * it (the Pi 5's does) gets 8-bit words bit-reversed in PHP instead, and any other word size throws.
     * @return array{SPIDevice, bool} the device, and whether its bytes are bit-reversed in software
     */
    public function open(): array
    {
        return SpidevBusLock::for((int) $this->device)->during(function (): array {
            $device = spi_open($this->path($this->chip_select), $this->spi_mode->value, $this->speed, $this->bits_per_word);

            if (is_null($device)) {
                throw SPIException::couldNotOpenSPIDevice($this->device, $this->chip_select);
            }

            if ($this->endianness === SPIEndianness::MSB || spi_set_lsb_first($device, true) === 0) {
                return [$device, false];
            }

            if ($this->bits_per_word === 8) {
                return [$device, true];
            }

            spi_close($device);

            throw SPIException::lsbFirstUnsupported($this->device, $this->bits_per_word);
        });
    }

    /** spidev's per-message buffer: the module's bufsiz parameter, 4096 unless the kernel was told otherwise. */
    public function maxMessage(): int
    {
        $path = '/sys/module/spidev/parameters/bufsiz';
        $bufsiz = is_readable($path) ? (int) trim((string) file_get_contents($path)) : 0;

        return $bufsiz > 0 ? $bufsiz : 4096;
    }

    private function path(int $chip_select): string
    {
        return "/dev/spidev{$this->device}.{$chip_select}";
    }
}

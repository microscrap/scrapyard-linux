<?php

namespace Microscrap\ScrapyardLinux\PWM;

use GeneralPurposeIO\Contracts\PWM\PWMException;
use GeneralPurposeIO\PWM\PWMConnectionDriver;
use GeneralPurposeIO\PWM\PWMConnectionFactory;

class PosixPWMConnectionFactory extends PWMConnectionFactory
{
    /** How long a freshly exported channel may take to become writable. */
    public int $ready_timeout_ms = 500;

    public function __construct(
        int $device,
        PosixPWMConnectionDriver $driver
    ) {
        parent::__construct($device, $driver);
    }

    public function readyTimeout(int $milliseconds): static
    {
        $this->ready_timeout_ms = $milliseconds;

        return $this;
    }

    protected function device(): int
    {
        return $this->device;
    }

    public function chipPath(): string
    {
        return $this->driver->chipPath($this->device);
    }

    protected function getHandle(): string
    {
        $path = $this->chipPath();

        if (! is_dir($path)) {
            throw PWMException::chipNotFound($this->device, $this->driver->sysfs_root);
        }

        return $path;
    }

    /** The driver needs the chip path and the ready timeout, so the factory is the handle. */
    public function register(): PWMConnectionDriver
    {
        $this->getHandle();

        return $this->driver->register($this->device, $this);
    }
}

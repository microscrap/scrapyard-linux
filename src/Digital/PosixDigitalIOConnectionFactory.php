<?php

namespace Microscrap\ScrapyardLinux\Digital;

use GeneralPurposeIO\Contracts\Digital\DigitalIOException;
use GeneralPurposeIO\Digital\DigitalInputTransport;
use GeneralPurposeIO\Digital\DigitalIOConnectionFactory;
use Microscrap\Bindings\GPIO\DataObjects\GPIOChip;

class PosixDigitalIOConnectionFactory extends DigitalIOConnectionFactory
{
    public string $consumer = "scrapyard-io-digital-io";

    public function __construct(
        int $device,
        PosixDigitalIOConnectionDriver $driver
    ) {
        parent::__construct($device, $driver);
    }

    public function consumer(string $name): static
    {
        $this->consumer = $name;

        return $this;
    }

    protected function device(): int
    {
        return $this->device;
    }

    protected function getHandle(): array
    {
        $req_config = gpiod_request_config_new();
        gpiod_request_config_set_consumer($req_config, $this->consumer);
        // the kernel keeps 16 edges per one-line request unless asked; match the pin's own queue
        gpiod_request_config_set_event_buffer_size($req_config, DigitalInputTransport::QUEUE_DEPTH);
        $chip = gpiod_chip_open("/dev/gpiochip{$this->device}");

        if(!isset($chip))
        {
            throw new DigitalIOException("POSIX DigitalIO handle for [gpiochip{$this->device}] could not be opened.");
        }

        return [$req_config, $chip];
    }
}
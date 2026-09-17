<?php

namespace Microscrap\ScrapyardLinux\Digital;

use GeneralPurposeIO\Digital\DigitalOutputTransport;
use Microscrap\Bindings\GPIO\DataObjects\GPIOChip;
use Microscrap\Bindings\GPIO\DataObjects\GPIOLineRequest;
use Microscrap\Bindings\GPIO\Enums\LineValue;

class PosixDigitalOutputTransport extends DigitalOutputTransport
{
    public function __construct(
        int $pin,
        public readonly GPIOChip $chip,
        public readonly GPIOLineRequest $handle,
    ) {
        parent::__construct($pin);
    }

    public function read(): bool
    {
        return gpiod_line_request_get_value($this->handle, $this->pin)->value == 1;
    }

    public function write(bool $state): bool
    {
        $value = $state ? LineValue::ACTIVE : LineValue::INACTIVE;

        gpiod_line_request_set_value($this->handle, $this->pin, $value);

        return $this->read();
    }

    public function close(): void
    {
        gpiod_chip_close($this->chip);
    }
}
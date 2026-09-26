<?php

namespace Microscrap\ScrapyardLinux\Digital;

use GeneralPurposeIO\Contracts\Digital\DigitalIOException;
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
        $this->ensureOpen();

        $value = gpiod_line_request_get_value($this->handle, $this->pin);

        if ($value !== LineValue::ACTIVE && $value !== LineValue::INACTIVE) {
            throw DigitalIOException::lineReadFailed($this->chip->path, $this->pin);
        }

        return $value === LineValue::ACTIVE;
    }

    public function write(bool $state): bool
    {
        $this->ensureOpen();

        gpiod_line_request_set_value($this->handle, $this->pin, $state ? LineValue::ACTIVE : LineValue::INACTIVE);

        return $this->read();
    }

    protected function release(): void
    {
        gpiod_line_request_release($this->handle);
    }
}
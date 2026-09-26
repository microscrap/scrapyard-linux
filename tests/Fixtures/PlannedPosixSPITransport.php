<?php

namespace Microscrap\ScrapyardLinux\Tests\Fixtures;

use Microscrap\ScrapyardLinux\SPI\PosixSPITransport;

/** The real transport with its message planning exposed and its clock settable without an fd. Nothing is sent. */
final class PlannedPosixSPITransport extends PosixSPITransport
{
    public function plan(array $segments): array
    {
        return $this->messages($segments);
    }

    public function clockAt(int $hz): static
    {
        $this->hz = $hz;

        return $this;
    }
}

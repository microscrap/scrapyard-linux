<?php

namespace Microscrap\ScrapyardLinux\UART;

use GeneralPurposeIO\Contracts\UART\UARTException;
use GeneralPurposeIO\Contracts\UART\UARTTransport;
use GeneralPurposeIO\UART\UARTConnectionDriver;
use Microscrap\Bindings\UART\DataObjects\UARTPort;

class PosixUARTConnectionDriver extends UARTConnectionDriver
{
    protected function newConnection(string $device): PosixUARTConnectionFactory
    {
        if (! file_exists($device)) {
            throw UARTException::couldNotOpenUARTPort($device);
        }

        return new PosixUARTConnectionFactory($device, $this);
    }

    protected function getTransport(string $device): UARTTransport
    {
        /** @var UARTPort $handle */
        $handle = $this->connections->get($device);

        return new PosixUARTTransport($handle);
    }
}
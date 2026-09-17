<?php

namespace Microscrap\ScrapyardLinux\UART;

use GeneralPurposeIO\UART\UARTTransport;
use Microscrap\Bindings\UART\DataObjects\UARTPort;

class PosixUARTTransport extends UARTTransport
{
    public function __construct(
        protected readonly UARTPort $port,

    ) {}

    public function handle(): UARTPort
    {
        return $this->port;
    }

    public function close(): void
    {
        uart_close($this->port);
    }

    public function flush(): void
    {
        uart_flush($this->port);
    }

    public function path(): string
    {
        return $this->port->path;
    }

    public function read(int $length): array|false
    {
        $data = uart_read($this->port, $length);

        return $data === false ? false : bytes2array($data);
    }

    public function write(array|string $data): int
    {
        return uart_write($this->port, static::normalizeData($data));
    }

    public function pollBytes(int $max_bytes = 4096): string
    {
        if (posix_ppoll($this->port->fd, 0) < 1) {
            return '';
        }

        $data = uart_read($this->port, $max_bytes);

        return $data === false ? '' : $data;
    }


}
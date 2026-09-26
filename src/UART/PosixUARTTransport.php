<?php

namespace Microscrap\ScrapyardLinux\UART;

use GeneralPurposeIO\Contracts\UART\UARTException;
use GeneralPurposeIO\UART\UARTTransport;
use Microscrap\Bindings\POSIX\Enums\PollEvent;
use Microscrap\Bindings\UART\DataObjects\UARTPort;
use Microscrap\Bindings\UART\Enums\ModemLine;

class PosixUARTTransport extends UARTTransport
{
    /**
     * The port's fd as a stream, opened the first time the loop asks. Once open it owns the fd: no dup, so the
     * close-on-exec flag holds, and fclose() is what gives the fd back.
     * @var resource|null
     */
    private $stream = null;

    public function __construct(
        string $device,
        public readonly UARTPort $port,
    ) {
        parent::__construct($device, $port->baud);
    }

    public function handle(): UARTPort
    {
        return $this->port;
    }

    public function path(): string
    {
        return $this->port->path;
    }

    public function dtr(bool $asserted): void
    {
        $this->modemLine(ModemLine::DTR, $asserted);
    }

    public function rts(bool $asserted): void
    {
        $this->modemLine(ModemLine::RTS, $asserted);
    }

    /**
     * VMIN=0: whatever the kernel holds, possibly nothing. A hung-up port (an unplugged ttyUSB) reads 0 bytes while
     * poll calls it ready: a second read tells that apart from bytes that landed after the first one.
     */
    protected function drainBytes(): string
    {
        $bytes = uart_read($this->port, 4096);

        if ($bytes === '' && posix_ppoll($this->port->fd, 0, PollEvent::POLLIN->value) > 0) {
            $bytes = uart_read($this->port, 4096);

            if ($bytes === '') {
                throw UARTException::readFailed($this->device);
            }
        }

        return $bytes === false ? throw UARTException::readFailed($this->device) : $bytes;
    }

    protected function awaitBytes(int $timeout_ms): void
    {
        posix_ppoll($this->port->fd, $timeout_ms < 0 ? -1 : $timeout_ms * 1_000_000, PollEvent::POLLIN->value);
    }

    /** n_tty reports POLLOUT only while fewer than 256 bytes are queued, so a TX_CHUNK always fits then. */
    protected function roomNow(): bool
    {
        return posix_ppoll($this->port->fd, 0, PollEvent::POLLOUT->value) > 0;
    }

    protected function awaitRoom(int $timeout_ms): void
    {
        posix_ppoll($this->port->fd, $timeout_ms < 0 ? -1 : $timeout_ms * 1_000_000, PollEvent::POLLOUT->value);
    }

    protected function transmit(string $bytes): int
    {
        return uart_write($this->port, $bytes);
    }

    protected function purge(): void
    {
        uart_flush($this->port);
    }

    protected function release(): void
    {
        is_null($this->stream) ? uart_close($this->port) : fclose($this->stream);
    }

    protected function intakeStreams(): array
    {
        $this->stream ??= posix_fdopen($this->port->fd, 'r') ?: throw UARTException::intakeStreamFailed($this->device);

        return [$this->stream];
    }

    protected function samplingInterval(): ?float
    {
        return null;
    }

    private function modemLine(ModemLine $line, bool $asserted): void
    {
        $this->ensureOpen();

        $result = $asserted ? uart_set_modem_line($this->port, $line) : uart_clear_modem_line($this->port, $line);

        if ($result < 0) {
            throw UARTException::modemLinesUnsupported($this->device, $line->name);
        }
    }
}

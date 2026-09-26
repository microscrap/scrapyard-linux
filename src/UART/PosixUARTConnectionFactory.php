<?php

namespace Microscrap\ScrapyardLinux\UART;

use GeneralPurposeIO\Contracts\UART\DataBits;
use GeneralPurposeIO\Contracts\UART\FlowControl;
use GeneralPurposeIO\Contracts\UART\Parity;
use GeneralPurposeIO\Contracts\UART\StopBits;
use GeneralPurposeIO\Contracts\UART\UARTException;
use GeneralPurposeIO\UART\UARTConnectionFactory;
use Microscrap\Bindings\UART\DataObjects\UARTPort;
use Microscrap\Bindings\UART\Enums\ControlChar;
use Microscrap\Bindings\UART\Enums\ControlFlag;
use Microscrap\Bindings\UART\Enums\InputFlag;
use Microscrap\Bindings\UART\Enums\TermiosAction;

class PosixUARTConnectionFactory extends UARTConnectionFactory
{
    public function __construct(
        string $device,
        PosixUARTConnectionDriver $driver
    ) {
        parent::__construct($device, $driver);
    }

    protected function device(): string
    {
        return $this->device;
    }

    protected function getHandle(): UARTPort
    {
        $port = uart_open($this->device, $this->baud_rate);

        if (is_null($port)) {
            throw UARTException::couldNotOpenUARTPort($this->device);
        }

        $this->configureLine($port);

        return $port;
    }

    /** $termios with this connection's data bits, parity, stop bits, flow control and never-waiting reads. */
    public function applyLine(array $termios): array
    {
        $termios['c_cflag'] &= ~ControlFlag::CS8->value;
        $termios['c_cflag'] |= match ($this->data_bits) {
            DataBits::FIVE => ControlFlag::CS5->value,
            DataBits::SIX => ControlFlag::CS6->value,
            DataBits::SEVEN => ControlFlag::CS7->value,
            DataBits::EIGHT => ControlFlag::CS8->value,
        };

        if ($this->parity === Parity::NONE) {
            $termios['c_cflag'] &= ~ControlFlag::PARENB->value;
        } else {
            $termios['c_cflag'] |= ControlFlag::PARENB->value;

            if ($this->parity === Parity::ODD) {
                $termios['c_cflag'] |= ControlFlag::PARODD->value;
            } else {
                $termios['c_cflag'] &= ~ControlFlag::PARODD->value;
            }
        }

        if ($this->stop_bits === StopBits::TWO) {
            $termios['c_cflag'] |= ControlFlag::CSTOPB->value;
        } else {
            $termios['c_cflag'] &= ~ControlFlag::CSTOPB->value;
        }

        if ($this->flow_control === FlowControl::HARDWARE) {
            $termios['c_cflag'] |= ControlFlag::CRTSCTS->value;
        } else {
            $termios['c_cflag'] &= ~ControlFlag::CRTSCTS->value;
        }

        $xon_xoff = InputFlag::IXON->value | InputFlag::IXOFF->value;

        if ($this->flow_control === FlowControl::SOFTWARE) {
            $termios['c_iflag'] |= $xon_xoff;
        } else {
            $termios['c_iflag'] &= ~$xon_xoff;
        }

        // VMIN=0 VTIME=0: a read hands back what the kernel holds, possibly nothing, and never waits. Waiting belongs
        // to ppoll() or the loop, both with the caller's timeout, so a silent device cannot hang a read.
        $termios['c_cc'][ControlChar::VMIN->value] = 0;
        $termios['c_cc'][ControlChar::VTIME->value] = 0;

        return $termios;
    }

    private function configureLine(UARTPort $port): void
    {
        $termios = uart_tcgetattr($port);

        if ($termios === false || uart_tcsetattr($port, $this->applyLine($termios), TermiosAction::TCSANOW) !== 0) {
            uart_close($port);

            throw UARTException::couldNotConfigureUARTPort($this->device);
        }
    }
}

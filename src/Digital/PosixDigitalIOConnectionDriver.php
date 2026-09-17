<?php

namespace Microscrap\ScrapyardLinux\Digital;

use GeneralPurposeIO\Contracts\Digital\DigitalIOException;
use GeneralPurposeIO\Contracts\Digital\LineBias;
use GeneralPurposeIO\Digital\DigitalIOConnectionDriver;
use Microscrap\Bindings\GPIO\DataObjects\GPIOChip;
use Microscrap\Bindings\GPIO\DataObjects\GPIOLineRequest;
use Microscrap\Bindings\GPIO\DataObjects\GPIOLineSettings;
use Microscrap\Bindings\GPIO\DataObjects\GPIORequestConfig;
use Microscrap\Bindings\GPIO\Enums\LineBias as GpiodLineBias;
use Microscrap\Bindings\GPIO\Enums\LineDirection;
use Microscrap\Bindings\GPIO\Enums\LineEdge;
use Microscrap\Bindings\POSIX\Enums\FcntlCommand;
use Microscrap\Bindings\POSIX\Enums\FileControlFlag;

class PosixDigitalIOConnectionDriver extends DigitalIOConnectionDriver
{
    protected function newConnection(int|string $device): PosixDigitalIOConnectionFactory
    {
        if(is_string($device)) {
            $device = intVal($device);
        }

        if(!file_exists("/dev/gpiochip{$device}"))
        {
            throw new DigitalIOException("Device {$device} does not exist");
        }

        return new PosixDigitalIOConnectionFactory($device, $this);
    }

    protected function getOutputTransport(string|int $device, int $pin): PosixDigitalOutputTransport
    {
        if(isset($this->pins[$pin]))
        {
            if($this->pins[$pin] instanceOf PosixDigitalOutputTransport)
            {
                return $this->pins[$pin];
            }

            throw new DigitalIOException("Pin {$pin} is not an output");
        }

        /**
         * @var GPIORequestConfig $req_config
         * @var GPIOChip $chip
         */
        [$req_config, $chip] = $this->connections->get($device);

        $settings = gpiod_line_settings_new();
        gpiod_line_settings_set_direction($settings, LineDirection::OUTPUT);

        $handle = $this->requestLine($chip, $req_config, $pin, $settings);

        return $this->pins[$pin] = new PosixDigitalOutputTransport($pin, $chip, $handle);
    }

    protected function getInputTransport(string|int $device, int $pin, LineBias $bias = LineBias::AS_IS, bool $active_low = false): PosixDigitalInputTransport
    {
        if(isset($this->pins[$pin]))
        {
            if($this->pins[$pin] instanceOf PosixDigitalInputTransport)
            {
                return $this->pins[$pin];
            }

            throw new DigitalIOException("Pin {$pin} is not an input");
        }

        /**
         * @var GPIORequestConfig $req_config
         * @var GPIOChip $chip
         */
        [$req_config, $chip] = $this->connections->get($device);

        $settings = gpiod_line_settings_new();
        gpiod_line_settings_set_direction($settings, LineDirection::INPUT);
        gpiod_line_settings_set_bias($settings, GpiodLineBias::from($bias->value));
        gpiod_line_settings_set_active_low($settings, $active_low);
        gpiod_line_settings_set_edge_detection($settings, LineEdge::BOTH);

        $handle = $this->requestLine($chip, $req_config, $pin, $settings);
        $this->unblock($handle);

        return $this->pins[$pin] = new PosixDigitalInputTransport($pin, $chip, $handle);
    }

    protected function requestLine(GPIOChip $chip, GPIORequestConfig $req_config, int $pin, GPIOLineSettings $settings): GPIOLineRequest
    {
        $line_config = gpiod_line_config_new();
        gpiod_line_config_add_line_settings($line_config, [$pin], $settings);

        $handle = gpiod_chip_request_lines($chip, $req_config, $line_config);

        if(is_null($handle))
        {
            throw new DigitalIOException("Could not request line {$pin} on {$chip->path}");
        }

        return $handle;
    }

    protected function unblock(GPIOLineRequest $handle): void
    {
        $flags = 0;
        $ignored = null;

        fcntl($handle->fd, FcntlCommand::F_GETFL->value, 0, $flags);
        fcntl($handle->fd, FcntlCommand::F_SETFL->value, $flags | FileControlFlag::O_NONBLOCK->value, $ignored);
    }
}

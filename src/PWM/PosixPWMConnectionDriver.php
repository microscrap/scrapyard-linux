<?php

namespace Microscrap\ScrapyardLinux\PWM;

use GeneralPurposeIO\Contracts\PWM\PWMException;
use GeneralPurposeIO\PWM\PWMConnectionDriver;

/**
 * Linux sysfs PWM. A connection is one pwmchip; a transport is one exported
 * channel under it. Nothing here needs ext-posi: the kernel exposes PWM as
 * plain attribute files under /sys/class/pwm.
 */
class PosixPWMConnectionDriver extends PWMConnectionDriver
{
    /** @var array<string, PosixPWMTransport> keyed "chip:channel" */
    protected array $channels = [];

    public function __construct(
        public readonly string $sysfs_root = '/sys/class/pwm',
    ) {
        parent::__construct();
    }

    protected function newConnection(int|string $device): PosixPWMConnectionFactory
    {
        if(is_string($device)) {
            $device = intVal($device);
        }

        if(!is_dir($this->chipPath($device)))
        {
            throw PWMException::chipNotFound($device, $this->sysfs_root);
        }

        return new PosixPWMConnectionFactory($device, $this);
    }

    protected function getTransport(int|string $device, int $channel): PosixPWMTransport
    {
        $key = "{$device}:{$channel}";

        if(isset($this->channels[$key])) {
            return $this->channels[$key];
        }

        /** @var PosixPWMConnectionFactory $factory */
        $factory = $this->connections->get($device);
        $chip_path = $factory->chipPath();
        $channel_path = "{$chip_path}/pwm{$channel}";

        if (! is_dir($channel_path)) {
            if (@file_put_contents("{$chip_path}/export", (string) $channel) === false) {
                throw PWMException::couldNotExport($device, $channel);
            }
        }

        // The kernel creates the channel dir before udev chmods its attributes.
        $this->waitUntilWritable("{$channel_path}/period", $factory->ready_timeout_ms);

        return $this->channels[$key] = new PosixPWMTransport($channel, $chip_path);
    }

    public function chipPath(int $chip): string
    {
        return "{$this->sysfs_root}/pwmchip{$chip}";
    }

    protected function waitUntilWritable(string $path, int $timeout_ms): void
    {
        $deadline = hrtime(true) + ($timeout_ms * 1_000_000);

        do {
            if (is_writable($path)) {
                return;
            }

            usleep(10_000);
        } while (hrtime(true) < $deadline);

        throw PWMException::channelNotReady($path);
    }
}

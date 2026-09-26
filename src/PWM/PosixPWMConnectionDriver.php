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
    public function __construct(
        public readonly string $sysfs_root = '/sys/class/pwm',
    ) {
        parent::__construct();
    }

    protected function newConnection(int|string $device): PosixPWMConnectionFactory
    {
        if (is_string($device)) {
            $device = intval($device);
        }

        if (! is_dir($this->chipPath($device))) {
            throw PWMException::chipNotFound($device, $this->sysfs_root);
        }

        return new PosixPWMConnectionFactory($device, $this);
    }

    protected function getTransport(int|string $device, int $channel): PosixPWMTransport
    {
        /** @var PosixPWMConnectionFactory $factory */
        $factory = $this->connections->get($device);
        $chip_path = $factory->chipPath();
        $channel_path = "{$chip_path}/pwm{$channel}";

        if (! is_dir($channel_path)) {
            $export = "{$chip_path}/export";

            if (! is_writable($export) || file_put_contents($export, (string) $channel) === false) {
                throw PWMException::couldNotExport($device, $channel);
            }
        }

        // The kernel creates the channel dir before udev chmods its attributes.
        $this->waitUntilWritable("{$channel_path}/period", $factory->ready_timeout_ms);

        return new PosixPWMTransport($channel, $chip_path);
    }

    /** A chip is a sysfs directory: nothing to close. Each channel unexported itself on close(). */
    protected function closeConnection(mixed $handle): void {}

    /** A worker builds its driver on the same sysfs tree as this one. */
    public function workerArguments(): array
    {
        return [$this->sysfs_root];
    }

    public function chipPath(int $chip): string
    {
        return "{$this->sysfs_root}/pwmchip{$chip}";
    }

    /**
     * The kernel creates the channel directory before udev hands its attributes to the gpio group. With a loop bound,
     * the wait polls on a loop timer: a fiber suspends, and the main stack keeps the loop turning. Without one, it sleeps.
     * @throws PWMException
     */
    protected function waitUntilWritable(string $path, int $timeout_ms): void
    {
        $deadline = hrtime(true) + ($timeout_ms * 1_000_000);
        $settled = fn (): bool => is_writable($path) || hrtime(true) >= $deadline;
        $loop = $this->eventLoop();

        if (is_null($loop)) {
            while (! $settled()) {
                usleep(10_000);
            }
        } elseif (! $settled()) {
            $poll = $loop->every(0.01, static fn () => null, "pwm-ready:{$path}");

            try {
                $loop->until($settled);
            } finally {
                $poll->cancel();
            }
        }

        if (! is_writable($path)) {
            throw PWMException::channelNotReady($path);
        }
    }
}

<?php

namespace Microscrap\ScrapyardLinux\PWM;

use GeneralPurposeIO\Contracts\PWM\PWMException;
use GeneralPurposeIO\PWM\PWMTransport;

class PosixPWMTransport extends PWMTransport
{
    public readonly string $channel_path;

    public function __construct(
        int $channel,
        public readonly string $chip_path,
    ) {
        parent::__construct($channel);

        $this->channel_path = "{$chip_path}/pwm{$channel}";
    }

    public function handle(): string
    {
        return $this->channel_path;
    }

    public function getPeriod(): int
    {
        return (int) $this->readAttribute('period');
    }

    public function setPeriod(int $value): int
    {
        $this->writeAttribute('period', (string) $value);

        return $this->getPeriod();
    }

    public function getEnable(): bool
    {
        return $this->readAttribute('enable') === '1';
    }

    public function setEnable(bool $value): bool
    {
        $this->writeAttribute('enable', $value ? '1' : '0');

        return $this->getEnable();
    }

    public function getDutyCycle(): int
    {
        return (int) $this->readAttribute('duty_cycle');
    }

    public function setDutyCycle(int $value): int
    {
        $this->writeAttribute('duty_cycle', (string) $value);

        return $this->getDutyCycle();
    }

    /** true when the sysfs polarity is 'inversed', false for 'normal'. */
    public function getPolarity(): bool
    {
        $polarity = $this->readAttribute('polarity');

        return match ($polarity) {
            'inversed' => true,
            'normal' => false,
            default => throw PWMException::invalidPolarity($polarity),
        };
    }

    public function setPolarity(bool $value): bool
    {
        $this->writeAttribute('polarity', $value ? 'inversed' : 'normal');

        return $this->getPolarity();
    }

    /** Disable the output and hand the channel back to the kernel. */
    public function close(): void
    {
        @file_put_contents("{$this->channel_path}/enable", '0');
        @file_put_contents("{$this->chip_path}/unexport", (string) $this->channel);
    }

    protected function writeAttribute(string $attribute, string $value): void
    {
        $path = "{$this->channel_path}/{$attribute}";

        if (@file_put_contents($path, $value) === false) {
            throw PWMException::couldNotWrite($path);
        }
    }

    protected function readAttribute(string $attribute): string
    {
        $path = "{$this->channel_path}/{$attribute}";
        $value = is_readable($path) ? file_get_contents($path) : false;

        if ($value === false) {
            throw PWMException::couldNotRead($path);
        }

        return trim($value);
    }
}

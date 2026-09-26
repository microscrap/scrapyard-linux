<?php

namespace Microscrap\ScrapyardLinux\SPI;

use Closure;
use GeneralPurposeIO\Contracts\SPI\SPIException;
use GeneralPurposeIO\SPI\SPITransport;

/**
 * One spidev bus's lock, shared by every process that talks on it: flock() on /run/lock/scrapyard-spi<bus>.lock.
 * spidev makes each message atomic, but chip select stays asserted between the messages of a call longer than bufsiz,
 * and across a select(). While one slave does that, no other chip select on the bus may be driven, whether the other
 * caller is a pool worker, this process or another program. One lock per bus per process, re-entrant for the slave
 * holding it. The driver waits out a select() running in another fiber, so another slave of this process meeting the
 * lock held is on the holder's own stack, or on an in-process work target's second driver; either way it throws.
 * spi_open rewrites the kernel device and can drop chip select: during() takes the same flock around an open.
 */
final class SpidevBusLock
{
    /** @var array<int, self> */
    private static array $locks = [];

    /** @var resource|null */
    private $file = null;

    private ?SPITransport $holder = null;

    private int $depth = 0;

    private function __construct(
        private readonly int $bus,
    ) {}

    public static function for(int $bus): self
    {
        return self::$locks[$bus] ??= new self($bus);
    }

    /** Where every process finds the bus's lock. /run/lock is tmpfs, world-writable, on systemd Linux (the Pi 5 included). */
    public static function path(int $bus): string
    {
        $dir = is_dir('/run/lock') && is_writable('/run/lock') ? '/run/lock' : sys_get_temp_dir();

        return "{$dir}/scrapyard-spi{$bus}.lock";
    }

    /** Runs $io with the bus to $slave alone, waiting while another process has it. */
    public function around(SPITransport $slave, Closure $io): mixed
    {
        if (! is_null($this->holder) && $this->holder !== $slave) {
            throw SPIException::busHeld($this->bus, $this->holder->chipSelect());
        }

        $this->acquireLock();
        $this->holder ??= $slave;

        try {
            return $io();
        } finally {
            $this->release();
        }
    }

    /** Flock with no slave: spi_open must not land while a call holds chip select. */
    public function during(Closure $io): mixed
    {
        $this->acquireLock();

        try {
            return $io();
        } finally {
            $this->release();
        }
    }

    private function acquireLock(): void
    {
        if ($this->depth === 0) {
            $this->file ??= $this->open();

            if (! flock($this->file, LOCK_EX)) {
                throw SPIException::busLockUnavailable(self::path($this->bus));
            }
        }

        $this->depth++;
    }

    private function release(): void
    {
        if (--$this->depth === 0) {
            $this->holder = null;
            flock($this->file, LOCK_UN);
        }
    }

    /** @return resource opened close-on-exec: a pool worker opens its own, so the lock keeps it out */
    private function open()
    {
        $path = self::path($this->bus);

        // made by whichever process comes first; another user's copy opens read-only, and flock() takes it all the same
        $file = @fopen($path, 'ce') ?: @fopen($path, 're');

        return $file ?: throw SPIException::busLockUnavailable($path);
    }
}

<?php

namespace Microscrap\ScrapyardLinux\SPI;

use Closure;
use GeneralPurposeIO\Contracts\SPI\SPIException;
use GeneralPurposeIO\SPI\SPITransport;
use Microscrap\Bindings\SPI\DataObjects\SPIDevice;
use Microscrap\Bindings\SPI\DataObjects\SPITransfer;

/**
 * One /dev/spidev<bus>.<cs>. A call goes out as spidev messages of at most the kernel's bufsiz. Chip select stays
 * asserted across message boundaries (cs_change on each message's last transfer), so a call of any length is one
 * selection on the wire. Inside select() the last message keeps it asserted too, and endSelection() lets it go with a
 * zero-length message. Every call, and a whole select(), holds the bus lock, so no other process drives another chip
 * select meanwhile. spidev keeps speed and word size per device for every fd in every process, and any open (a pool
 * worker's included) rewrites them, so every transfer carries this slave's clock and the bus word size itself.
 */
class PosixSPITransport extends SPITransport
{
    /** A message left chip select asserted: it has to be let go. */
    private bool $holding = false;

    /** @var array{string, string}|null [every byte value, the same bit-reversed], for strtr() */
    private static ?array $bit_reversal = null;

    public function __construct(
        int $chip_select,
        protected readonly SPIDevice $handle,
        private readonly int $bus,
        private readonly int $max_message = 4096,
        private readonly bool $reverse_bits = false,
    ) {
        parent::__construct($chip_select);
    }

    public function handle(): SPIDevice
    {
        return $this->handle;
    }

    /** The kernel checks the clock (spi_setup); from then on every transfer of this slave carries it. */
    public function speed(int $hz): static
    {
        $this->ensureOpen();

        if (spi_set_speed($this->handle, $hz) !== 0) {
            throw SPIException::speedRefused($this->handle->path, $hz);
        }

        $this->hz = $hz;

        return $this;
    }

    public function read(int $len): array|false
    {
        $this->ensureOpen();

        return $this->exchange([[str_repeat("\0", $len), true]]);
    }

    public function write(array|string $data): int
    {
        $data = is_array($data) ? array2bytes($data) : $data;

        $this->ensureOpen();

        return $this->exchange([[$data, false]]) === false ? -1 : strlen($data);
    }

    public function transfer(array|string $data): array|false
    {
        $data = is_array($data) ? array2bytes($data) : $data;

        $this->ensureOpen();

        return $this->exchange([[$data, true]]);
    }

    public function writeRead(array|string $bytes_to_write, int $bytes_to_read): array|false
    {
        $tx = is_array($bytes_to_write) ? array2bytes($bytes_to_write) : $bytes_to_write;

        $this->ensureOpen();

        return $this->exchange([[$tx, false], [str_repeat("\0", $bytes_to_read), true]]);
    }

    /** Chip select asserts with the first message; nothing to send yet. */
    protected function beginSelection(): void {}

    protected function endSelection(): void
    {
        $this->letGoOfChipSelect();
    }

    /** A whole selection holds the bus lock, chip select down to chip select up. */
    protected function whileSelected(Closure $selection): mixed
    {
        return SpidevBusLock::for($this->bus)->around($this, $selection);
    }

    protected function release(): void
    {
        spi_close($this->handle);
    }

    /**
     * $segments ([tx bytes, whether their rx is kept]) as spidev messages of at most max_message bytes, each a list of
     * [transfer, whether its rx is kept]. Every message but the last leaves chip select asserted (cs_change on its last
     * transfer); inside select() the last one does too. Empty segments send nothing.
     * @param list<array{string, bool}> $segments
     * @return list<list<array{SPITransfer, bool}>>
     */
    protected function messages(array $segments): array
    {
        /** @var list<list<array{string, bool}>> $chunked */
        $chunked = [];
        $room = 0;

        foreach ($segments as [$tx, $keep]) {
            foreach (str_split($tx, $this->max_message) as $chunk) {
                if ($chunk === '') {
                    continue;
                }

                if (strlen($chunk) > $room) {
                    $chunked[] = [];
                    $room = $this->max_message;
                }

                $chunked[count($chunked) - 1][] = [$chunk, $keep];
                $room -= strlen($chunk);
            }
        }

        $last = count($chunked) - 1;
        $messages = [];

        foreach ($chunked as $i => $chunks) {
            $keep_cs = $i !== $last || $this->selected();
            $message = [];

            foreach ($chunks as $j => [$chunk, $keep]) {
                $message[] = [new SPITransfer(
                    tx: $this->bits($chunk),
                    len: strlen($chunk),
                    speedHz: $this->hz ?? $this->handle->speed,
                    bitsPerWord: $this->handle->bitsPerWord,
                    csChange: $keep_cs && $j === count($chunks) - 1,
                ), $keep];
            }

            $messages[] = $message;
        }

        return $messages;
    }

    /**
     * Sends $segments under the bus lock and hands back the kept rx as a byte array, or false when the kernel refused
     * a message.
     * @param list<array{string, bool}> $segments
     */
    private function exchange(array $segments): array|false
    {
        return SpidevBusLock::for($this->bus)->around($this, function () use ($segments): array|false {
            $rx = '';

            foreach ($this->messages($segments) as $message) {
                $in = spi_transfer($this->handle, ...array_column($message, 0));

                if ($in === false) {
                    if (! $this->selected()) {
                        $this->letGoOfChipSelect();
                    }

                    return false;
                }

                $this->holding = $message[count($message) - 1][0]->csChange;
                $at = 0;

                foreach ($message as [$transfer, $keep]) {
                    if ($keep) {
                        $rx .= substr($in, $at, $transfer->len);
                    }

                    $at += $transfer->len;
                }
            }

            return bytes2array($this->bits($rx));
        });
    }

    /** A zero-length message without cs_change: chip select goes up. Nothing to send when no message left it down. */
    private function letGoOfChipSelect(): void
    {
        if (! $this->holding) {
            return;
        }

        $this->holding = false;

        spi_transfer($this->handle, new SPITransfer(tx: '', len: 0));
    }

    private function bits(string $bytes): string
    {
        if (! $this->reverse_bits) {
            return $bytes;
        }

        self::$bit_reversal ??= self::bitReversal();

        return strtr($bytes, self::$bit_reversal[0], self::$bit_reversal[1]);
    }

    /** @return array{string, string} */
    private static function bitReversal(): array
    {
        [$plain, $reversed] = ['', ''];

        for ($byte = 0; $byte < 256; $byte++) {
            $mirror = 0;

            for ($bit = 0; $bit < 8; $bit++) {
                if ($byte & (1 << $bit)) {
                    $mirror |= 1 << (7 - $bit);
                }
            }

            $plain .= chr($byte);
            $reversed .= chr($mirror);
        }

        return [$plain, $reversed];
    }
}

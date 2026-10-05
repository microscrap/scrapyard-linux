<?php

namespace Microscrap\ScrapyardLinux\SPI;

use Closure;
use GeneralPurposeIO\Contracts\SPI\SPIException;
use GeneralPurposeIO\Contracts\SPI\WritesFromMemory;
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
 * writeFrom() sends bytes straight out of memory the same way, with no copy into PHP.
 */
class PosixSPITransport extends SPITransport implements WritesFromMemory
{
    /** SPI_IOC_MESSAGE's size field is 14 bits: 16383 bytes of 32-byte structs. */
    private const MAX_TRANSFERS = 511;

    /**
     * spidev counts each transfer against bufsiz as its length rounded up to ARCH_DMA_MINALIGN: 128 on arm64 (measured
     * on the Pi 5, kernel 6.12: 1 + 65409 bytes is refused at bufsiz 65536, 1 + 65408 taken), smaller elsewhere.
     */
    private const DMA_ALIGN = 128;

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

    /**
     * $spans ([address, length]) straight out of memory: spidev reads each transfer's tx_buf at its address and
     * nothing is copied into PHP. Messages and chip select as write(): at most max_message bytes a message, chip
     * select held across them, and inside select() after the last too.
     *
     * @param  list<array{int, int}>  $spans
     *
     * @throws SPIException when this slave reverses bits in software
     */
    public function writeFrom(array $spans): int
    {
        if ($this->reverse_bits) {
            throw SPIException::memoryNeedsNativeBitOrder($this->handle->path);
        }

        $this->ensureOpen();

        return SpidevBusLock::for($this->bus)->around($this, function () use ($spans): int {
            $written = 0;

            foreach ($this->memoryMessages($spans) as $message) {
                if (spi_transfer($this->handle, ...$message) === false) {
                    if (! $this->selected()) {
                        $this->letGoOfChipSelect();
                    }

                    return -1;
                }

                $this->holding = $message[count($message) - 1]->csChange;

                foreach ($message as $transfer) {
                    $written += $transfer->len;
                }
            }

            return $written;
        });
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
     * $segments ([tx bytes, whether their rx is kept]) as spidev messages of at most max_message bytes, each transfer
     * counted rounded up to DMA_ALIGN, each a list of [transfer, whether its rx is kept]. Every message but the last
     * leaves chip select asserted (cs_change on its last transfer); inside select() the last one does too. Empty
     * segments send nothing.
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

                if (self::aligned(strlen($chunk)) > $room) {
                    $chunked[] = [];
                    $room = $this->max_message;
                }

                $chunked[count($chunked) - 1][] = [$chunk, $keep];
                $room -= self::aligned(strlen($chunk));
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
     * $spans as spidev messages of at most max_message bytes and MAX_TRANSFERS transfers, each transfer counted rounded
     * up to DMA_ALIGN, a span split on a DMA_ALIGN boundary where a message fills. Every message but the last leaves
     * chip select asserted; inside select() the last one does too.
     *
     * @param  list<array{int, int}>  $spans
     * @return list<list<SPITransfer>>
     */
    protected function memoryMessages(array $spans): array
    {
        /** @var list<list<array{int, int}>> $chunked */
        $chunked = [];
        $room = 0;

        foreach ($spans as [$address, $length]) {
            while ($length > 0) {
                if ($room < self::DMA_ALIGN || count($chunked[count($chunked) - 1]) === self::MAX_TRANSFERS) {
                    $chunked[] = [];
                    $room = $this->max_message;
                }

                $take = self::aligned($length) <= $room ? $length : intdiv($room, self::DMA_ALIGN) * self::DMA_ALIGN;
                $chunked[count($chunked) - 1][] = [$address, $take];
                $address += $take;
                $length -= $take;
                $room -= self::aligned($take);
            }
        }

        $last = count($chunked) - 1;
        $messages = [];

        foreach ($chunked as $i => $chunks) {
            $keep_cs = $i !== $last || $this->selected();
            $message = [];

            foreach ($chunks as $j => [$address, $take]) {
                $message[] = new SPITransfer(
                    tx: '',
                    len: $take,
                    speedHz: $this->hz ?? $this->handle->speed,
                    bitsPerWord: $this->handle->bitsPerWord,
                    csChange: $keep_cs && $j === count($chunks) - 1,
                    txAddress: $address,
                );
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

    /** $length as spidev counts it against bufsiz. */
    private static function aligned(int $length): int
    {
        return intdiv($length + self::DMA_ALIGN - 1, self::DMA_ALIGN) * self::DMA_ALIGN;
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

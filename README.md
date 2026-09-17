# microscrap/spi — Linux SPI / spidev bindings for ScrapyardIO

> **Docs (production):** [ScrapyardIO · microscrap/spi 0.7.x](https://scrapyard-io.projectsaturnstudios.com/ecosystem/microscrap/spi/0.7.x/overview)

[![Docs](https://img.shields.io/badge/docs-ScrapyardIO-0ea5e9?logo=readthedocs&logoColor=white)](https://scrapyard-io.projectsaturnstudios.com/ecosystem/microscrap/spi/0.7.x/overview)
[![Packagist Version](https://img.shields.io/packagist/v/microscrap/spi.svg?label=packagist)](https://packagist.org/packages/microscrap/spi)
[![PHP Version Require](https://img.shields.io/packagist/php-v/microscrap/spi.svg)](https://packagist.org/packages/microscrap/spi)
[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![Requires ext-posi](https://img.shields.io/badge/ext--posi-%5E0.7-777bb4?logo=php&logoColor=white)](https://github.com/php-io-extensions/posi)

PHP library that wraps the [**posi**](https://github.com/php-io-extensions/posi) extension (`ext-posi`) with global helpers, enums, and data objects. Every helper delegates to `Microscrap\Bindings\SPI\Device`, which uses [`microscrap/posix`](https://github.com/microscrap/posix) for FD I/O and `ioctl`.

This project provides PHP bindings to the Linux `spidev` character device API, mirroring the public surface of `<linux/spi/spidev.h>` (the `SPI_IOC_*` ioctl family).

This is the **bindings** package — not the native extension. Ecosystem docs: [`0.7.x`](https://scrapyard-io.projectsaturnstudios.com/ecosystem/microscrap/spi/0.7.x/overview).

## Highlights

* Open a spidev device (`/dev/spidev0.0`, `/dev/spidev1.1`, etc.) and configure mode, clock speed, and word size in one call
* Half-duplex byte I/O via `spi_read` / `spi_write` (uses `posix_read` / `posix_write` under the hood)
* Full-duplex, chained transfers via `spi_transfer` and the `SPITransfer` data object — packs `spi_ioc_transfer` and issues `SPI_IOC_MESSAGE(n)`
* Per-segment overrides: clock speed, bits-per-word, inter-word delay, CS-change, dual/quad lane counts
* Inspect and mutate every spidev attribute — `mode`, `max_speed_hz`, `bits_per_word`, `lsb_first`
* Typed `SPIMode` bitmask covers CPOL/CPHA, CS polarity, 3-wire, loopback, no-CS, dual/quad/octal lanes, ready signal
* Automatically prefers a native `spi_transfer(int $fd, …)` from a low-level extension when one is loaded, otherwise falls back to the `ext-posi` memory path
* Thin global `spi_*` helper API — all functions are `function_exists`-guarded

## Requirements

* PHP `^8.4|^8.5|^8.6`
* Linux kernel with `spidev` enabled and a populated `/dev/spidev*` device
* **ext-posi** `^0.7.0` — install from [php-io-extensions/posi](https://github.com/php-io-extensions/posi); the `posi_mem_*` helpers are required for `spi_transfer` unless a native `spi_transfer(int $fd, …)` is loaded
* **microscrap/posix** `^0.7.0`

## Installation

Confirm **ext-posi** is loaded:

```bash
php -m | grep posi
```

Confirm the spidev device is visible (and that your user can access it, or run as root):

```bash
ls /dev/spidev*
```

On a Raspberry Pi, enable SPI via `raspi-config` or by adding `dtparam=spi=on` to `/boot/config.txt` / `/boot/firmware/config.txt`.

```bash
composer require microscrap/spi:^0.7.0
```

Composer autoloads `src/Helpers/spi-device.php`, registering the global `spi_*` functions when the name is free (`function_exists` guard).

## Usage

SPI transactions are driven through **global helper functions** (`spi_open`, `spi_read`, `spi_write`, `spi_transfer`, etc.). All helpers delegate to the `Device` facade and are only defined once (`function_exists` guard).

Enums live under `Microscrap\Bindings\SPI\Enums` — cases are **FULLY UPPERCASE**. The device handle is `Microscrap\Bindings\SPI\DataObjects\SPIDevice`, and a single transfer segment is described by `Microscrap\Bindings\SPI\DataObjects\SPITransfer`.

---

### Example — read a register from an SPI peripheral

```php
<?php

use Microscrap\Bindings\SPI\DataObjects\SPITransfer;
use Microscrap\Bindings\SPI\Enums\SPIMode;

// Open spidev0.0 at 500 kHz, mode 1 (CPHA=1, CPOL=0), 8 bits/word.
$dev = spi_open('/dev/spidev0.0', SPIMode::CPHA->value, 500_000, 8);
if (is_null($dev)) {
    exit("Failed to open SPI device\n");
}

// Full-duplex read of register 0x07. Many SPI sensors use bit 6 of the first
// byte to flag a read; adjust to match your device's protocol.
$rx = spi_transfer(
    $dev,
    new SPITransfer(tx: pack('CC', 0x07 | 0x40, 0x00), len: 2),
);

if ($rx === false) {
    exit("Transfer failed\n");
}

printf("REG 0x07 = 0x%02X\n", ord($rx[1]));

spi_close($dev);
```

---

### Example — chained, multi-segment transfer

`spi_transfer` accepts any number of `SPITransfer` segments. Each segment is sent back-to-back as a single `SPI_IOC_MESSAGE` transaction; CS is held low between segments unless a segment sets `csChange: true`.

```php
<?php

use Microscrap\Bindings\SPI\DataObjects\SPITransfer;

$dev = spi_open('/dev/spidev0.0', 0, 1_000_000, 8);

$rx = spi_transfer(
    $dev,
    new SPITransfer(tx: "\x9F", len: 1),                  // JEDEC ID command
    new SPITransfer(tx: str_repeat("\0", 3), len: 3),     // 3 bytes manufacturer + device ID
);

if ($rx === false || strlen($rx) !== 4) {
    exit("Transfer failed\n");
}

// Skip the first byte (clocked out during command phase).
printf("JEDEC ID: %s\n", strtoupper(bin2hex(substr($rx, 1))));

spi_close($dev);
```

---

## API Reference

### Device lifecycle and I/O

| Helper | Facade method | Description |
|---|---|---|
| `spi_open(string $path, int $mode = 0, int $speed = 500_000, int $bitsPerWord = 8)` | `Device::spiOpen` | Open `/dev/spidev*` and apply mode/speed/word-size; returns `SPIDevice\|null` |
| `spi_close(SPIDevice $dev)` | `Device::spiClose` | Close the file descriptor; returns 0 or -1 |
| `spi_read(SPIDevice $dev, int $len)` | `Device::spiRead` | Half-duplex read of up to `$len` bytes; returns `string\|false` |
| `spi_write(SPIDevice $dev, string $data)` | `Device::spiWrite` | Half-duplex write of `$data`; returns bytes written |
| `spi_transfer(SPIDevice $dev, SPITransfer ...$transfers)` | `Device::spiTransfer` | Full-duplex, chained transfer; returns concatenated MISO bytes or `false` |

`spi_open` validates each ioctl as it goes and closes the descriptor on any failure, so a non-null return guarantees the device is configured. The mode parameter is the same `SPIMode` bitmask the kernel uses (`SPI_IOC_WR_MODE32`).

### Configuration (ioctl)

| Helper | Facade method | Underlying ioctl |
|---|---|---|
| `spi_get_mode(SPIDevice $dev)` | `Device::spiGetMode` | `SPI_IOC_RD_MODE32` |
| `spi_set_mode(SPIDevice $dev, int $mode)` | `Device::spiSetMode` | `SPI_IOC_WR_MODE32` |
| `spi_get_speed(SPIDevice $dev)` | `Device::spiGetSpeed` | `SPI_IOC_RD_MAX_SPEED_HZ` |
| `spi_set_speed(SPIDevice $dev, int $hz)` | `Device::spiSetSpeed` | `SPI_IOC_WR_MAX_SPEED_HZ` |
| `spi_get_bits_per_word(SPIDevice $dev)` | `Device::spiGetBitsPerWord` | `SPI_IOC_RD_BITS_PER_WORD` |
| `spi_set_bits_per_word(SPIDevice $dev, int $bits)` | `Device::spiSetBitsPerWord` | `SPI_IOC_WR_BITS_PER_WORD` |
| `spi_get_lsb_first(SPIDevice $dev)` | `Device::spiGetLsbFirst` | `SPI_IOC_RD_LSB_FIRST` |
| `spi_set_lsb_first(SPIDevice $dev, bool $lsb)` | `Device::spiSetLsbFirst` | `SPI_IOC_WR_LSB_FIRST` |

All setters return `0` on success and `-1` on failure. Getters return the raw integer (or `bool` for `lsb_first`), and `-1` on ioctl failure for the int-returning ones. Mask `spi_get_mode` against `SPIMode` cases to test individual mode bits.

### Full-duplex transfers

#### `spi_transfer(SPIDevice $dev, SPITransfer ...$transfers): string|false`

Issues a single `SPI_IOC_MESSAGE(n)` ioctl bundling `n` transfer segments. CS is asserted at the start of the first segment and stays asserted across all segments unless an individual segment requests otherwise via `csChange`. Returns the concatenated MISO bytes from every segment (length equals the sum of each segment's `len`), or `false` if the transaction fails.

Two paths are supported transparently:

1. **Native fast path** — if a low-level extension exposes `spi_transfer(int $fd, …)` (note the `int` first argument), it is used directly.
2. **`ext-posi` fallback** — otherwise the call packs each `spi_ioc_transfer` struct manually using `posi_mem_alloc` / `posi_mem_write` / `posi_mem_read` and issues `SPI_IOC_MESSAGE(1)` per segment. This is the supported path for plain `ext-posi` installations.

If neither path is available, `spi_transfer` returns `false`.

---

## Data objects

### `SPIDevice`

```php
final readonly class SPIDevice {
    public int    $fd;          // open file descriptor
    public string $path;        // device path (/dev/spidev0.0)
    public int    $mode;        // SPIMode bitmask the device was opened with
    public int    $speed;       // max clock speed (Hz) configured at open time
    public int    $bitsPerWord; // word size configured at open time
}
```

The `mode`, `speed`, and `bitsPerWord` fields record the values passed to `spi_open` and are **not** updated when you later call `spi_set_mode` / `spi_set_speed` / `spi_set_bits_per_word`. Use the matching getter for the live kernel value.

### `SPITransfer`

```php
final readonly class SPITransfer {
    public function __construct(
        public string $tx,                  // bytes clocked out on MOSI (padded/truncated to $len)
        public int    $len,                 // total bytes clocked in each direction
        public int    $speedHz        = 0,  // override device speed (0 = use device default)
        public int    $delayUsecs     = 0,  // microseconds to hold after this segment
        public int    $bitsPerWord    = 0,  // override device word size (0 = use device default)
        public bool   $csChange       = false, // de-assert CS after this segment
        public int    $txNbits        = 0,  // 1 / 2 / 4 / 8 — dual/quad/octal MOSI
        public int    $rxNbits        = 0,  // 1 / 2 / 4 / 8 — dual/quad/octal MISO
        public int    $wordDelayUsecs = 0,  // microseconds between words
    ) {}
}
```

Notes:

* `$tx` is right-padded with NUL bytes if shorter than `$len`, and truncated if longer.
* For read-only segments pass `tx: str_repeat("\0", $len)` (or any dummy bytes — the slave ignores them on a pure read).
* `csChange` semantics are inverted from intuition: setting it `true` requests CS **de-assert** after this segment, which is what you want for "this is the last byte of a logical message" inside a chain.
* The dual/quad/octal lane fields require a controller and slave that support [SPI multi-IO](https://www.kernel.org/doc/Documentation/spi/spidev.rst); leave them at `0` for standard single-MOSI/MISO operation.

---

## Enums

All enums are `int`-backed with **FULLY UPPERCASE** cases that map directly to kernel constants.

### `SPIMode` — `mode` bitmask (`SPI_IOC_WR_MODE32`)

| Case | Value | Meaning |
|---|---|---|
| `MODE_0` | `0x00` | CPOL=0, CPHA=0 (alias of zero) |
| `CPHA` | `0x01` | Clock phase = 1 (sample on trailing edge) |
| `CPOL` | `0x02` | Clock polarity = 1 (idle high) |
| `CS_HIGH` | `0x04` | Chip-select is active-high |
| `LSB_FIRST` | `0x08` | LSB transmitted first |
| `THREE_WIRE` | `0x10` | Shared MOSI/MISO line |
| `LOOP` | `0x20` | Internal MOSI→MISO loopback |
| `NO_CS` | `0x40` | Disable hardware CS (manage manually) |
| `READY` | `0x80` | Slave pulls ready signal |
| `TX_DUAL` | `0x100` | Dual-lane MOSI |
| `RX_DUAL` | `0x200` | Dual-lane MISO |
| `TX_QUAD` | `0x400` | Quad-lane MOSI |
| `RX_QUAD` | `0x800` | Quad-lane MISO |
| `CS_WORD` | `0x1000` | Toggle CS per word |
| `TX_OCTAL` | `0x2000` | Octal-lane MOSI |
| `RX_OCTAL` | `0x4000` | Octal-lane MISO |
| `THREE_WIRE_HIZ` | `0x8000` | High-Z on inactive cycle of 3-wire |

The classical "SPI mode 0..3" values are simply `CPOL`/`CPHA` combinations:

| SPI mode | Bitmask | CPOL | CPHA |
|---|---|---|---|
| 0 | `SPIMode::MODE_0->value` (0) | 0 | 0 |
| 1 | `SPIMode::CPHA->value` (1) | 0 | 1 |
| 2 | `SPIMode::CPOL->value` (2) | 1 | 0 |
| 3 | `SPIMode::CPOL->value \| SPIMode::CPHA->value` (3) | 1 | 1 |

### `SPIOpCode` — ioctl request numbers (`<linux/spi/spidev.h>`)

| Case | Value |
|---|---|
| `SPI_IOC_RD_MODE` | `0x80016B01` |
| `SPI_IOC_WR_MODE` | `0x40016B01` |
| `SPI_IOC_RD_LSB_FIRST` | `0x80016B02` |
| `SPI_IOC_WR_LSB_FIRST` | `0x40016B02` |
| `SPI_IOC_RD_BITS_PER_WORD` | `0x80016B03` |
| `SPI_IOC_WR_BITS_PER_WORD` | `0x40016B03` |
| `SPI_IOC_RD_MAX_SPEED_HZ` | `0x80046B04` |
| `SPI_IOC_WR_MAX_SPEED_HZ` | `0x40046B04` |
| `SPI_IOC_RD_MODE32` | `0x80046B05` |
| `SPI_IOC_WR_MODE32` | `0x40046B05` |
| `MESSAGE_1` | `0x40206B00` |

`SPIOpCode::messageN(int $n): int` computes the variant `SPI_IOC_MESSAGE(n)` ioctl number for batched transfers:

```php
use Microscrap\Bindings\SPI\Enums\SPIOpCode;

$ioctl = SPIOpCode::messageN(3); // request for 3 chained spi_ioc_transfer structs
```

---

## Quick reference

| Helper | Signature |
|---|---|
| `spi_open` | `(string $path, int $mode = 0, int $speed = 500_000, int $bitsPerWord = 8): ?SPIDevice` |
| `spi_close` | `(SPIDevice $dev): int` |
| `spi_read` | `(SPIDevice $dev, int $len): string\|false` |
| `spi_write` | `(SPIDevice $dev, string $data): int` |
| `spi_get_mode` | `(SPIDevice $dev): int` |
| `spi_set_mode` | `(SPIDevice $dev, int $mode): int` |
| `spi_get_speed` | `(SPIDevice $dev): int` |
| `spi_set_speed` | `(SPIDevice $dev, int $hz): int` |
| `spi_get_bits_per_word` | `(SPIDevice $dev): int` |
| `spi_set_bits_per_word` | `(SPIDevice $dev, int $bits): int` |
| `spi_get_lsb_first` | `(SPIDevice $dev): bool` |
| `spi_set_lsb_first` | `(SPIDevice $dev, bool $lsb): int` |
| `spi_transfer` | `(SPIDevice $dev, SPITransfer ...$transfers): string\|false` |

---

## Notes

* **Half-duplex vs full-duplex.** `spi_read` and `spi_write` operate on the kernel's `posix_read` / `posix_write` path, which is half-duplex — the kernel clocks out dummy bytes on a read and ignores MISO on a write. For real full-duplex transactions (where the bytes you receive correspond byte-for-byte to the bytes you transmit), use `spi_transfer` with one or more `SPITransfer` segments.
* **CS behavior.** A single `spi_transfer` call holds CS low across every segment by default. Set `csChange: true` on a segment to release CS after it. Use `SPIMode::NO_CS` if your driver provides CS via a GPIO instead of the controller.
* **Word size on Linux.** Most spidev drivers only support 8-bit words. Setting `bitsPerWord` to anything else may return `-1` on platforms that don't support it.
* **Speed.** The configured speed is an upper bound. The kernel rounds down to the closest divider the SPI controller can produce; query `spi_get_speed` afterwards if exact-rate behavior matters.
* **Concurrency.** Linux serialises access to a spidev character device per file descriptor. If you need overlapping transactions, open the same device twice — but the kernel will still serialise them on the bus.

## License

MIT. See [LICENSE](LICENSE).

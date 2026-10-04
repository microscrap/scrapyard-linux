# microscrap/scrapyard-linux

[![Tests](https://github.com/microscrap/scrapyard-linux/actions/workflows/tests.yml/badge.svg)](https://github.com/microscrap/scrapyard-linux/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/microscrap/scrapyard-linux.svg)](https://packagist.org/packages/microscrap/scrapyard-linux)
[![License](https://img.shields.io/packagist/l/microscrap/scrapyard-linux.svg)](LICENSE)
[![Requires ext-posi](https://img.shields.io/badge/ext--posi-%5E0.9-777bb4?logo=php&logoColor=white)](https://github.com/php-io-extensions/posi)

The Linux adapter for [`scrapyard-io/framework`](https://github.com/scrapyard-io/framework): the `native` driver for I2C, SPI, UART, digital pins and PWM, on a Raspberry Pi or any Linux board that exposes the standard kernel interfaces.

```
ext-posi                               1:1 POSIX, ioctl, termios and gpiod calls
  → microscrap/{posix,gpio,i2c,spi,uart}   PHP bindings
    → microscrap/scrapyard-linux       the `native` driver per protocol   ← this package
      → scrapyard-io/framework         managers, transports, the event loop and via()
```

| Protocol | Kernel interface | `connectTo()` | `device()` |
|---|---|---|---|
| I2C | `/dev/i2c-N` (i2c-dev) | bus number | slave address |
| SPI | `/dev/spidevN.CS` (spidev) | bus number | chip select |
| UART | a tty such as `/dev/ttyAMA0` or `/dev/ttyUSB0` | device path | the same path |
| Digital | `/dev/gpiochipN` (libgpiod v2 character device) | chip number | `input()` / `output()` line offset |
| PWM | `/sys/class/pwm/pwmchipN` (sysfs) | chip number | channel |

## Requirements

- PHP 8.4 or newer, on Linux
- [`ext-posi`](https://github.com/php-io-extensions/posi) 0.10: `pie install php-io-extensions/posi`
- `scrapyard-io/framework` 0.10, or just the `gpio/*` components it is split into
- Access to the device nodes. On Raspberry Pi OS, add your user to `gpio`, `i2c`, `spi` and `dialout`, and enable the interfaces you use with `raspi-config` or `dtparam`/`dtoverlay` lines in `config.txt`.

## Installation

```bash
composer require microscrap/scrapyard-linux
```

The service provider is discovered automatically and registers `native` on every protocol manager. Make it the default in `config/gpio.php`, or name it at each call:

```php
'protocols' => [
    'i2c' => ['default' => 'native'],
    'spi' => ['default' => 'native'],
    'uart' => ['default' => 'native'],
    'digital-in' => ['default' => 'native'],
    'pwm' => ['default' => 'native'],
],
```

## Usage

```php
use GeneralPurposeIO\Contracts\Digital\LineBias;

$fan = app('gpio.i2c')->driver('native')->connectTo(1)->register()->device(1, 0x21);
$temp = $fan->writeRead([0xFC], 1);

$panel = app('gpio.spi')->driver('native')->connectTo(0)->speed(32_000_000)->register()->device(0, 0);
$panel->write($frame);

$gps = app('gpio.uart')->driver('native')->connectTo('/dev/ttyAMA0')->baud(9600)->register()->device('/dev/ttyAMA0');
$sentence = $gps->readUntil("\r\n", timeout_ms: 1000);

$pins = app('gpio.digital')->driver('native')->connectTo(0)->register();
$button = $pins->input(0, 27, LineBias::PULL_UP);
$edge = $button->listen(500, rising_events: false, falling_events: true);

$servo = app('gpio.pwm')->driver('native')->connectTo(0)->register()->device(0, 0);
$servo->setPeriod(20_000_000);
$servo->setDutyCycle(1_500_000);
$servo->setEnable(true);
```

The framework's README covers the transports, the event loop and `via()`. What this adapter adds:

### Digital pins

- Each pin is its own line request. Inputs always request both edges; the framework filters rising or falling.
- The kernel keeps 64 unread edges per pin, the same depth as the framework's queue. Edge timestamps and sequence numbers are the kernel's.
- A watched input puts its line-request fd on the event loop, so edges wake the loop directly.
- `connectTo(N)->consumer('my-app')` sets the consumer name tools like `gpioinfo` show (default `scrapyard-io-digital-io`).

### I2C

- Every slave on a bus shares one fd, and each call selects its address first.
- `writeRead()` is a single `I2C_RDWR` transfer with a repeated START.
- `bulkWrite()` sends each chunk as its own message, 42 messages per transfer.

### SPI

- Each chip select opens its own `/dev/spidevN.CS` with the connection's mode, speed and word size (`bitsPerByte()`, default 8).
- A call longer than spidev's buffer (`/sys/module/spidev/parameters/bufsiz`, 4096 by default) goes out as several messages with chip select held throughout, so it is still one selection on the wire.
- Every process on the machine takes the bus in turn through `flock()` on `/run/lock/scrapyard-spi<bus>.lock`. A pool worker, another program and this process never interleave on one bus.
- `speed($hz)` on a slave sets its own clock, carried on every transfer.
- LSB-first on a controller that refuses it, such as the Pi 5's, is done by reversing bits in PHP for 8-bit words. Other word sizes throw.

### UART

- Ports are configured raw with never-waiting reads (VMIN=0, VTIME=0).
- Blocking reads and writes wait in `ppoll()` with the caller's timeout. On the event loop, the tty fd wakes the loop.
- A USB serial device that is unplugged while open throws on the next read instead of spinning.
- `dtr()` and `rts()` drive the modem lines; a port without them throws.

### PWM

- PWM uses plain sysfs files and needs no extension.
- A channel is exported the first time `device()` asks for it. The driver waits up to 500 ms for udev to make its files writable; set a different limit with `connectTo(N)->readyTimeout($ms)`. With an event loop bound, that wait keeps the loop turning.
- Every setter returns the value read back from sysfs.
- `close()` disables the channel and unexports it.

### Offloading

- `via()` jobs run on the app's worker pools: `'thread'` or `'process'`, or with none named, the thread pool when it is on and the process pool otherwise.
- A pool worker opens its own bus: every fd here is close-on-exec, so nothing is inherited.
- PWM workers are built on the same sysfs root as the parent's driver.

## Testing

```bash
composer install
vendor/bin/pest
```

The suite needs Linux and `ext-posi`, and no hardware:
- a pseudo-terminal and a FIFO stand in for serial devices;
- temporary directories stand in for sysfs;
- the planned spidev messages are checked without a device.

`tests/SPI/PiSpiBusTest.php` runs on a Raspberry Pi 5 with `spi0` and `spi10` enabled and nothing on CE1. Anywhere else it skips itself.

## License

MIT. See [LICENSE](LICENSE).

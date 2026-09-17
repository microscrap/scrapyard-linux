# scrapyard-linux

The `native` driver for [`scrapyard-io/framework`](https://github.com/scrapyard-io/framework): talk to a Linux board's own I2C, SPI, UART, GPIO and PWM from a Venusian app.

`microscrap/scrapyard-linux` plugs into the framework's protocol managers, so `I2C::driver('native')`, `SPI::driver('native')` and the rest open the kernel's devices: `i2c-dev`, `spidev`, serial ports, `libgpiod` chips and sysfs PWM. Chip drivers written against the framework's transports work unchanged on it.

## Requirements

- Linux, such as Raspberry Pi OS
- PHP 8.4 or newer with `ext-posi` loaded
- A Venusian application with `scrapyard-io/framework` 0.8
- The kernel interfaces you plan to use, and permission to open them:

| Protocol | Device | Raspberry Pi OS |
|---|---|---|
| I2C | `/dev/i2c-N` | enable with `raspi-config`; user in the `i2c` group |
| SPI | `/dev/spidevN.M` | enable with `raspi-config`; user in the `spi` group |
| UART | a serial port such as `/dev/ttyAMA0` | enable the serial port with `raspi-config`; user in the `dialout` group |
| Digital | `/dev/gpiochipN` | user in the `gpio` group |
| PWM | `/sys/class/pwm/pwmchipN` | add a PWM overlay in `config.txt` |

PWM works through sysfs files and doesn't use `ext-posi`.

## Installation

```bash
composer require microscrap/scrapyard-linux
```

The service provider is discovered automatically. It registers a `native` driver on the I2C, SPI, UART, DigitalIO and PWM managers.

Name the driver in each call, or make it the default in `config/gpio.php`:

```php
'protocols' => [
    'i2c' => ['default' => 'native'],
    'spi' => ['default' => 'native'],
    'uart' => ['default' => 'native'],
    'digital-in' => ['default' => 'native'],
    'pwm' => ['default' => 'native'],
],
```

## Quick start

A device at `0x3C` on `/dev/i2c-1`:

```php
use GeneralPurposeIO\I2C\I2C;

$display = I2C::driver('native')
    ->connectTo(1)
    ->register()
    ->device(1, 0x3C);

$display->probe();              // true when it answers
$display->write([0x00, 0xAF]);
```

## I2C

```php
$bus = I2C::driver('native')->connectTo(1)->register();   // /dev/i2c-1

$display = $bus->device(1, 0x3C);
$fan = $bus->device(1, 0x21);

$temperature = $fan->writeRead([0xFC], 1);
```

`connectTo()` takes the bus number, as an integer or a numeric string, and throws if `/dev/i2c-N` doesn't exist.

Every device on a bus shares one file descriptor:

- `read()` and `write()` select the device's address before each transfer, so devices on one bus can be used in any order.
- `writeRead()` sends the write and the read as one combined transaction with a repeated start.
- `bulkWrite()` sends several writes as one combined transaction.
- `probe()` sends an SMBus quick write and reports whether the device acknowledged it.

`close()` on any device closes the shared descriptor, which ends the bus for every device on it.

## SPI

```php
use GeneralPurposeIO\SPI\SPI;

$master = SPI::driver('native')
    ->connectTo(0)             // /dev/spidev0.*
    ->mode(3)
    ->speed(8_000_000)
    ->bitsPerByte(8)
    ->register();

$panel = $master->device(0, 0);    // /dev/spidev0.0
$sensor = $master->device(0, 1);   // /dev/spidev0.1
```

`connectTo()` takes the SPI master number and throws if no `/dev/spidevN.*` exists. `device()` opens `/dev/spidevN.CS` the first time you ask for that chip select, using the mode, speed and word size set on the connection. Later calls return a transport on the same open device.

`write()` and `read()` are half duplex. `transfer()` is full duplex and returns as many bytes as it sent. `close()` closes that chip select's device.

## UART

```php
use GeneralPurposeIO\Contracts\UART\FlowControl;
use GeneralPurposeIO\UART\UART;

$gps = UART::driver('native')
    ->connectTo('/dev/ttyAMA0')
    ->baud(9600)
    ->flowControl(FlowControl::NONE)
    ->register()
    ->device('/dev/ttyAMA0');

$gps->write("\$PMTK220,1000*1F\r\n");
$line = $gps->pollBytes();   // whatever has arrived, or ''
```

`connectTo()` takes the port's path and throws if it doesn't exist. Opening the port sets the baud rate, data bits, parity and stop bits. `FlowControl::HARDWARE` turns on RTS/CTS, and `FlowControl::SOFTWARE` turns on XON/XOFF.

`pollBytes()` checks the port without waiting and returns `''` when nothing has arrived. `flush()` discards buffered data, and `close()` closes the port.

## Digital pins

```php
use GeneralPurposeIO\Contracts\Digital\LineBias;
use GeneralPurposeIO\Digital\DigitalIO;

$chip = DigitalIO::driver('native')
    ->connectTo(0)             // /dev/gpiochip0
    ->consumer('weather-station')
    ->register();

$led = $chip->output(0, 17);
$button = $chip->input(0, 27, LineBias::PULL_UP);

$led->high();

if ($edge = $button->listen(5_000, rising: false, falling: true)) {
    echo "pressed at {$edge->timestamp} ns\n";
}
```

`connectTo()` takes the chip number and throws if `/dev/gpiochipN` doesn't exist. `consumer()` sets the label tools like `gpioinfo` show for the lines you request. It defaults to `scrapyard-io-digital-io`.

Pin numbers are line offsets on that chip. The first `output()` or `input()` for a line requests it, and later calls return the same transport. Asking for a line in the other direction throws.

An input line is requested with edge detection on both edges, and with the bias and `active_low` you pass:

- `read()` returns the current level.
- `pollEdges()` returns the edges queued since the last call, up to 16, without waiting.
- `listen($timeout_ms)` waits for one edge and returns `null` on timeout. A negative timeout waits indefinitely.

Edge timestamps come from the kernel, in nanoseconds.

`close()` on any pin closes its chip, which releases every line requested from it.

## PWM

```php
use GeneralPurposeIO\PWM\PWM;

$servo = PWM::driver('native')
    ->connectTo(0)             // /sys/class/pwm/pwmchip0
    ->readyTimeout(1_000)
    ->register()
    ->device(0, 0);            // channel 0

$servo->setPeriod(20_000_000);       // 50 Hz, in nanoseconds
$servo->setDutyCycle(1_500_000);
$servo->setEnable(true);
```

`connectTo()` takes the pwmchip number and throws if the chip doesn't exist. `device()` exports the channel if it isn't exported yet. It then waits up to `readyTimeout()` milliseconds for the channel's files to become writable, 500 by default, because udev sets their permissions just after the export.

Every setter writes the sysfs attribute, then reads it back and returns what the kernel kept. `setPolarity(true)` selects `inversed`, and `false` selects `normal`. `close()` disables the output and unexports the channel.

## The gpio dock

Input pins and serial ports never block when the framework's `gpio` dock resource polls them, so they can go straight onto it:

```php
use GeneralPurposeIO\Core\MagicAliases\GPIO;

GPIO::watch($button, rising: false, falling: true);   // DigitalEdgeOccurrence per edge
GPIO::receive($gps);                                  // UARTBytesOccurrence per batch of bytes
```

See the framework README for the rest of the dock.

## Errors

Failures throw the framework's protocol exceptions, such as `I2CException`, `SPIException`, `UARTException`, `DigitalIOException` and `PWMException`. They all extend `GPIOLevelException`. Typical causes are:

- The device, chip or port doesn't exist.
- The kernel refuses to open or configure it, usually because of permissions.
- A PWM channel can't be exported, or its files never become writable.

Transfers that fail on the bus don't throw. `read()` and `writeRead()` return `false`, and `write()` returns a negative count.

## Testing

```bash
composer install
vendor/bin/pest
```

The suite needs no hardware.

## License

MIT. See [LICENSE](LICENSE).

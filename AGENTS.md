# AGENTS.md — microscrap/scrapyard-linux

**Always read `.okf/index.md` first** before changing this package. Open only the concepts needed for the task; prefer `status: stable` when present. When you learn a durable package fact, update `.okf/` and append `.okf/log.md`.

## Role

The Linux adapter for `scrapyard-io/framework` 0.9: the `native` driver on the I2C, SPI, UART, DigitalIO and PWM managers, over `ext-posi` and the `microscrap/{posix,gpio,i2c,spi,uart}` bindings. Depends on the `gpio/*` splits, never on the whole framework.

## Rules

* Talk to the kernel through the `microscrap/*` helpers; no raw syscalls the bindings already cover.
* Every fd opens close-on-exec. A pool worker opens its own bus; it never inherits one.
* Waits take the caller's timeout. Blocking waits sleep in the kernel (`ppoll`, gpiod wait); loop waits go through the framework's hooks, an fd in the select set or a loop timer.
* Test suites stay hardware-free: ptys, FIFOs, temp sysfs trees, recorded messages. A test that needs a bench skips itself when the bench is absent.
* Prefer `is_null($var)` over `$var === null`.

## Quick OKF map

| Need | Concept |
|------|---------|
| Identity, provider, device naming | `.okf/overview.md` |
| gpiod pins and edges | `.okf/digital.md` |
| i2c-dev | `.okf/i2c.md` |
| spidev, bus lock | `.okf/spi.md` |
| termios ports | `.okf/uart.md` |
| sysfs PWM | `.okf/pwm.md` |
| Test stand-ins, CI | `.okf/testing.md` |

---
type: Concept
title: Overview
description: What scrapyard-linux 0.10 ships, where it sits, how it registers, how devices are named, and which waits block or ride the loop.
tags: [overview, provider, stack, drivers]
status: draft
generated: { by: claude-opus-5-5/claude-code, at: "2026-09-25T23:00:00Z" }
sources:
  - id: composer
    resource: composer.json
    title: composer.json
  - id: provider
    resource: src/Providers/ScrapyardLinuxServiceProvider.php
    title: ScrapyardLinuxServiceProvider
---

# Stack position

```
ext-posi                      1:1 POSIX, ioctl, termios, gpiod syscalls
  → microscrap/{posix,gpio,i2c,spi,uart}   PHP bindings over ext-posi
    → microscrap/scrapyard-linux           this package: the `native` driver per protocol
      → scrapyard-io/framework (gpio/*)    managers, transports, loop and via() machinery
```

Requires `gpio/{contracts,digital,i2c,spi,uart,pwm,nuts-and-bolts}` ^0.10 — the splits, not the framework. `ext-posi` ^0.10 is a hard requirement even though PWM alone never touches it.

# Provider

`ScrapyardLinuxServiceProvider::boot()` calls `extend('native', …)` on each manager: `PosixI2CConnectionDriver`, `PosixSPIConnectionDriver`, `PosixUARTConnectionDriver`, `PosixDigitalIOConnectionDriver`, `PosixPWMConnectionDriver`. Discovered through `extra.venusian.providers`. Nothing in `register()`. The managers hand each driver its loop and work-target resolvers; the adapter never looks them up itself.

# Device naming

| Protocol | `connectTo()` | `device()` / pin | Kernel node |
|---|---|---|---|
| I2C | bus number (int or numeric string) | slave address | `/dev/i2c-N` |
| SPI | bus number | chip select | `/dev/spidevN.CS` |
| UART | device path | the same path | the tty given |
| DigitalIO | gpiochip number | line offset | `/dev/gpiochipN` |
| PWM | pwmchip number | channel | `/sys/class/pwm/pwmchipN/pwmC` |

`connectTo()` checks the node exists and throws the protocol's exception when not.

# Blocking vs loop

- **Loop-woken (fd in the select set):** Digital input edges (line-request fd), UART intake (tty fd). Both via `posix_fdopen`, no dup — the stream owns the fd and close-on-exec holds.
- **Always blocking, fast:** I2C and SPI transfers. i2c-dev and spidev have no poll support worth selecting on (always ready), so they stay synchronous; `via()` moves them to a worker pool instead.
- **UART writes on the loop:** paced by POLLOUT checks on loop turns (see [uart.md](/uart.md)).
- **PWM export wait:** a loop timer when a loop is bound, `usleep` otherwise (see [pwm.md](/pwm.md)).

# Close-on-exec everywhere

Every fd this package opens is O_CLOEXEC (i2c-dev, spidev via `spi_open`, tty via `uart_open`, the SPI lock file with `fopen(…, 'ce')`, gpiod line requests by the kernel). A pool worker never inherits a bus; it opens its own.

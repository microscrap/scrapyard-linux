---
type: Concept
title: Testing
description: How the suite stands in for hardware — pty and FIFO devices, fake sysfs trees, recorded spidev messages, lock holders in child processes — plus the Pi SPI bench that skips itself and the CI job.
tags: [testing, pest, pty, ci]
status: draft
generated: { by: claude-opus-5-5/claude-code, at: "2026-09-25T23:00:00Z" }
sources:
  - id: pest
    resource: tests/Pest.php
    title: tests/Pest.php
  - id: ci
    resource: .github/workflows/tests.yml
    title: tests.yml
---

# Rule

The suite never needs a chip. Devices are simulated by kernel objects any Linux box has, or by recorded calls. Chips are for scratch smoke runs outside the suite.

# Stand-ins

| Area | Stand-in | Where |
|---|---|---|
| UART | pty pair: `/dev/ptmx` opened O_RDWR\|O_NOCTTY\|O_CLOEXEC, TIOCSPTLCK (0x40045431) unlock, TIOCGPTN (0x80045430) → `/dev/pts/N`; master fd plays the device | `ptyPair()` in `tests/Pest.php`; `UART/PtyPortTest`, `UART/PtyLoopTest` |
| UART hang-up | a FIFO whose writer closes | `PtyPortTest` |
| PWM | temp dir `scrapyard-pwm-*` with `pwmchipN/{export,unexport,npwm}` and channel attributes in kernel format | `pwmTree()`, `pwmChannelAppears()`; `PWM/SysfsChannelTest`, `PWM/SysfsOffloadTest` |
| SPI messages | `PlannedPosixSPITransport` fixture exposes the planned spidev messages without a device | `SPI/SpidevMessagesTest` |
| SPI lock | a child process holding the flock | `SPI/SpidevBusLockTest` |
| Provider | container + managers, drivers resolved by name | `I2C/ProviderTest` |

A pty forces 8N1, so the data-bits/parity test checks `applyLine()`'s mapping directly. pty and ioctl numbers are Linux-only: the suite needs Linux and `ext-posi`.

# Pi bench

`SPI/PiSpiBusTest` runs only with `ext-posi`, `/dev/spidev0.1` and `/dev/spidev10.0` present, nothing wired to CE1 (it clocks bytes out there). Anywhere else it skips itself.

# Running

```bash
composer install
vendor/bin/pest
```

# CI

`.github/workflows/tests.yml`: ubuntu, PHP 8.4, `sudo pie install php-io-extensions/posi:^0.10` (posi needs no system libraries; sudo because the system extension dir is root's), `composer update --prefer-stable`, `vendor/bin/pest`. Resolves `gpio/*` and `microscrap/*` 0.10 from Packagist.

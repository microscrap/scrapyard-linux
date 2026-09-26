---
type: Concept
title: PWM
description: sysfs PWM with no extension, channels exported on demand, a wait for udev to hand the attributes over, attributes read back after every write, workers built on the same sysfs root.
tags: [pwm, sysfs, udev, offload]
status: draft
generated: { by: claude-opus-5-5/claude-code, at: "2026-09-25T23:00:00Z" }
sources:
  - id: driver
    resource: src/PWM/PosixPWMConnectionDriver.php
    title: PosixPWMConnectionDriver
  - id: factory
    resource: src/PWM/PosixPWMConnectionFactory.php
    title: PosixPWMConnectionFactory
  - id: transport
    resource: src/PWM/PosixPWMTransport.php
    title: PosixPWMTransport
---

# Tree

`PosixPWMConnectionDriver(string $sysfs_root = '/sys/class/pwm')`. Chip = `<root>/pwmchipN` directory; channel = `<chip>/pwmC`. Plain `file_get_contents` / `file_put_contents` — no ext-posi. The root is injectable so tests point it at a temp tree.

# Connection

`connectTo(N)` requires the chip dir (`PWMException::chipNotFound`). The factory is the registered handle (it carries the chip path and `readyTimeout()`, default 500 ms). Nothing to close on `disconnect()`.

# Channel

`device(N, C)`: channel dir missing → write C to `<chip>/export` (`couldNotExport` when not writable). The kernel makes the dir before udev chmods its attributes to the `gpio` group, so the driver waits until `<channel>/period` is writable:

- loop bound: a 10 ms `every()` timer keeps the loop turning while `until()` checks — a fiber suspends, the main stack keeps other timers firing; the timer is cancelled after.
- no loop: `usleep(10 ms)` polling.
- past `ready_timeout_ms` → `PWMException::channelNotReady`.

# Attributes

`period`, `duty_cycle`, `enable` (`0`/`1`), `polarity` (`normal` → false, `inversed` → true, anything else → `invalidPolarity`). Every setter writes, then returns the getter's read-back. Every access: `ensureOpen()`, `awaitTurn()` (earlier `via()` jobs first). Unreadable/unwritable → `couldNotRead` / `couldNotWrite` naming the file.

`close()` writes `enable=0` then unexports the channel (both best-effort).

# via()

`PWMChannelGig` ships `workerArguments()` = `[$sysfs_root]`, so a worker builds its driver on the same tree the parent uses.

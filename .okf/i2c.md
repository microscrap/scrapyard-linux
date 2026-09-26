---
type: Concept
title: I2C
description: One i2c-dev fd per bus shared by every slave, slave address selected per call, writeRead and bulkWrite as I2C_RDWR messages, via() through a worker.
tags: [i2c, i2c-dev, rdwr, offload]
status: draft
generated: { by: claude-opus-5-5/claude-code, at: "2026-09-25T23:00:00Z" }
sources:
  - id: driver
    resource: src/I2C/PosixI2CConnectionDriver.php
    title: PosixI2CConnectionDriver
  - id: factory
    resource: src/I2C/PosixI2CConnectionFactory.php
    title: PosixI2CConnectionFactory
  - id: transport
    resource: src/I2C/PosixI2CTransport.php
    title: PosixI2CTransport
  - id: max
    resource: src/I2C/Enums/I2CRdwrIoctlMaxMsgs.php
    title: I2CRdwrIoctlMaxMsgs
---

# Connection

`connectTo(N)` → `posix_open("/dev/i2c-N", O_RDWR|O_CLOEXEC)`. The int fd is the registered handle; every `device(N, addr)` transport shares it. `disconnect()` → `posix_close`. A transport's `close()` releases nothing — the fd belongs to the bus.

# Calls

Every call: `ensureOpen()`, `ensureFits()` (framework's 8192-byte message limit), `awaitTurn()` (waits for this slave's earlier `via()` jobs).

| Call | Wire |
|---|---|
| `probe()` | set slave addr, then SMBus quick write; empty `i2c_write` fallback when SMBus helpers are missing |
| `read()` / `write()` | `I2C_SLAVE` ioctl for this address, then plain read/write on the shared fd |
| `writeRead()` | one `I2C_RDWR`: write message + read message, repeated START, no STOP between |
| `bulkWrite()` | each chunk its own write message; batches of 42 (`I2C_RDWR_IOCTL_MAX_MSGS`), STOP between batches |

Plain read/write address the fd's current slave, so the address is re-selected before each — slaves sharing the fd never talk to the wrong device. A refused address → `read()` false, `write()` -1.

# via()

Base framework behaviour: a `BusGig` naming `PosixI2CConnectionDriver` runs on a work target. A pool worker builds its own driver and opens its own `/dev/i2c-N` (close-on-exec keeps the parent's fd out). The kernel serialises transfers per adapter across processes.

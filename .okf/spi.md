---
type: Concept
title: SPI
description: One spidev fd per chip select, calls split into bufsiz messages with chip select held across them, a flock bus lock shared by every process, per-transfer clock and word size, software bit reversal when LSB-first is refused.
tags: [spi, spidev, bus-lock, chip-select, offload]
status: draft
generated: { by: claude-opus-5-5/claude-code, at: "2026-09-25T23:00:00Z" }
sources:
  - id: driver
    resource: src/SPI/PosixSPIConnectionDriver.php
    title: PosixSPIConnectionDriver
  - id: factory
    resource: src/SPI/PosixSPIConnectionFactory.php
    title: PosixSPIConnectionFactory
  - id: transport
    resource: src/SPI/PosixSPITransport.php
    title: PosixSPITransport
  - id: lock
    resource: src/SPI/SpidevBusLock.php
    title: SpidevBusLock
---

# Connection

`connectTo(N)` requires some `/dev/spidevN.*`. The factory itself is the registered handle — the bus holds no fd. Settings: mode, speed, endianness (framework) plus `bitsPerByte()` (spidev only, default 8); `settings()` / `configure()` carry all four as `SPIBusSettings`.

`device(N, cs)` → factory `chipSelect(cs)->open()`: `spi_open("/dev/spidevN.CS", mode, speed, bits)` under the bus lock (an open rewrites the kernel device and can drop chip select). Each slave owns its fd; `close()` → `spi_close`. Missing node → `SPIException::couldNotOpenSPIDevice`.

# LSB first

MSB → nothing to do. LSB → `spi_set_lsb_first`. Refused (Pi 5 controller) with 8-bit words → transport bit-reverses every byte in PHP (`strtr` table built once). Refused with any other word size → `SPIException::lsbFirstUnsupported`.

# Messages

`maxMessage()` = `/sys/module/spidev/parameters/bufsiz`, 4096 when unreadable. A call's segments (`[tx, keep rx]`) are chunked into messages ≤ bufsiz. Every message but the last sets `cs_change` on its last transfer → chip select stays asserted across message boundaries, so any length is one selection on the wire. Inside `select()` the last one keeps it too; `endSelection()` sends a zero-length message to release. Empty segments send nothing. spidev counts every transfer rounded up to ARCH_DMA_MINALIGN against bufsiz (128 on the Pi 5's arm64 6.12 kernel: 1 + 65409 bytes refused with EMSGSIZE, 1 + 65408 taken), so messages are planned at that size (`DMA_ALIGN`).

`writeFrom([[address, length], …])` (`WritesFromMemory`): the same planning over memory spans, each a transfer whose `tx_buf` is the address (`SPITransfer::$txAddress`), no copy; a span split on a 128-byte boundary where a message fills; at most 511 transfers a message (`SPI_IOC_MESSAGE`'s 14-bit size). A slave reversing bits in software refuses it.

Every transfer carries this slave's clock (`speed($hz)` → `spi_set_speed`, checked by the kernel) and the bus word size: spidev stores speed/bits per device node for every fd in every process, so a worker's open would otherwise change them underneath.

`writeRead()` = write segment + zero-filled read segment, one selection. `read()` clocks zeros. A refused message → `false` / -1, chip select released outside `select()`.

# Bus lock

`SpidevBusLock::for($bus)`: `flock(LOCK_EX)` on `/run/lock/scrapyard-spi<bus>.lock` (temp dir when `/run/lock` is not writable). Opened close-on-exec; another user's file opens read-only and still locks.

- `around($slave, $io)`: every call and a whole `select()`. Re-entrant for the holding slave (depth count). Another slave of the same process meeting it held → `SPIException::busHeld` — can only happen on the holder's own stack or an in-process second driver, since the framework makes other fibers wait out a `select()`.
- `during($io)`: opens, no slave.
- Another process holding it → this call waits in `flock`.

# via()

`SPIBusGig` on a worker pool; a pool worker opens its own spidev fd, the bus lock keeps its selections from interleaving with the parent's.

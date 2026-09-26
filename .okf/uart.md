---
type: Concept
title: UART
description: termios ports opened close-on-exec with never-waiting reads, ppoll for blocking waits, POLLOUT-gated chunks, the tty fd on the loop through posix_fdopen, hang-up detection, DTR/RTS over TIOCMBIS/TIOCMBIC.
tags: [uart, termios, ppoll, loop, modem-lines]
status: draft
generated: { by: claude-opus-5-5/claude-code, at: "2026-09-25T23:00:00Z" }
sources:
  - id: driver
    resource: src/UART/PosixUARTConnectionDriver.php
    title: PosixUARTConnectionDriver
  - id: factory
    resource: src/UART/PosixUARTConnectionFactory.php
    title: PosixUARTConnectionFactory
  - id: transport
    resource: src/UART/PosixUARTTransport.php
    title: PosixUARTTransport
---

# Opening

`connectTo('/dev/ttyAMA0')` requires the path to exist. `register()` → `uart_open(path, baud)` (O_CLOEXEC in `microscrap/uart`), then `tcgetattr` → `applyLine()` → `tcsetattr(TCSANOW)`. Configure failure closes the port and throws `couldNotConfigureUARTPort`.

`applyLine(array $termios)` (public, pure):

- CSIZE from `DataBits`; PARENB/PARODD from `Parity`; CSTOPB for two stop bits.
- CRTSCTS on for `FlowControl::HARDWARE`, IXON|IXOFF on for `SOFTWARE`, both off otherwise.
- VMIN=0, VTIME=0: a read returns what the kernel holds, possibly nothing. Waiting is ppoll's or the loop's job, always with the caller's timeout — a silent device cannot hang a read.

# Framework hooks

| Hook | Implementation |
|---|---|
| `drainBytes()` | `uart_read(port, 4096)` |
| `awaitBytes($ms)` | `posix_ppoll(fd, ns, POLLIN)` |
| `roomNow()` | `posix_ppoll(fd, 0, POLLOUT) > 0` |
| `awaitRoom($ms)` | `posix_ppoll(fd, ns, POLLOUT)` |
| `transmit()` | `uart_write` |
| `purge()` | `uart_flush` (tcflush both queues) |
| `intakeStreams()` | tty fd via `posix_fdopen(fd, 'r')`, once |
| `samplingInterval()` | null — the fd wakes the loop |

n_tty reports POLLOUT only while fewer than 256 bytes are queued, so one `TX_CHUNK` (256) always fits when `roomNow()` says yes.

# Hang-up

An unplugged ttyUSB reads 0 bytes while poll calls it ready — an endless wake loop. After an empty read, `ppoll(0, POLLIN) > 0` triggers a second read; still empty → `UARTException::readFailed`. Bytes landing between the two reads are returned normally. `uart_read` false → same exception.

# Modem lines

`dtr()` / `rts()` → `uart_set_modem_line` / `uart_clear_modem_line` (TIOCMBIS / TIOCMBIC). Negative result → `modemLinesUnsupported` (a pty, or a port without the lines).

# Release

Stream open → `fclose` gives the fd back; never opened → `uart_close`. `path()` = the port's path.

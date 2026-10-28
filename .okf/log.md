# Directory update log

## 2026-10-28

* **Version**: 0.11.0 with the Venusian 0.11 ecosystem: `version` 0.11.0, `dev-main` → `0.11.x-dev`, `ext-posi` `^0.11.0`, PHP `~8.4.27||^8.5.12`, docs URLs `0.11.x`, `scrapyard-io/framework` `^0.11.0`. `gpio/*`, the microscrap siblings and `venusian-voyager/*` at `^0.11.0`. README, AGENTS and the bundle relabelled; CI installs `posi` `^0.11` where it has a workflow. No API change. 60 passed on the Pi (8.4 ZTS).

## 2026-10-04
* **Update**: [spi](/spi.md) — `PosixSPITransport` implements `WritesFromMemory`: `writeFrom()` sends memory spans as transfers pointing at them. Both message planners count each transfer rounded up to 128 bytes, as spidev does, so a message of many short transfers is no longer refused with EMSGSIZE. Pinned by `tests/SPI/SpidevMemoryMessagesTest.php`, `SpidevMessagesTest.php`; suite 60/60 on the Pi.
* **Revision**: 0.10.0 — [overview](/overview.md) requires `gpio/*` and `ext-posi` ^0.10; `microscrap/posix` is gone (ext-posi 0.10 absorbed it), so the adapters use the extension's constants (`O_*`, `F_GETFL`/`F_SETFL`, `POLLIN`/`POLLOUT`). [i2c](/i2c.md), [spi](/spi.md): offloaded gigs run on a 0.10 worker pool. [testing](/testing.md): CI installs posi ^0.10 and resolves the 0.10 splits.

## 2026-09-25
* **Creation**: bundle for 0.9 — [overview](/overview.md), [digital](/digital.md), [i2c](/i2c.md), [spi](/spi.md), [uart](/uart.md), [pwm](/pwm.md), [testing](/testing.md).

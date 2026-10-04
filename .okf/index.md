---
okf_version: "0.2"
---

# microscrap/scrapyard-linux — knowledge bundle

Linux adapter for `scrapyard-io/framework` 0.10. Registers the `native` driver on the I2C, SPI, UART, DigitalIO and PWM managers. Kernel interfaces: `libgpiod` v2 character devices, `i2c-dev`, `spidev`, termios ttys, sysfs PWM — all through `ext-posi` and the `microscrap/*` bindings, PWM through plain files.

Read this index first, then only the concepts the task needs. Every concept is `status: draft` until a human verifies it.

# Concepts

* [overview.md](/overview.md) - what ships, stack position, provider, device naming, what blocks and what rides the loop
* [digital.md](/digital.md) - gpiochip connections, line requests, edge fd on the loop, close-on-exec
* [i2c.md](/i2c.md) - one shared i2c-dev fd per bus, address per call, I2C_RDWR batching, offload to a worker
* [spi.md](/spi.md) - fd per chip select, bufsiz messages with chip select held, cross-process bus lock, LSB-first fallback
* [uart.md](/uart.md) - VMIN=0 termios, ppoll waits, POLLOUT pacing, fdopen intake, hang-up detection, modem lines
* [pwm.md](/pwm.md) - sysfs pwmchip/channel files, export and udev wait, worker arguments
* [testing.md](/testing.md) - pty and FIFO devices, fake sysfs trees, recorded spidev messages, the self-skipping Pi bench, CI

# Log

* [log.md](/log.md)

<?php

namespace Microscrap\ScrapyardLinux\I2C\Enums;

/** I2C_RDWR_IOCTL_MAX_MSGS in linux/i2c-dev.h: the kernel refuses a larger batch outright. */
enum I2CRdwrIoctlMaxMsgs: int
{
    case MAX_MESSAGES_PER_TRANSFER = 42;
}

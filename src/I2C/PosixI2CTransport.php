<?php

namespace Microscrap\ScrapyardLinux\I2C;

use Microscrap\Bindings\I2C\Bus;
use GeneralPurposeIO\I2C\I2CTransport;
use Microscrap\Bindings\I2C\Enums\I2CMsgFlag;
use Microscrap\Bindings\I2C\DataObjects\I2CBus;
use Microscrap\Bindings\I2C\Enums\SMBusReadWrite;

class PosixI2CTransport extends I2CTransport
{
    public function __construct(
        int $address,
        public readonly int $fd
    ) {
        parent::__construct($address);
    }

    public function handle(): int
    {
        return $this->fd;
    }

    public function probe(): bool
    {
        $bus = new I2CBus($this->fd, '', $this->address);

        if (Bus::i2cSetSlaveAddr($bus, $this->address) !== 0) {
            return false;
        }

        if (function_exists('i2c_smbus_write_quick')) {
            return i2c_smbus_write_quick($bus, SMBusReadWrite::WRITE) === 0;
        }

        // Fallback when SMBus helpers are unavailable: empty write transaction.
        return i2c_write($bus, '') >= 0;
    }

    public function read(int $len): array|false
    {
        $bus = $this->addressedBus();

        if (is_null($bus)) {
            return false;
        }

        $bytes = i2c_read($bus, $len);

        if ($bytes === false) {
            return false;
        }

        return bytes2array($bytes);
    }

    public function write(array|string $data): int
    {
        $bus = $this->addressedBus();

        if (is_null($bus)) {
            return -1;
        }

        if (is_array($data)) {
            $data = array2bytes($data);
        }

        return i2c_write($bus, $data);
    }

    public function writeRead(array|string $bytes_to_write, int $bytes_to_read): array|false
    {
        $bus = new I2CBus($this->fd, "", $this->address);
        $write_bytes = is_array($bytes_to_write) ? array2bytes($bytes_to_write) : $bytes_to_write;

        $result = i2c_rdwr($bus, [
            ['flags' => 0, 'data' => $write_bytes],
            ['flags' => I2CMsgFlag::M_RD->value, 'len' => $bytes_to_read],
        ]);

        if ($result === false) {
            return false;
        }

        return bytes2array($result);
    }

    public function bulkWrite(array|string $messages): array|false
    {
        $bus = new I2CBus($this->fd, "", $this->address);
        $chunks = static::normalizeBulkMessages($messages);
        if (count($chunks) === 0) {
            return [];
        }

        $result = i2c_rdwr($bus, array_map(
            static fn (string $chunk): array => ['flags' => 0, 'data' => $chunk],
            $chunks,
        ));

        if ($result === false) {
            return false;
        }

        return array_map('strlen', $chunks);
    }

    /**
     * Plain read()/write() on an i2c-dev descriptor go to the descriptor's
     * current slave, and every transport on a bus shares one descriptor.
     * Select this transport's address before each plain transfer; null when
     * the kernel refuses it.
     */
    protected function addressedBus(): ?I2CBus
    {
        $bus = new I2CBus($this->fd, '', $this->address);

        return Bus::i2cSetSlaveAddr($bus, $this->address) === 0 ? $bus : null;
    }

    public function close(): void
    {
        $bus = new I2CBus($this->fd, "", 0x00);
        i2c_close($bus);
    }


}
<?php

use GeneralPurposeIO\Contracts\SPI\SPIException;
use GeneralPurposeIO\Contracts\SPI\WritesFromMemory;
use Microscrap\Bindings\SPI\DataObjects\SPIDevice;
use Microscrap\Bindings\SPI\DataObjects\SPITransfer;
use Microscrap\ScrapyardLinux\SPI\SpidevBusLock;
use Microscrap\ScrapyardLinux\Tests\Fixtures\PlannedPosixSPITransport;

/** A slave on made-up bus 93 at 1 MHz, 8-bit words, bufsiz $max_message (4096 by default). No fd: nothing here is sent. */
function memoryPlanned(bool $reverse_bits = false, int $max_message = 4096): PlannedPosixSPITransport
{
    return new PlannedPosixSPITransport(0, new SPIDevice(-1, '/dev/spidev93.0', 3, 1_000_000, 8), 93, $max_message, $reverse_bits);
}

/** @return list<list<array{int, int, bool}>> every message as [address, length, cs_change] per transfer */
function memoryShape(array $messages): array
{
    return array_map(fn (array $message): array => array_map(
        fn (SPITransfer $transfer): array => [$transfer->txAddress, $transfer->len, $transfer->csChange],
        $message,
    ), $messages);
}

afterEach(function () {
    $path = SpidevBusLock::path(93);
    if (is_file($path)) {
        unlink($path);
    }
});

it('is a bus that writes from memory', function () {
    expect(memoryPlanned())->toBeInstanceOf(WritesFromMemory::class);
});

it('points every transfer at its span and splits where bufsiz fills, each transfer counted rounded up to 128 bytes, chip select held between messages and let go after the last', function () {
    expect(memoryShape(memoryPlanned()->planFrom([[0x1000, 960], [0x2000, 5000]])))->toBe([
        [[0x1000, 960, false], [0x2000, 3072, true]],
        [[0x2000 + 3072, 1928, false]],
    ]);
});

it('puts this slave\'s clock and the bus word size on every memory transfer, and copies nothing', function () {
    $transfers = array_merge(...memoryPlanned()->clockAt(10_000_000)->planFrom([[0x1000, 9000]]));

    expect(array_unique(array_map(fn (SPITransfer $t): int => $t->speedHz, $transfers)))->toBe([10_000_000])
        ->and(array_unique(array_map(fn (SPITransfer $t): int => $t->bitsPerWord, $transfers)))->toBe([8])
        ->and(array_unique(array_map(fn (SPITransfer $t): string => $t->tx, $transfers)))->toBe(['']);
});

it('caps a message at 511 transfers, what SPI_IOC_MESSAGE can carry', function () {
    $spans = array_map(fn (int $row): array => [0x1000 + $row * 64, 4], range(0, 599));

    expect(array_map('count', memoryPlanned(max_message: 65536)->planFrom($spans)))->toBe([511, 89]);
});

it('sends nothing for no spans', function () {
    expect(memoryPlanned()->planFrom([]))->toBe([]);
});

it('refuses memory on a slave that reverses bits in software', function () {
    memoryPlanned(reverse_bits: true)->writeFrom([[0x1000, 4]]);
})->throws(SPIException::class, 'reverses bits');

it('fits as many short rows in a message as spidev takes, each counted as 128 bytes', function () {
    $spans = array_map(fn (int $row): array => [0x1000 + $row * 960, 100], range(0, 39));

    expect(array_map('count', memoryPlanned()->planFrom($spans)))->toBe([32, 8]);
});

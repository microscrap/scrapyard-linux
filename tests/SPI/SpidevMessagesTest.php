<?php

use Microscrap\Bindings\SPI\DataObjects\SPIDevice;
use Microscrap\ScrapyardLinux\SPI\SpidevBusLock;
use Microscrap\ScrapyardLinux\Tests\Fixtures\PlannedPosixSPITransport;

/** A slave on made-up bus 92 whose device opened at 1 MHz, 8-bit words. No fd: nothing here is sent. */
function planned(bool $reverse_bits = false): PlannedPosixSPITransport
{
    return new PlannedPosixSPITransport(0, new SPIDevice(-1, '/dev/spidev92.0', 3, 1_000_000, 8), 92, 4096, $reverse_bits);
}

/** @return list<list<array{int, bool, bool}>> every message as [length, rx kept, cs_change] per transfer */
function shape(array $messages): array
{
    return array_map(fn (array $message): array => array_map(
        fn (array $planned): array => [$planned[0]->len, $planned[1], $planned[0]->csChange],
        $message,
    ), $messages);
}

afterEach(function () {
    $path = SpidevBusLock::path(92);
    if (is_file($path)) {
        unlink($path);
    }
});

it('puts this slave\'s clock and the bus word size on every transfer', function () {
    $transfers = array_merge(...planned()->plan([["\x9F", false], [str_repeat("\0", 5000), true]]));
    $slave_clocked = array_merge(...planned()->clockAt(100_000)->plan([["\x9F", false]]));

    expect(array_unique(array_map(fn (array $planned): int => $planned[0]->speedHz, $transfers)))->toBe([1_000_000])
        ->and(array_unique(array_map(fn (array $planned): int => $planned[0]->bitsPerWord, $transfers)))->toBe([8])
        ->and($slave_clocked[0][0]->speedHz)->toBe(100_000);
});

it('splits a long read into bufsiz messages, chip select held between them and let go after the last', function () {
    expect(shape(planned()->plan([[str_repeat("\0", 10_000), true]])))->toBe([
        [[4096, true, true]],
        [[4096, true, true]],
        [[1808, true, false]],
    ]);
});

it('keeps a writeRead\'s write and read under one chip select, split or not', function () {
    expect(shape(planned()->plan([["\x0B", false], ["\0\0\0", true]])))->toBe([
        [[1, false, false], [3, true, false]],
    ])->and(shape(planned()->plan([["\x0B", false], [str_repeat("\0", 4200), true]])))->toBe([
        [[1, false, true]],
        [[4096, true, true]],
        [[104, true, false]],
    ]);
});

it('keeps chip select held after the last message inside select()', function () {
    $shape = planned()->select(fn (PlannedPosixSPITransport $held): array => shape($held->plan([["\x0B", false]])));

    expect($shape)->toBe([[[1, false, true]]]);
});

it('sends nothing for an empty segment', function () {
    expect(planned()->plan([['', true]]))->toBe([])
        ->and(planned()->plan([["\x01", false], ['', true]]))->toHaveCount(1);
});

it('reverses the bits of every byte it sends when the controller cannot send LSB first', function () {
    expect(planned(reverse_bits: true)->plan([["\x01\x80\xF0", false]])[0][0][0]->tx)->toBe("\x80\x01\x0F");
});

it('counts every transfer rounded up to 128 bytes against bufsiz, as spidev does', function () {
    expect(shape(planned()->plan([["\x0B", false], [str_repeat("\0", 4095), true]])))->toBe([
        [[1, false, true]],
        [[4095, true, false]],
    ]);
});

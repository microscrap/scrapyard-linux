<?php

use GeneralPurposeIO\Contracts\UART\DataBits;
use GeneralPurposeIO\Contracts\UART\FlowControl;
use GeneralPurposeIO\Contracts\UART\Parity;
use GeneralPurposeIO\Contracts\UART\StopBits;
use GeneralPurposeIO\Contracts\UART\UARTException;
use Microscrap\Bindings\UART\DataObjects\UARTPort;
use Microscrap\ScrapyardLinux\UART\PosixUARTConnectionDriver;
use Microscrap\ScrapyardLinux\UART\PosixUARTConnectionFactory;
use Microscrap\ScrapyardLinux\UART\PosixUARTTransport;

it('refuses a serial path that does not exist', function (): void {
    expect(fn () => (new PosixUARTConnectionDriver)->connectTo('/dev/ttyNOPE'))
        ->toThrow(UARTException::class, 'UART port [/dev/ttyNOPE] could not be opened');
});

it('starts at 9600 8N1 with no flow control', function (): void {
    $factory = new PosixUARTConnectionFactory('/dev/ttyAMA0', new PosixUARTConnectionDriver);

    expect($factory->baud_rate)->toBe(9_600)
        ->and($factory->data_bits)->toBe(DataBits::EIGHT)
        ->and($factory->parity)->toBe(Parity::NONE)
        ->and($factory->stop_bits)->toBe(StopBits::ONE)
        ->and($factory->flow_control)->toBe(FlowControl::NONE);
});

it('carries line settings as fluent state and accepts their int forms', function (): void {
    $factory = new PosixUARTConnectionFactory('/dev/ttyAMA0', new PosixUARTConnectionDriver);

    $same = $factory->baud(115_200)->parity(Parity::EVEN)->stopBits(2)->dataBits(7)->flowControl(FlowControl::HARDWARE);

    expect($same)->toBe($factory)
        ->and($factory->baud_rate)->toBe(115_200)
        ->and($factory->parity)->toBe(Parity::EVEN)
        ->and($factory->stop_bits)->toBe(StopBits::TWO)
        ->and($factory->data_bits)->toBe(DataBits::SEVEN)
        ->and($factory->flow_control)->toBe(FlowControl::HARDWARE)
        ->and($factory->parity(1)->parity)->toBe(Parity::ODD)
        ->and($factory->flowControl(2)->flow_control)->toBe(FlowControl::SOFTWARE);
});

it('returns null for a port that was never connected', function (): void {
    expect((new PosixUARTConnectionDriver)->device('/dev/ttyAMA0'))->toBeNull();
});

it('hands out a transport over the registered port', function (): void {
    $port = new UARTPort(5, '/dev/ttyAMA0', 9_600);
    $driver = new PosixUARTConnectionDriver;
    $driver->register('/dev/ttyAMA0', $port);

    $transport = $driver->device('/dev/ttyAMA0');

    expect($transport)->toBeInstanceOf(PosixUARTTransport::class)
        ->and($transport->path())->toBe('/dev/ttyAMA0')
        ->and($transport->handle())->toBe($port);
});

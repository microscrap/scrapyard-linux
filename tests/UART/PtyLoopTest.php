<?php

use GeneralPurposeIO\Contracts\UART\UARTReceived;
use Voyager\Contracts\IOPools\MailCollection;
use Voyager\Contracts\IOPools\Receivable;
use Voyager\IOPools\EventLoop;

afterEach(fn () => closePtys());

it('mails what the device sends while the port is watched', function () {
    [$master, $path] = ptyPair();
    $mail = new class implements Receivable {
        public array $events = [];

        public function handOff(MailCollection $mail): void
        {
            foreach ($mail->mail() as $event) {
                $this->events[] = $event;
            }
        }
    };
    $loop = new EventLoop(null, 16, $mail);
    $port = ptyPort($path, $loop);

    $port->watch();
    $loop->at(0.02, fn () => posix_write($master, "\$GNGGA,1*00\r\n", 13));
    $loop->at(0.08, fn () => $port->unwatch());
    $loop->run();

    expect(implode('', array_map(fn (UARTReceived $received) => $received->bytes, $mail->events)))->toBe("\$GNGGA,1*00\r\n")
        ->and($mail->events[0]->name())->toBe("gpio.uart.{$path}");
});

it('a read in a fiber suspends until the device sends', function () {
    [$master, $path] = ptyPair();
    $loop = new EventLoop;
    $port = ptyPort($path, $loop);
    $order = [];

    $reader = $loop->async(function () use ($port, &$order) {
        $order[] = 'waiting';
        $order[] = 'got '.$port->readUntil("\r\n", 1_000);
    });
    $loop->async(function () use (&$order) { $order[] = 'other fiber'; });
    $loop->at(0.03, fn () => posix_write($master, "+OK\r\n", 5));
    $loop->until(fn (): bool => $reader->settled());

    expect($order)->toBe(['waiting', 'other fiber', "got +OK\r\n"]);
});

it('write() on the loop reaches the device intact', function () {
    [$master, $path] = ptyPair();
    $loop = new EventLoop;
    $port = ptyPort($path, $loop);
    $payload = str_repeat("\$PMTK605*31\r\n", 80);    // 1040 bytes

    $wrote = $port->write($payload);

    $got = '';
    while (strlen($got) < strlen($payload) && posix_ppoll($master, 500_000_000, 1) === 1) {
        $got .= posix_read($master, 4096);
    }

    expect($wrote)->toBe(strlen($payload))
        ->and($got)->toBe($payload);
});

it('close() on a watched port gives the fd back and ends run()', function () {
    [, $path] = ptyPair();
    $loop = new EventLoop;
    $port = ptyPort($path, $loop);
    $fd = $port->port->fd;

    $port->watch();
    $loop->at(0.02, fn () => $port->close());
    $loop->run();

    expect(posix_write($fd, 'x', 1))->toBe(-1);
});

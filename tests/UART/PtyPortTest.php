<?php

use GeneralPurposeIO\Contracts\UART\FlowControl;
use GeneralPurposeIO\Contracts\UART\Parity;
use GeneralPurposeIO\Contracts\UART\UARTException;
use Microscrap\Bindings\UART\Enums\ControlChar;
use Microscrap\Bindings\UART\Enums\ControlFlag;
use Microscrap\Bindings\UART\Enums\InputFlag;
use Microscrap\Bindings\UART\DataObjects\UARTPort;
use Microscrap\ScrapyardLinux\UART\PosixUARTConnectionDriver;
use Microscrap\ScrapyardLinux\UART\PosixUARTTransport;

afterEach(fn () => closePtys());

it('passes bytes through untouched in both directions', function () {
    [$master, $path] = ptyPair();
    $port = ptyPort($path);
    $in = "\$GNGGA\r\n\x00\x11\x13\xff";

    posix_write($master, $in, strlen($in));

    expect($port->read(64, 500))->toBe(bytes2array($in))
        ->and($port->write("AT\r\n\x00"))->toBe(5)
        ->and(posix_ppoll($master, 500_000_000, 1))->toBe(1)
        ->and(posix_read($master, 64))->toBe("AT\r\n\x00");
});

it('read(…, 0) never waits, and a timeout waits it out', function () {
    [, $path] = ptyPair();
    $port = ptyPort($path);

    $started = hrtime(true);
    $none = $port->read(8, 0);
    $instant = (hrtime(true) - $started) / 1e6;

    $started = hrtime(true);
    $late = $port->read(8, 60);
    $waited = (hrtime(true) - $started) / 1e6;

    expect($none)->toBe([])
        ->and($instant)->toBeLessThan(5.0)
        ->and($late)->toBe([])
        ->and($waited)->toBeGreaterThanOrEqual(55.0);
});

it('waits in the kernel for a line the device sends later', function () {
    [$master, $path] = ptyPair();
    $port = ptyPort($path);
    $device = proc_open(['sh', '-c', 'sleep 0.05; printf \'$GNRMC,1*00\r\n\''], [1 => fopen("php://fd/{$master}", 'w')], $pipes);

    $started = hrtime(true);
    $line = $port->readUntil("\r\n", 1_000);
    $waited = (hrtime(true) - $started) / 1e6;
    proc_close($device);

    expect($line)->toBe("\$GNRMC,1*00\r\n")
        ->and($waited)->toBeGreaterThanOrEqual(40.0);
});

it('writes a payload bigger than one chunk intact', function () {
    [$master, $path] = ptyPair();
    $port = ptyPort($path);
    $payload = str_repeat("\$GPTXT,01,01,02,MAIN*00\r\n", 40);    // 1000 bytes

    expect($port->write($payload))->toBe(strlen($payload));

    $got = '';
    while (strlen($got) < strlen($payload) && posix_ppoll($master, 500_000_000, 1) === 1) {
        $got .= posix_read($master, 4096);
    }

    expect($got)->toBe($payload);
});

it('close() gives the fd back and refuses every later call', function () {
    [, $path] = ptyPair();
    $port = ptyPort($path);
    $fd = $port->port->fd;

    $port->close();

    expect(posix_write($fd, 'x', 1))->toBe(-1)
        ->and(fn () => $port->read(1, 0))->toThrow(UARTException::class, "UART port {$path} is closed.");
});

it('says so when the device has no modem control lines', function () {
    [, $path] = ptyPair();

    expect(fn () => ptyPort($path)->dtr(true))
        ->toThrow(UARTException::class, "UART port {$path} cannot set DTR: the device has no modem control lines, or did not answer.");
});

it('keeps the stop bits, flow control and never-waiting reads it was opened with', function () {
    [, $path] = ptyPair();
    $driver = new PosixUARTConnectionDriver;
    $driver->connectTo($path)->baud(9_600)->stopBits(2)->flowControl(FlowControl::HARDWARE)->register();

    $termios = uart_tcgetattr($driver->device($path)->port);

    expect($termios['c_cflag'] & ControlFlag::CSTOPB->value)->toBe(ControlFlag::CSTOPB->value)
        ->and($termios['c_cflag'] & ControlFlag::CRTSCTS->value)->toBe(ControlFlag::CRTSCTS->value)
        ->and($termios['c_iflag'] & (InputFlag::IXON->value | InputFlag::IXOFF->value))->toBe(0)
        ->and($termios['c_cc'][ControlChar::VMIN->value])->toBe(0)
        ->and($termios['c_cc'][ControlChar::VTIME->value])->toBe(0);
});

it('turns XON/XOFF on for software flow control', function () {
    [, $path] = ptyPair();
    $driver = new PosixUARTConnectionDriver;
    $driver->connectTo($path)->flowControl(FlowControl::SOFTWARE)->register();

    $termios = uart_tcgetattr($driver->device($path)->port);
    $xon_xoff = InputFlag::IXON->value | InputFlag::IXOFF->value;

    expect($termios['c_iflag'] & $xon_xoff)->toBe($xon_xoff)
        ->and($termios['c_cflag'] & ControlFlag::CRTSCTS->value)->toBe(0);
});

it('maps data bits and parity onto termios (a pty forces 8N1, so this checks the mapping itself)', function () {
    $factory = (new PosixUARTConnectionDriver)->connectTo('/dev/null');
    $blank = ['c_iflag' => 0, 'c_oflag' => 0, 'c_cflag' => ControlFlag::CS8->value, 'c_lflag' => 0, 'c_cc' => array_fill(0, 32, 0)];
    $parity = ControlFlag::PARENB->value | ControlFlag::PARODD->value;

    $even = $factory->dataBits(7)->parity(Parity::EVEN)->applyLine($blank);
    $odd = $factory->parity(Parity::ODD)->applyLine($blank);
    $none = $factory->dataBits(8)->parity(Parity::NONE)->applyLine([...$blank, 'c_cflag' => ControlFlag::CS7->value | $parity]);

    expect($even['c_cflag'] & ControlFlag::CS8->value)->toBe(ControlFlag::CS7->value)
        ->and($even['c_cflag'] & $parity)->toBe(ControlFlag::PARENB->value)
        ->and($odd['c_cflag'] & $parity)->toBe($parity)
        ->and($none['c_cflag'] & ControlFlag::CS8->value)->toBe(ControlFlag::CS8->value)
        ->and($none['c_cflag'] & ControlFlag::PARENB->value)->toBe(0);
});

it('refuses a device that does not exist', function () {
    expect(fn () => (new PosixUARTConnectionDriver)->connectTo('/dev/ttyNOPE'))
        ->toThrow(UARTException::class, 'UART port [/dev/ttyNOPE] could not be opened.');
});

it('never lets a child process inherit the port', function () {
    [, $path] = ptyPair();
    ptyPort($path);

    $child = proc_open(['sh', '-c', 'ls -l /proc/$$/fd'], [1 => ['pipe', 'w']], $pipes);
    $fds = stream_get_contents($pipes[1]);
    proc_close($child);

    expect($fds)->not->toContain($path);
});

it('reports a device that hung up instead of spinning on it', function () {
    // an unplugged ttyUSB polls ready and reads 0 bytes forever; a FIFO whose writer closed does exactly the same
    $fifo = sys_get_temp_dir().'/uart-hangup-'.bin2hex(random_bytes(4));
    exec('mkfifo '.escapeshellarg($fifo));
    $reader = posix_open($fifo, O_RDONLY | O_NONBLOCK);
    posix_close(posix_open($fifo, O_WRONLY));
    $port = new PosixUARTTransport('/dev/ttyGONE', new UARTPort($reader, $fifo, 9_600));

    $started = hrtime(true);

    try {
        expect(fn () => $port->read(4, 300))->toThrow(UARTException::class, 'Could not read from UART port /dev/ttyGONE.')
            ->and((hrtime(true) - $started) / 1e6)->toBeLessThan(100.0);
    } finally {
        posix_close($reader);
        unlink($fifo);
    }
});

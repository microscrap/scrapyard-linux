<?php

namespace Microscrap\ScrapyardLinux\Digital;

use GeneralPurposeIO\Contracts\Digital\DigitalEdgeEvent;
use GeneralPurposeIO\Contracts\Digital\DigitalIOException;
use GeneralPurposeIO\Contracts\Digital\SignalEdge;
use GeneralPurposeIO\Digital\DigitalInputTransport;
use Microscrap\Bindings\GPIO\DataObjects\GPIOChip;
use Microscrap\Bindings\GPIO\DataObjects\GPIOEdgeEvent;
use Microscrap\Bindings\GPIO\DataObjects\GPIOEdgeEventBuffer;
use Microscrap\Bindings\GPIO\DataObjects\GPIOLineRequest;
use Microscrap\Bindings\GPIO\Enums\EdgeEventType;
use Microscrap\Bindings\GPIO\Enums\LineValue;

class PosixDigitalInputTransport extends DigitalInputTransport
{
    /**
     * The line fd as a stream, opened the first time the loop asks. Once open it owns the fd:
     * no dup, so the kernel's close-on-exec flag holds and pool workers never inherit the line.
     * @var resource|null
     */
    private $stream = null;

    private readonly GPIOEdgeEventBuffer $buffer;

    public function __construct(
        int $pin,
        public readonly GPIOChip $chip,
        public readonly GPIOLineRequest $handle,
    ) {
        parent::__construct($pin);
        $this->buffer = gpiod_edge_event_buffer_new(self::QUEUE_DEPTH);
    }

    public function read(): bool
    {
        $this->ensureOpen();

        $value = gpiod_line_request_get_value($this->handle, $this->pin);

        if ($value !== LineValue::ACTIVE && $value !== LineValue::INACTIVE) {
            throw DigitalIOException::lineReadFailed($this->chip->path, $this->pin);
        }

        return $value === LineValue::ACTIVE;
    }

    /** Empties the kernel queue, so the level-triggered fd is quiet again before the next select. */
    protected function drainEdges(): array
    {
        $edges = [];

        while (gpiod_line_request_wait_edge_events($this->handle, 0) === 1) {
            $count = gpiod_line_request_read_edge_events($this->handle, $this->buffer, self::QUEUE_DEPTH);

            if ($count < 0) {
                throw DigitalIOException::edgeReadFailed($this->chip->path, $this->pin);
            }

            for ($i = 0; $i < $count; $i++) {
                $event = gpiod_edge_event_buffer_get_event($this->buffer, $i);

                $edges[] = new DigitalEdgeEvent(
                    $this->device,
                    $this->pin,
                    $event->event_type === EdgeEventType::RISING_EDGE ? SignalEdge::RISING : SignalEdge::FALLING,
                    $event->timestamp_ns,
                    $event->line_seqno,
                );
            }
        }

        return $edges;
    }

    protected function awaitEdges(int $timeout_ms): void
    {
        gpiod_line_request_wait_edge_events($this->handle, $timeout_ms < 0 ? -1 : $timeout_ms * 1_000_000);
    }

    protected function edgeStreams(): array
    {
        $this->stream ??= posix_fdopen($this->handle->fd, 'r')
            ?: throw DigitalIOException::edgeStreamFailed($this->chip->path, $this->pin);

        return [$this->stream];
    }

    protected function samplingInterval(): ?float
    {
        return null;
    }

    protected function release(): void
    {
        if (is_null($this->stream)) {
            gpiod_line_request_release($this->handle);

            return;
        }

        fclose($this->stream);
    }
}
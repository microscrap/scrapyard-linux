<?php

namespace Microscrap\ScrapyardLinux\Digital;

use GeneralPurposeIO\Contracts\Digital\DigitalEdgeEvent;
use GeneralPurposeIO\Contracts\Digital\SignalEdge;
use GeneralPurposeIO\Digital\DigitalInputTransport;
use Microscrap\Bindings\GPIO\DataObjects\GPIOChip;
use Microscrap\Bindings\GPIO\DataObjects\GPIOEdgeEvent;
use Microscrap\Bindings\GPIO\DataObjects\GPIOLineRequest;
use Microscrap\Bindings\GPIO\Enums\EdgeEventType;

class PosixDigitalInputTransport extends DigitalInputTransport
{
    public function __construct(
        int $pin,
        public readonly GPIOChip $chip,
        public readonly GPIOLineRequest $handle,
    ) {
        parent::__construct($pin);
    }

    public function read(): bool
    {
        return gpiod_line_request_get_value($this->handle, $this->pin)->value == 1;
    }

    public function pollEdges(bool $rising_events, bool $falling_events, int $max_events = 16): array
    {
        if (gpiod_line_request_wait_edge_events($this->handle, 0) !== 1) {
            return [];
        }

        $buffer = gpiod_edge_event_buffer_new($max_events);

        if (is_null($buffer)) {
            return [];
        }

        $count = gpiod_line_request_read_edge_events($this->handle, $buffer, $max_events);
        $events = [];

        for ($i = 0; $i < $count; $i++) {
            $event = $this->toDigitalInputEvent(gpiod_edge_event_buffer_get_event($buffer, $i), $this->pin, $rising_events, $falling_events);

            if (! is_null($event)) {
                $events[] = $event;
            }
        }

        return $events;
    }

    public function listen(int $timeout, bool $rising_events, bool $falling_events): ?DigitalEdgeEvent
    {
        $ready = $timeout > -1
            ? gpiod_line_request_wait_edge_events($this->handle, $timeout * 1_000_000)
            : gpiod_line_request_wait_edge_events($this->handle, -1);

        if ($ready !== 1) {
            return null;
        }

        $buffer = gpiod_edge_event_buffer_new(1);
        if (is_null($buffer) || gpiod_line_request_read_edge_events($this->handle, $buffer, 1) < 1) {
            return null;
        }

        return $this->toDigitalInputEvent(
            gpiod_edge_event_buffer_get_event($buffer, 0),
            $this->pin,
            $rising_events,
            $falling_events,
        );
    }

    public function close(): void
    {
        gpiod_chip_close($this->chip);
    }

    protected function toDigitalInputEvent(?GPIOEdgeEvent $edge_event, int $pin, bool $rising_events, bool $falling_events): ?DigitalEdgeEvent
    {
        if (is_null($edge_event) || $edge_event->line_offset !== $pin) {
            return null;
        }

        $edge = match ($edge_event->event_type) {
            EdgeEventType::RISING_EDGE => $rising_events ? SignalEdge::RISING : null,
            EdgeEventType::FALLING_EDGE => $falling_events ? SignalEdge::FALLING : null,
            default => null
        };

        return is_null($edge) ? null : new DigitalEdgeEvent($edge, $edge_event->timestamp_ns);
    }
}
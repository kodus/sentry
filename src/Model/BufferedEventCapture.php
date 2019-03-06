<?php

namespace Kodus\Sentry\Model;

/**
 * This class implements buffered capture of {@see Event} instances to the
 * Sentry back-end via an HTTP request.
 *
 * Buffered events must be explicitly flushed at the end of the request -
 * for example, under FCGI, you could use `register_shutdown_function` and
 * `fastcgi_finish_request` to first flush the response, and then flush any
 * buffered events to Sentry, without blocking the user.
 */
class BufferedEventCapture implements EventCapture
{
    /**
     * @var Event[]
     */
    private $events = [];

    /**
     * @var EventCapture|null
     */
    private $destination;

    /**
     * @param EventCapture $destination the destination `EventCapture` implementation to `flush()` to
     */
    public function __construct(EventCapture $destination)
    {
        $this->destination = $destination;
    }

    public function captureEvent(Event $event): void
    {
        $this->events[] = $event;
    }

    /**
     * Flush all captured Events to the destination `EventCapture` implementation.
     */
    public function flush(): void
    {
        foreach ($this->events as $event) {
            $this->destination->captureEvent($event);
        }

        $this->events = [];
    }
}

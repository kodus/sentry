<?php

namespace Kodus\Sentry\Model;

/**
 * This interface abstracts the capture of {@see Event} objects to Sentry.
 */
interface EventCapture
{
    /**
     * Capture a given {@see Event} to Sentry.
     *
     * @param Event $event
     */
    public function captureEvent(Event $event): void;
}

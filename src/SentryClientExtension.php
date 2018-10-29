<?php

namespace Kodus\Sentry;

use Kodus\Sentry\Model\Event;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * This interface provides a minimum of composability for the client - you can
 * inject implementations of this interface to the {@see SentryClient} constructor,
 * and the client will apply these prior to capture of any {@see Event}.
 *
 * @see SentryClient::__construct()
 */
interface SentryClientExtension
{
    /**
     * Applies transformations to the `$event`, and/or extracts additional information
     * from the `$exception` and `$request` instances and applies them to the `$event`.
     *
     * @param Event                       $event
     * @param Throwable                   $exception
     * @param ServerRequestInterface|null $request
     */
    public function apply(Event $event, Throwable $exception, ?ServerRequestInterface $request): void;
}

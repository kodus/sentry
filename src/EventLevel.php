<?php

namespace Kodus\Sentry;

/**
 * Sentry Event severity levels
 *
 * @see Event::$level
 */
abstract class EventLevel
{
    const FATAL   = "fatal";
    const ERROR   = "error";
    const WARNING = "warning";
    const INFO    = "info";
    const DEBUG   = "debug";
}

<?php

namespace Kodus\Sentry\Model;

/**
 * Sentry severity levels
 *
 * @see Event::$level
 * @see Breadcrumb::$level
 *
 * @link https://docs.sentry.io/clientdev/attributes/#optional-attributes
 */
abstract class Level
{
    const FATAL   = "fatal";
    const ERROR   = "error";
    const WARNING = "warning";
    const INFO    = "info";
    const DEBUG   = "debug";
}

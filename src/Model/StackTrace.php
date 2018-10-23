<?php

namespace Kodus\Sentry\Model;

use JsonSerializable;

/**
 * @link https://docs.sentry.io/clientdev/interfaces/stacktrace/
 */
class StackTrace implements JsonSerializable
{
    /**
     * @var StackFrame[]
     */
    public $frames = [];

    /**
     * @var array|null tuple like [int $start_frame, int $end_frame]
     */
    public $frames_omitted;

    /**
     * @param StackFrame[] $frames
     */
    public function __construct($frames)
    {
        $this->frames = $frames;
    }

    public function setFramesOmitted(int $start, int $end)
    {
        $this->frames_omitted = [$start, $end];
    }

    /**
     * @internal
     */
    public function jsonSerialize()
    {
        return array_filter(get_object_vars($this));
    }
}

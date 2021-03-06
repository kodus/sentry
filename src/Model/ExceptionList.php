<?php

namespace Kodus\Sentry\Model;

/**
 * @link https://docs.sentry.io/clientdev/interfaces/exception/
 */
class ExceptionList
{
    /**
     * @var ExceptionInfo[]
     */
    public $values = [];

    /**
     * @param ExceptionInfo[] $values
     */
    public function __construct($values)
    {
        $this->values = $values;
    }
}

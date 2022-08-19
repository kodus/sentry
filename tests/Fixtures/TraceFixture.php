<?php

namespace Tests\Fixtures;

use Exception;

class TraceFixture
{
    /**
     * @throws Exception
     */
    public function outer($arg)
    {
        try {
            $this->inner($arg);
        } catch (Exception $inner) {
            throw new Exception("from outer: $arg", 0, $inner);
        }
    }

    protected function inner($arg)
    {
        /**
         * @throws Exception
         */
        $closure = function () use ($arg) {
            throw new Exception("from inner: $arg");
        };

        $closure();
    }
}

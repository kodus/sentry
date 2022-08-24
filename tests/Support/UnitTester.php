<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Exception;
use Tests\Fixtures\TraceFixture;

/**
 * Inherited Methods
 * @method void wantToTest($text)
 * @method void wantTo($text)
 * @method void execute($callable)
 * @method void expectTo($prediction)
 * @method void expect($prediction)
 * @method void amGoingTo($argumentation)
 * @method void am($role)
 * @method void lookForwardTo($achieveValue)
 * @method void comment($description)
 * @method void pause($vars = [])
 *
 * @SuppressWarnings(PHPMD)
*/
class UnitTester extends \Codeception\Actor
{
    use _generated\UnitTesterActions;

    /**
     * Define custom actions here
     */

    public function createExceptionWith($arg): ?Exception
    {
        $fixture = new TraceFixture();

        try {
            $fixture->outer($arg);
        } catch (Exception $exception) {
            return $exception;
        }

        return null;
    }

    public function createEmptyClosure(): Closure
    {
        return function () {
        };
    }
}

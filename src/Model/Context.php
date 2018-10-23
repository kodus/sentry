<?php

namespace Kodus\Sentry\Model;

use JsonSerializable;

/**
 * @link https://docs.sentry.io/clientdev/interfaces/contexts/
 */
interface Context extends JsonSerializable
{
    public function getType(): string;
}

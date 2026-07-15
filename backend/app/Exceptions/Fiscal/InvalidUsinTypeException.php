<?php

namespace App\Exceptions\Fiscal;

use InvalidArgumentException;

/** Thrown when a usin_type outside UsinGenerator::SEPARATORS's known keys (SIR, SS) is requested. */
class InvalidUsinTypeException extends InvalidArgumentException
{
    public function __construct(string $usinType)
    {
        parent::__construct("Unknown usin_type '{$usinType}' - must be one of: SIR, SS.");
    }
}

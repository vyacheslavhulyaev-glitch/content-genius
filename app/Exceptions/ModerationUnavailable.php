<?php

namespace App\Exceptions;

use RuntimeException;

class ModerationUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Moderation service unavailable');
    }
}

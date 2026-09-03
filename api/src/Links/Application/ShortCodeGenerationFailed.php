<?php

declare(strict_types=1);

namespace App\Links\Application;

final class ShortCodeGenerationFailed extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Could not allocate a unique short link code after five attempts.');
    }
}

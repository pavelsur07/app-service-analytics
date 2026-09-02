<?php

declare(strict_types=1);

namespace App\Identity\Domain;

enum CaptchaUnavailableReason: string
{
    case Transport = 'transport';
    case HttpStatus = 'http_status';
    case InvalidJson = 'invalid_json';
    case UnexpectedStatus = 'unexpected_status';
}

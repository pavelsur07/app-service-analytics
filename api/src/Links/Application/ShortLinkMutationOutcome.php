<?php

declare(strict_types=1);

namespace App\Links\Application;

enum ShortLinkMutationOutcome
{
    case Saved;
    case Unchanged;
    case NotFound;
    case VersionConflict;
}

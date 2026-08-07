<?php

declare(strict_types=1);

namespace App\Shared\Ui\Response;

final readonly class AppInfoResponse
{
    public function __construct(
        public string $app,
        public string $version,
        public string $respondedAt,
        // Проба конвейера: новое поле схемы без регенерации (критерий 3).
        public string $environment = 'dev',
    ) {
    }
}

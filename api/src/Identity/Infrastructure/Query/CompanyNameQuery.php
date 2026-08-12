<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * Имя одной компании для отображения — DBAL, без гидрации Company
 * (CLAUDE.md §5). companyId первым параметром (§1); межарендаторного
 * чтения здесь нет: это собственные данные той компании, доступ
 * к которой вызывающий уже подтвердил.
 */
final readonly class CompanyNameQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function find(string $companyId): ?string
    {
        $name = $this->connection->fetchOne(
            'SELECT name FROM company WHERE id = :companyId',
            ['companyId' => $companyId],
        );

        return \is_string($name) ? $name : null;
    }
}

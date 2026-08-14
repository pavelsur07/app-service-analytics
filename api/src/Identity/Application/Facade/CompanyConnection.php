<?php

declare(strict_types=1);

namespace App\Identity\Application\Facade;

/**
 * Подключение компании глазами другого модуля: чем оно является
 * и в каком состоянии, без единого поля учётных данных.
 *
 * $createdAt строкой, а не DateTimeImmutable: значение едет прямиком
 * в JSON-контракт и ни разу не участвует в вычислениях. Разбирать дату,
 * чтобы тут же сложить её обратно, — работа без адресата.
 */
final readonly class CompanyConnection
{
    public function __construct(
        public string $id,
        public string $marketplace,
        public string $externalShopId,
        public string $state,
        public string $createdAt,
    ) {
    }
}

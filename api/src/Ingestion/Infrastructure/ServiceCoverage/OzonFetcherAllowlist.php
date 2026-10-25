<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\ServiceCoverage;

use Symfony\Component\Uid\Uuid;

/**
 * Подключения, которые ozon-fetcher загружает
 * (`OZON_FETCHER_CONNECTION_ALLOWLIST`, тот же список, что у сервиса).
 * По нему экран «Покрытие GO» сравнивает сервис с загрузкой PHP:
 * сервис считает только эти кабинеты, и сравнение со всеми кабинетами
 * PHP показывало бы расхождение там, где его нет.
 */
final readonly class OzonFetcherAllowlist
{
    /** @var list<string> */
    public array $accountIds;

    public function __construct(string $allowlist)
    {
        $ids = [];
        foreach (explode(',', $allowlist) as $entry) {
            $entry = trim($entry);
            if ('' === $entry) {
                continue;
            }
            // Сервис с таким списком не стартует; здесь — та же строгость,
            // а не молча урезанное сравнение.
            if (!Uuid::isValid($entry)) {
                throw new \UnexpectedValueException('OZON_FETCHER_CONNECTION_ALLOWLIST entries must be marketplace_account_id UUIDs.');
            }
            $ids[] = strtolower($entry);
        }
        $this->accountIds = array_values(array_unique($ids));
    }
}

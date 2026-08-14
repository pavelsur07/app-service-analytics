<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use App\Ingestion\Domain\MarketplaceReportType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;

/**
 * Подключения всех компаний, по которым raw-слой пополнялся недавно.
 *
 * Межарендаторное чтение для операционной системной задачи (CLAUDE.md §1):
 * контроль свежести по определению спрашивает «а у всех ли идут данные»,
 * и companyId у такого вопроса нет. Приём тот же, что у
 * ActiveOzonAccountsQuery, — отдельный DBAL-запрос вне репозитория,
 * а не метод, снимающий скоуп с company-scoped интерфейса. Deptrac держит
 * класс в отдельном узком слое (IngestionOperationalQuery): IngestionUi
 * имеет широкий доступ к IngestionInfrastructure, и без выделения любой
 * контроллер мог бы прочитать подключения чужих компаний.
 *
 * Свежесть меряется raw-слоем, а не фактами. У продавца бывает день
 * без единого заказа — фактов не появится, и «нет новых фактов» назвало бы
 * исправную синхронизацию сломанной. Raw-документ приходит каждый день
 * при любом объёме продаж: period входит в естественный ключ (ADR-006),
 * поэтому новый день даёт новую строку даже при побайтово том же ответе
 * площадки. Пустой ответ — тоже ответ, и он доказывает, что связь с Ozon
 * есть, ключи живы и воркер работает.
 *
 * Тип отчёта в условии обязателен. Raw-слой общий для всех выгрузок
 * подключения, и без фильтра исправная синхронизация каталога — она идёт
 * тем же тиком — обновляла бы отметку за вставшую синхронизацию продаж.
 * Сторож молчал бы именно тогда, когда должен кричать.
 *
 * Обратная сторона названа прямо: вставший каталог этим сторожем
 * не отслеживается. Продажи — обещание продукта, каталог — вспомогательный
 * список для оверлея, и отдельная тревога по нему пока не окупается.
 */
final readonly class RecentlyIngestedAccountsQuery
{
    /**
     * Тот же защитный потолок, что у ActiveOzonAccountsQuery, — свежих
     * подключений не может быть больше, чем активных. Константа своя,
     * а не импортированная: Ingestion не ходит в Identity мимо Facade.
     */
    public const int MAX_RESULTS = 200;

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function build(\DateTimeImmutable $since): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('company_id', 'marketplace_account_id')
            ->from('marketplace_raw_document')
            // Индекс (received_at, company_id, marketplace_account_id) —
            // порядок столбцов ради index-only scan: диапазон по времени
            // ведущим столбцом, а группировка забирает остальные два
            // прямо из индекса, не заглядывая в кучу. Без него сторож
            // раз в час читал бы всю таблицу raw-документов, а она растёт
            // с каждым днём работы каждого подключения.
            ->where('received_at >= :since')
            ->andWhere('report_type = :reportType')
            ->setParameter('since', $since, Types::DATETIME_IMMUTABLE)
            ->setParameter('reportType', MarketplaceReportType::OzonPostingFboList)
            ->groupBy('company_id')
            ->addGroupBy('marketplace_account_id')
            ->setMaxResults(self::MAX_RESULTS + 1);
    }

    /**
     * Ключ пары «компания + подключение»: с ним сравнение свежих
     * и активных — пересечение множеств, а не запрос на каждое
     * подключение в цикле (CLAUDE.md §6).
     */
    public static function key(string $companyId, string $marketplaceAccountId): string
    {
        return $companyId.':'.$marketplaceAccountId;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): RecentlyIngestedAccountRow
    {
        return new RecentlyIngestedAccountRow(
            companyId: self::stringValue($row['company_id']),
            marketplaceAccountId: self::stringValue($row['marketplace_account_id']),
        );
    }

    private static function stringValue(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('Expected a string value in a raw document row.');
        }

        return $value;
    }
}

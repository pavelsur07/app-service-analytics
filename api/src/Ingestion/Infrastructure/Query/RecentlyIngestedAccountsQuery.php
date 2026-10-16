<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;

/**
 * Подключения всех компаний, выгрузки которых недавно загрузились
 * успешно — по отметке загрузки дня (ADR-038).
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
 * Свежесть меряется загрузкой, а не фактами. У продавца бывает день
 * без единого заказа — фактов не появится, и «нет новых фактов» назвало бы
 * исправную синхронизацию сломанной. Признак — `completed_at` отметки:
 * его ставит каждый прогон, записавший все страницы, при любом объёме
 * продаж, и пустой ответ площадки — тоже успех: связь с Ozon есть, ключи
 * живы, воркер работает. Raw-документа для этого мало: он сохраняется
 * до разбора, и выгрузка, которая каждый тик получает ответ и отказывает
 * на разборе, по raw выглядела бы живой (ADR-038).
 *
 * Тип отчёта в условии обязателен. Отметки общие для всех выгрузок
 * подключения, и без фильтра исправная синхронизация каталога — она идёт
 * тем же тиком — обновляла бы свежесть за вставшую синхронизацию продаж.
 * Сторож молчал бы именно тогда, когда должен кричать.
 *
 * Свежесть считается отдельно по каждому типу, а не одной отметкой
 * на подключение: у выгрузок общий тик, но не общая судьба. Вставшая
 * загрузка расходов при исправных продажах — рабочий экран с завышенной
 * маржой, и «по подключению данные идут» это не ловит.
 *
 * Обратная сторона названа прямо: вставший каталог этим сторожем
 * не отслеживается. Продажи и расходы — то, из чего складывается цифра
 * на экране; каталог — вспомогательный список для оверлея, и отдельная
 * тревога по нему пока не окупается.
 */
final readonly class RecentlyIngestedAccountsQuery
{
    /**
     * Тот же защитный потолок, что у ActiveOzonAccountsQuery, — свежих
     * подключений не может быть больше, чем активных. Константа своя,
     * а не импортированная: Ingestion не ходит в Identity мимо Facade.
     *
     * Строк бывает больше, чем подключений: по одной на каждый
     * отслеживаемый тип отчёта. Потолок выборки считается от их числа,
     * а не задан вторым магическим числом рядом.
     */
    public const int MAX_ACCOUNTS = 200;

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param non-empty-list<string> $reportTypes
     */
    public function build(\DateTimeImmutable $since, array $reportTypes): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('company_id', 'marketplace_account_id', 'report_type')
            ->from('marketplace_day_load')
            // Индекс (completed_at, company_id, marketplace_account_id,
            // report_type) — порядок столбцов ради index-only scan:
            // диапазон по времени ведущим столбцом, а фильтр по типу
            // и группировка забирают остальные три прямо из индекса,
            // не заглядывая в кучу. Без него сторож раз в час читал бы
            // всю таблицу отметок, а она растёт с каждым днём работы
            // каждого подключения.
            ->where('completed_at >= :since')
            ->andWhere('report_type IN (:reportTypes)')
            ->setParameter('since', $since, Types::DATETIME_IMMUTABLE)
            ->setParameter('reportTypes', $reportTypes, ArrayParameterType::STRING)
            ->groupBy('company_id')
            ->addGroupBy('marketplace_account_id')
            ->addGroupBy('report_type')
            ->setMaxResults(self::MAX_ACCOUNTS * \count($reportTypes) + 1);
    }

    /**
     * Ключ тройки «компания + подключение + тип отчёта»: с ним сравнение
     * свежих и активных — пересечение множеств, а не запрос на каждое
     * подключение в цикле (CLAUDE.md §6).
     *
     * Тип входит в ключ, потому что свежесть у выгрузок раздельная.
     * Без него отметка одного типа закрывала бы другой — та самая
     * маскировка, ради которой в запросе стоит фильтр.
     */
    public static function key(string $companyId, string $marketplaceAccountId, string $reportType): string
    {
        return $companyId.':'.$marketplaceAccountId.':'.$reportType;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): RecentlyIngestedAccountRow
    {
        return new RecentlyIngestedAccountRow(
            companyId: self::stringValue($row['company_id']),
            marketplaceAccountId: self::stringValue($row['marketplace_account_id']),
            reportType: self::stringValue($row['report_type']),
        );
    }

    private static function stringValue(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('Expected a string value in a day load row.');
        }

        return $value;
    }
}

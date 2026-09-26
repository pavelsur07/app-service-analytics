<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Кластеры Ozon FBO в sales_fact: откуда отгружено (financial_data.cluster_from)
 * и куда доставляется (financial_data.cluster_to). Для отчёта «Локализация»:
 * равенство кластеров — локальная продажа. Индексов нет — отчёт отбирает
 * строки по существующему (company_id, business_date).
 *
 * Две необязательные колонки со значением по умолчанию NULL — изменение
 * метаданных таблицы, без перезаписи строк и долгой блокировки записи.
 * Совместима со старым кодом: он колонок не знает и не читает.
 *
 * Порядок после применения — тот же, что у Version20260926060431:
 * сразу бэкфилл app:ingestion:backfill-ozon-posting-statuses по каждому
 * Ozon-подключению с --from не позже первого raw строк окна пересчёта
 * и --to = сегодня. Он заполняет кластеры и переводит row_hash на формулу
 * с кластерами, и синхронизация не переписывает окно как мнимую
 * корректировку задним числом (ADR-006).
 * Проверка — сразу после бэкфилла. Она находит строки, которые бэкфилл
 * не перевёл (снимок не нашёлся в raw диапазона: расширить --from), но
 * только пока их не переписала синхронизация: тик, пришедшийся на
 * бэкфилл, или сообщения, поставленные в очередь до него, записывают
 * хэш по новой формуле сами, и такие строки проверка уже не увидит.
 * Этот остаточный риск принят сознательно: его цена — разовое обновление
 * last_updated_at, который сегодня никто не читает. Смотрится по БД, не по
 * выводу команды. Окно — ночной рескан отправлений: postingRescanDays = 30
 * дат по Europe/Moscow (DispatchActiveOzonSyncsAction). Ожидаемый
 * результат — пусто.
 *
 *   SELECT company_id, marketplace_account_id, source_row_id FROM sales_fact
 *   WHERE business_date BETWEEN (now() AT TIME ZONE 'Europe/Moscow')::date - 29
 *                           AND (now() AT TIME ZONE 'Europe/Moscow')::date
 *     AND row_hash <> encode(sha256(convert_to(concat_ws('|',
 *         status, quantity, amount_minor, commission_amount_minor,
 *         COALESCE(posting_number, '<null>'), COALESCE(order_number, '<null>'),
 *         COALESCE(warehouse_id::text, '<null>'), COALESCE(warehouse_name, '<null>'),
 *         COALESCE(delivery_city, '<null>'),
 *         COALESCE(cluster_from, '<null>'), COALESCE(cluster_to, '<null>')), 'UTF8')), 'hex')
 *   LIMIT 50;
 *
 * down() рабочий: колонки восстанавливаются из raw той же командой.
 */
final class Version20260926075235 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Ozon FBO clusters (from, to) to sales_fact';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sales_fact ADD cluster_from TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE sales_fact ADD cluster_to TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sales_fact DROP cluster_from');
        $this->addSql('ALTER TABLE sales_fact DROP cluster_to');
    }
}

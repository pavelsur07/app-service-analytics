<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Момент заказа Ozon FBO (in_process_at, UTC) в sales_fact — точка отсчёта
 * отчёта «Скорость доставки» (docs/plan/ozon-delivery-speed-report.md,
 * решение 1). Индекса нет: отчёт отбирает строки по существующему
 * (company_id, business_date).
 *
 * Необязательная колонка со значением по умолчанию NULL — изменение
 * метаданных таблицы, без перезаписи строк и долгой блокировки записи.
 * Совместима со старым кодом: он колонку не знает и не читает.
 * В row_hash не входит (момент заказа неизменен), поэтому перевода хэшей,
 * как у Version20260926075235, не нужно, и гонка бэкфилла с синхронизацией
 * ничего не портит: обе стороны пишут одно и то же значение.
 *
 * После применения — бэкфилл app:ingestion:backfill-ozon-posting-statuses
 * по каждому Ozon-подключению по всей истории: --from = дата первого raw
 * ozon_posting_fbo_list подключения, --to = сегодня. Проверка — пусто:
 *
 *   SELECT company_id, marketplace_account_id, source_row_id FROM sales_fact
 *   WHERE business_date >= (now() AT TIME ZONE 'Europe/Moscow')::date - 119
 *     AND ordered_at IS NULL
 *   LIMIT 50;
 *
 * down() рабочий: колонка восстанавливается из raw той же командой.
 */
final class Version20260926092038 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Ozon FBO order moment (ordered_at) to sales_fact';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sales_fact ADD ordered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sales_fact DROP ordered_at');
    }
}

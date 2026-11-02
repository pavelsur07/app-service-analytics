<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Frozen project requirements and versioned run provenance (ADR-050).
 */
final class Version20261102033959 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stage 6: frozen requirements and v2 progress projection';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product_research_ozon_project ADD requirements JSONB DEFAULT \'[]\' NOT NULL');
        $this->addSql(<<<'SQL'
            UPDATE product_research_ozon_project p SET requirements=(
              SELECT jsonb_agg(jsonb_build_object('month',to_char(d,'YYYY-MM'),'date_from',d::date::text,
                'date_to',last_day::text,'days',last_day-d::date+1,'partial',last_day<(d+interval '1 month - 1 day')::date) ORDER BY d)
              FROM (SELECT DISTINCT d, CASE WHEN p.include_current_month AND date_trunc('month',p.updated_at AT TIME ZONE 'Europe/Moscow')=d THEN LEAST((d+interval '1 month - 1 day')::date,(p.updated_at AT TIME ZONE 'Europe/Moscow')::date-1) ELSE (d+interval '1 month - 1 day')::date END last_day
                FROM product_research_ozon_period r CROSS JOIN LATERAL generate_series(r.from_month,r.to_month,interval '1 month') d
                WHERE r.company_id=p.company_id AND r.project_id=p.id) months)
            SQL);
        $this->addSql('ALTER TABLE product_research_ozon_run ADD progress_format INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE product_research_ozon_run_month ADD origin VARCHAR(8) DEFAULT NULL');
        $this->addSql('ALTER TABLE product_research_ozon_run_month ADD collected_at TIMESTAMP(6) WITH TIME ZONE');
        $this->addSql('ALTER TABLE product_research_ozon_run_month ADD used_at TIMESTAMP(6) WITH TIME ZONE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product_research_ozon_project DROP requirements');
        $this->addSql('ALTER TABLE product_research_ozon_run DROP progress_format');
        $this->addSql('ALTER TABLE product_research_ozon_run_month DROP origin');
        $this->addSql('ALTER TABLE product_research_ozon_run_month DROP collected_at');
        $this->addSql('ALTER TABLE product_research_ozon_run_month DROP used_at');
    }
}

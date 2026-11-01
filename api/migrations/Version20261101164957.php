<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Durable research run intent, frozen months and delivery journal (ADR-050).
 */
final class Version20261101164957 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stage 5: research run outbox and monthly progress';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE product_research_ozon_run (company_id UUID NOT NULL, id UUID NOT NULL, source_id UUID NOT NULL, request_id UUID NOT NULL, input_hash CHAR(64) NOT NULL, payload TEXT NOT NULL, payload_hash CHAR(64) NOT NULL, created_by_user_id UUID NOT NULL, created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, status VARCHAR(16) NOT NULL, revision INT NOT NULL, progress_hash CHAR(64) NOT NULL, delivery_attempts INT NOT NULL, next_delivery_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, delivered_at TIMESTAMP(6) WITH TIME ZONE, next_poll_at TIMESTAMP(6) WITH TIME ZONE, finished_at TIMESTAMP(6) WITH TIME ZONE, lease_id UUID DEFAULT NULL, lease_until TIMESTAMP(6) WITH TIME ZONE, error_code VARCHAR(64) DEFAULT NULL, project_id UUID NOT NULL, PRIMARY KEY (company_id, id))');
        $this->addSql('CREATE INDEX IDX_8E769107979B1AD6166D1F9C ON product_research_ozon_run (company_id, project_id)');
        $this->addSql('CREATE INDEX idx_research_run_author ON product_research_ozon_run (company_id, created_by_user_id)');
        $this->addSql('CREATE INDEX idx_research_run_project ON product_research_ozon_run (company_id, project_id, id)');
        $this->addSql('CREATE INDEX idx_research_run_delivery ON product_research_ozon_run (company_id, next_delivery_at, id) WHERE (delivered_at IS NULL)');
        $this->addSql('CREATE INDEX idx_research_run_poll ON product_research_ozon_run (company_id, next_poll_at, id) WHERE ((delivered_at IS NOT NULL) AND (finished_at IS NULL))');
        $this->addSql('CREATE UNIQUE INDEX uq_research_run_request ON product_research_ozon_run (company_id, project_id, request_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_research_active_run ON product_research_ozon_run (company_id, project_id) WHERE (finished_at IS NULL)');
        $this->addSql('CREATE TABLE product_research_ozon_run_month (id UUID NOT NULL, month VARCHAR(7) NOT NULL, date_from DATE NOT NULL, date_to DATE NOT NULL, days INT NOT NULL, partial BOOLEAN NOT NULL, status VARCHAR(16) NOT NULL, snapshot_id UUID DEFAULT NULL, row_count INT DEFAULT NULL, error_code VARCHAR(32) DEFAULT NULL, company_id UUID NOT NULL, run_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_50120B48979B1AD684E3FEC4 ON product_research_ozon_run_month (company_id, run_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_research_run_month ON product_research_ozon_run_month (company_id, run_id, month)');
        $this->addSql('ALTER TABLE product_research_ozon_run ADD CONSTRAINT FK_8E769107979B1AD6166D1F9C FOREIGN KEY (company_id, project_id) REFERENCES product_research_ozon_project (company_id, id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE product_research_ozon_run_month ADD CONSTRAINT FK_50120B48979B1AD684E3FEC4 FOREIGN KEY (company_id, run_id) REFERENCES product_research_ozon_run (company_id, id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE product_research_ozon_project ADD last_run_id UUID DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product_research_ozon_run DROP CONSTRAINT FK_8E769107979B1AD6166D1F9C');
        $this->addSql('ALTER TABLE product_research_ozon_run_month DROP CONSTRAINT FK_50120B48979B1AD684E3FEC4');
        $this->addSql('DROP TABLE product_research_ozon_run');
        $this->addSql('DROP TABLE product_research_ozon_run_month');
        $this->addSql('ALTER TABLE product_research_ozon_project DROP last_run_id');
    }
}

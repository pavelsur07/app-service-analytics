<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261108110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ADR-052: company-scoped observe event journal for seller Tabs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE advertising_observation_event (company_id UUID NOT NULL, id UUID NOT NULL, policy_id UUID NOT NULL, account_id UUID NOT NULL, campaign_id VARCHAR(20) NOT NULL, version INT NOT NULL, event_key VARCHAR(64) NOT NULL, kind VARCHAR(24) NOT NULL, actor_id UUID DEFAULT NULL, action VARCHAR(16) NOT NULL, reason VARCHAR(64) NOT NULL, limit_minor BIGINT DEFAULT NULL, spend_minor BIGINT DEFAULT NULL, difference_minor BIGINT DEFAULT NULL, currency CHAR(3) NOT NULL, details JSON NOT NULL, occurred_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(company_id, id))');
        $this->addSql('CREATE UNIQUE INDEX uq_ads_event_key ON advertising_observation_event(company_id, policy_id, event_key)');
        $this->addSql('CREATE INDEX idx_ads_event_actor ON advertising_observation_event(company_id, actor_id)');
        $this->addSql("ALTER TABLE advertising_observation_event ADD CONSTRAINT chk_ads_event_money CHECK (currency='RUB' AND (limit_minor IS NULL OR limit_minor BETWEEN 1 AND 9007199254740991) AND (spend_minor IS NULL OR spend_minor BETWEEN 0 AND 9007199254740991) AND (difference_minor IS NULL OR difference_minor BETWEEN 0 AND 9007199254740991))");
        $this->addSql('CREATE INDEX idx_ads_event_account ON advertising_observation_event(company_id, account_id, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE advertising_observation_event');
    }
}

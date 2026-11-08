<?php

declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261108074000 extends AbstractMigration
{
    public function getDescription(): string { return 'ADR-052: observe policies and latest confirmed projection, no advertising commands'; }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE advertising_observation_policy (company_id UUID NOT NULL, id UUID NOT NULL, account_id UUID NOT NULL, campaign_id VARCHAR(20) NOT NULL, daily_limit_minor BIGINT NOT NULL, currency CHAR(3) NOT NULL, start_time VARCHAR(5) NOT NULL, stop_time VARCHAR(5) NOT NULL, poll_seconds INT NOT NULL, enabled BOOLEAN NOT NULL, version INT DEFAULT 1 NOT NULL, updated_by UUID NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(company_id,id))');
        $this->addSql('CREATE UNIQUE INDEX uq_ads_policy_campaign ON advertising_observation_policy(company_id,account_id,campaign_id)');
        $this->addSql('CREATE INDEX idx_ads_policy_delivery ON advertising_observation_policy(id)');
        $this->addSql('CREATE INDEX idx_ads_policy_actor ON advertising_observation_policy(company_id,updated_by)');
        $this->addSql("ALTER TABLE advertising_observation_policy ADD CONSTRAINT chk_ads_policy CHECK (currency='RUB' AND daily_limit_minor BETWEEN 1 AND 9007199254740991 AND poll_seconds BETWEEN 600 AND 86400 AND start_time<stop_time)");
        $this->addSql('CREATE TABLE advertising_observation_state (company_id UUID NOT NULL, policy_id UUID NOT NULL, account_id UUID NOT NULL, applied_version INT NOT NULL, day DATE NOT NULL, spend_minor BIGINT DEFAULT NULL, difference_minor BIGINT DEFAULT NULL, currency CHAR(3) NOT NULL, action VARCHAR(16) NOT NULL, reason VARCHAR(64) NOT NULL, latched BOOLEAN NOT NULL, evaluated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(company_id,policy_id))');
        $this->addSql('CREATE INDEX idx_ads_state_account ON advertising_observation_state(company_id,account_id)');
        $this->addSql("ALTER TABLE advertising_observation_state ADD CONSTRAINT chk_ads_state CHECK (currency='RUB' AND (spend_minor IS NULL OR spend_minor>=0) AND (difference_minor IS NULL OR difference_minor>=0) AND action IN ('would_run','would_stop','blocked'))");
    }
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE advertising_observation_state');
        $this->addSql('DROP TABLE advertising_observation_policy');
    }
}

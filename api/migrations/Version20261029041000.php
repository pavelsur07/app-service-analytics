<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029041000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Immutable scoped net forecast publication anchors (ADR-049).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE net_forecast_artifact (company_id UUID NOT NULL, marketplace_account_id UUID NOT NULL, kind VARCHAR(5) NOT NULL, request_id UUID NOT NULL, request_hash CHAR(64) NOT NULL, attempt_id UUID NOT NULL, artifact_hash CHAR(64) NOT NULL, manifest_byte_size INT NOT NULL, published_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(company_id, marketplace_account_id, kind, request_id))');
    }

    public function down(Schema $schema): void
    {
        // Dev rollback only. Production app rollback retains this additive table.
        $this->addSql('DROP TABLE net_forecast_artifact');
    }
}

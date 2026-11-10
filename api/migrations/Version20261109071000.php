<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261109071000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Performance API application-call counts; operational aggregate, not wire quota ledger';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE performance_http_logical_count (bucket_start TIMESTAMP(0) WITH TIME ZONE NOT NULL, operation VARCHAR(32) NOT NULL, attempts BIGINT NOT NULL, PRIMARY KEY(bucket_start, operation))');
        $this->addSql('ALTER TABLE performance_http_logical_count ADD CONSTRAINT chk_perf_logical_attempts CHECK (attempts > 0)');
    }

    public function down(Schema $schema): void
    {
        // Removal invalidates collected coverage. Do not use the removed history
        // as proof of an empty quota window on a later rollout.
        $this->addSql('DROP TABLE performance_http_logical_count');
    }
}

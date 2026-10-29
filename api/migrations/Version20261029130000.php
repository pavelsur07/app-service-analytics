<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen immutable artifact kind for private net forecast quality evidence; retain existing primary key.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE net_forecast_artifact ALTER COLUMN kind TYPE VARCHAR(20)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf((bool) $this->connection->fetchOne('SELECT EXISTS (SELECT 1 FROM net_forecast_artifact WHERE length(kind) > 5 LIMIT 1)'), 'Quality evidence exists: rollback the application while retaining the widened column. Never delete evidence to narrow kind.');
        $this->addSql('ALTER TABLE net_forecast_artifact ALTER COLUMN kind TYPE VARCHAR(5)');
    }
}

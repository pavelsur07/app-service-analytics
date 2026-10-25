<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Последняя сводка покрытия сервиса вне монолита для экрана «Покрытие GO»
 * (ADR-043 п. 6): одна строка на сервис, пишет процесс снятия по расписанию.
 *
 * Данных компаний в таблице нет — только счётчики по ресурсу и периоду,
 * поэтому и company_id нет. Новая таблица, факт-таблиц не касается.
 *
 * down() рабочий: таблица удаляется, сводка снимается заново первым же
 * тиком процесса.
 */
final class Version20261025165328 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add service coverage capture for the admin coverage screen (ADR-043)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE service_coverage_capture (service VARCHAR(64) NOT NULL, state VARCHAR(16) NOT NULL, attempted_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, succeeded_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, payload JSONB DEFAULT NULL, PRIMARY KEY (service))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE service_coverage_capture');
    }
}

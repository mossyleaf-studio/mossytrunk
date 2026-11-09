<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261109140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The release template becomes the planning settings with a default ready-by margin, and an event may override it';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE release_template RENAME TO planning_settings');
        $this->addSql('ALTER TABLE planning_settings RENAME CONSTRAINT release_template_pkey TO planning_settings_pkey');
        $this->addSql('ALTER INDEX uniq_cc60c4e282d40a1f RENAME TO UNIQ_D240F66882D40A1F');
        $this->addSql('ALTER TABLE planning_settings RENAME CONSTRAINT fk_cc60c4e282d40a1f TO FK_D240F66882D40A1F');
        $this->addSql('ALTER TABLE planning_settings ADD ready_days_before INT DEFAULT 3 NOT NULL');
        $this->addSql('ALTER TABLE event ADD ready_days_before INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event DROP ready_days_before');
        $this->addSql('ALTER TABLE planning_settings DROP ready_days_before');
        $this->addSql('ALTER TABLE planning_settings RENAME CONSTRAINT FK_D240F66882D40A1F TO fk_cc60c4e282d40a1f');
        $this->addSql('ALTER INDEX UNIQ_D240F66882D40A1F RENAME TO uniq_cc60c4e282d40a1f');
        $this->addSql('ALTER TABLE planning_settings RENAME CONSTRAINT planning_settings_pkey TO release_template_pkey');
        $this->addSql('ALTER TABLE planning_settings RENAME TO release_template');
    }
}

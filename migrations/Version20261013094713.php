<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013094713 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Donations received at an event';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE event_donation (id UUID NOT NULL, label VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, amount_cents INT NOT NULL, event_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_7709687071F7E88B ON event_donation (event_id)');
        $this->addSql('ALTER TABLE event_donation ADD CONSTRAINT FK_7709687071F7E88B FOREIGN KEY (event_id) REFERENCES event (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event_donation DROP CONSTRAINT FK_7709687071F7E88B');
        $this->addSql('DROP TABLE event_donation');
    }
}

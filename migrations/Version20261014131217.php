<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014131217 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Manual discount rules, chosen on an order';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE discount_rule ADD manual BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE discount_rule DROP manual');
    }
}

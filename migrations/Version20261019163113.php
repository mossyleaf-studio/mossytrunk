<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019163113 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'An order cancelled on its service is marked cancelled';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "order" ADD cancelled BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "order" DROP cancelled');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018171551 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Comment on an order';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "order" ADD note TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "order" DROP note');
    }
}

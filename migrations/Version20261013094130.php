<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013094130 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Discount type targets can be narrowed to a design collection';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE discount_condition_target ADD collection_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE discount_condition_target ADD CONSTRAINT FK_DED870C7514956FD FOREIGN KEY (collection_id) REFERENCES design_collection (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_DED870C7514956FD ON discount_condition_target (collection_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE discount_condition_target DROP CONSTRAINT FK_DED870C7514956FD');
        $this->addSql('DROP INDEX IDX_DED870C7514956FD');
        $this->addSql('ALTER TABLE discount_condition_target DROP collection_id');
    }
}

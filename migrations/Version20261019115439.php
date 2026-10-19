<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019115439 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'An external item links to one or more products, each with a quantity';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE external_item_component (id UUID NOT NULL, product_id UUID NOT NULL, variant VARCHAR(255) DEFAULT NULL, quantity INT NOT NULL, external_item_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX external_item_component_product_idx ON external_item_component (product_id)');
        $this->addSql('CREATE INDEX IDX_544DAF41CBCD8B1D ON external_item_component (external_item_id)');
        $this->addSql('ALTER TABLE external_item_component ADD CONSTRAINT FK_544DAF41CBCD8B1D FOREIGN KEY (external_item_id) REFERENCES external_item (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('INSERT INTO external_item_component (id, product_id, variant, quantity, external_item_id) SELECT gen_random_uuid(), product_id, variant, 1, id FROM external_item WHERE product_id IS NOT NULL');
        $this->addSql('DROP INDEX external_item_workspace_product_idx');
        $this->addSql('ALTER TABLE external_item DROP product_id');
        $this->addSql('ALTER TABLE external_item DROP variant');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE external_item ADD product_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE external_item ADD variant VARCHAR(255) DEFAULT NULL');
        $this->addSql('UPDATE external_item SET product_id = first.product_id, variant = first.variant FROM (SELECT DISTINCT ON (external_item_id) external_item_id, product_id, variant FROM external_item_component ORDER BY external_item_id, id) first WHERE first.external_item_id = external_item.id');
        $this->addSql('ALTER TABLE external_item_component DROP CONSTRAINT FK_544DAF41CBCD8B1D');
        $this->addSql('DROP TABLE external_item_component');
        $this->addSql('CREATE INDEX external_item_workspace_product_idx ON external_item (workspace_id, product_id)');
    }
}

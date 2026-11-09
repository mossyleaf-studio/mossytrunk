<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261109104523 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A supplier gets an optional order lead time; a restock plans an order from a supplier by a day or an event';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE restock (id UUID NOT NULL, note VARCHAR(255) DEFAULT NULL, day DATE DEFAULT NULL, lead_days INT NOT NULL, ordered_on DATE DEFAULT NULL, workspace_id UUID NOT NULL, supplier_id UUID NOT NULL, event_id UUID DEFAULT NULL, supplier_order_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_33B621E882D40A1F ON restock (workspace_id)');
        $this->addSql('CREATE INDEX IDX_33B621E82ADD6D8C ON restock (supplier_id)');
        $this->addSql('CREATE INDEX IDX_33B621E871F7E88B ON restock (event_id)');
        $this->addSql('CREATE INDEX IDX_33B621E81605B9 ON restock (supplier_order_id)');
        $this->addSql('ALTER TABLE restock ADD CONSTRAINT FK_33B621E882D40A1F FOREIGN KEY (workspace_id) REFERENCES workspace (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE restock ADD CONSTRAINT FK_33B621E82ADD6D8C FOREIGN KEY (supplier_id) REFERENCES supplier (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE restock ADD CONSTRAINT FK_33B621E871F7E88B FOREIGN KEY (event_id) REFERENCES event (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE restock ADD CONSTRAINT FK_33B621E81605B9 FOREIGN KEY (supplier_order_id) REFERENCES supplier_order (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE supplier ADD lead_days INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE restock DROP CONSTRAINT FK_33B621E882D40A1F');
        $this->addSql('ALTER TABLE restock DROP CONSTRAINT FK_33B621E82ADD6D8C');
        $this->addSql('ALTER TABLE restock DROP CONSTRAINT FK_33B621E871F7E88B');
        $this->addSql('ALTER TABLE restock DROP CONSTRAINT FK_33B621E81605B9');
        $this->addSql('DROP TABLE restock');
        $this->addSql('ALTER TABLE supplier DROP lead_days');
    }
}

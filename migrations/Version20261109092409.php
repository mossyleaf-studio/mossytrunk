<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261109092409 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A collection or a design gets a release day or event with its retro-planning steps, pre-filled from the workspace template';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE release_plan (id UUID NOT NULL, day DATE DEFAULT NULL, steps JSON NOT NULL, workspace_id UUID NOT NULL, collection_id UUID DEFAULT NULL, design_id UUID DEFAULT NULL, event_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_90ADEB6A514956FD ON release_plan (collection_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_90ADEB6AE41DC9B2 ON release_plan (design_id)');
        $this->addSql('CREATE INDEX IDX_90ADEB6A82D40A1F ON release_plan (workspace_id)');
        $this->addSql('CREATE INDEX IDX_90ADEB6A71F7E88B ON release_plan (event_id)');
        $this->addSql('CREATE TABLE release_template (id UUID NOT NULL, steps JSON NOT NULL, workspace_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_CC60C4E282D40A1F ON release_template (workspace_id)');
        $this->addSql('ALTER TABLE release_plan ADD CONSTRAINT FK_90ADEB6A82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspace (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE release_plan ADD CONSTRAINT FK_90ADEB6A514956FD FOREIGN KEY (collection_id) REFERENCES design_collection (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE release_plan ADD CONSTRAINT FK_90ADEB6AE41DC9B2 FOREIGN KEY (design_id) REFERENCES design (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE release_plan ADD CONSTRAINT FK_90ADEB6A71F7E88B FOREIGN KEY (event_id) REFERENCES event (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE release_template ADD CONSTRAINT FK_CC60C4E282D40A1F FOREIGN KEY (workspace_id) REFERENCES workspace (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE release_plan DROP CONSTRAINT FK_90ADEB6A82D40A1F');
        $this->addSql('ALTER TABLE release_plan DROP CONSTRAINT FK_90ADEB6A514956FD');
        $this->addSql('ALTER TABLE release_plan DROP CONSTRAINT FK_90ADEB6AE41DC9B2');
        $this->addSql('ALTER TABLE release_plan DROP CONSTRAINT FK_90ADEB6A71F7E88B');
        $this->addSql('ALTER TABLE release_template DROP CONSTRAINT FK_CC60C4E282D40A1F');
        $this->addSql('DROP TABLE release_plan');
        $this->addSql('DROP TABLE release_template');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121102029 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the CHECK constraints, whose rules the code that builds the rows now guards';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE briefing_source_snapshot DROP CONSTRAINT briefing_source_snapshot_source_check');
        $this->addSql('ALTER TABLE briefing_source_snapshot DROP CONSTRAINT briefing_source_snapshot_scope_key_check');
        $this->addSql('ALTER TABLE briefing_source_snapshot DROP CONSTRAINT briefing_source_snapshot_date_check');
        $this->addSql('ALTER TABLE briefing_source_snapshot DROP CONSTRAINT briefing_source_snapshot_status_check');
        $this->addSql('ALTER TABLE briefing_source_snapshot DROP CONSTRAINT briefing_source_snapshot_error_check');
        $this->addSql('ALTER TABLE briefing_source_snapshot DROP CONSTRAINT briefing_source_snapshot_failure_check');
        $this->addSql('ALTER TABLE briefing_source_snapshot DROP CONSTRAINT briefing_source_snapshot_ok_payload_check');
        $this->addSql('ALTER TABLE briefing_source_snapshot DROP CONSTRAINT briefing_source_snapshot_fetched_check');
        $this->addSql('ALTER TABLE briefing_source_snapshot DROP CONSTRAINT briefing_source_snapshot_payload_check');
        $this->addSql('ALTER TABLE briefing_discovery DROP CONSTRAINT briefing_discovery_id_check');
        $this->addSql('ALTER TABLE briefing_discovery DROP CONSTRAINT briefing_discovery_type_check');
        $this->addSql('ALTER TABLE briefing_discovery DROP CONSTRAINT briefing_discovery_key_check');
        $this->addSql('ALTER TABLE briefing_discovery DROP CONSTRAINT briefing_discovery_date_check');
        $this->addSql('ALTER TABLE briefing_discovery DROP CONSTRAINT briefing_discovery_title_check');
        $this->addSql('ALTER TABLE briefing_discovery DROP CONSTRAINT briefing_discovery_data_check');
        $this->addSql('ALTER TABLE bookmark DROP CONSTRAINT bookmark_id_check');
        $this->addSql('ALTER TABLE bookmark DROP CONSTRAINT bookmark_user_id_check');
        $this->addSql('ALTER TABLE bookmark DROP CONSTRAINT bookmark_discovery_id_check');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE briefing_source_snapshot ADD CONSTRAINT briefing_source_snapshot_source_check CHECK (source IN (\'apod\', \'on_this_day\', \'weather\'))');
        $this->addSql('ALTER TABLE briefing_source_snapshot ADD CONSTRAINT briefing_source_snapshot_scope_key_check CHECK (scope_key <> \'\')');
        $this->addSql('ALTER TABLE briefing_source_snapshot ADD CONSTRAINT briefing_source_snapshot_date_check CHECK ((source = \'on_this_day\') = (date IS NULL))');
        $this->addSql('ALTER TABLE briefing_source_snapshot ADD CONSTRAINT briefing_source_snapshot_status_check CHECK (status IN (\'ok\', \'failed\'))');
        $this->addSql('ALTER TABLE briefing_source_snapshot ADD CONSTRAINT briefing_source_snapshot_error_check CHECK (error IN (\'unavailable\', \'rate_limited\', \'rejected\', \'misconfigured\', \'invalid_payload\'))');
        $this->addSql('ALTER TABLE briefing_source_snapshot ADD CONSTRAINT briefing_source_snapshot_failure_check CHECK ((status = \'failed\') = (error IS NOT NULL))');
        $this->addSql('ALTER TABLE briefing_source_snapshot ADD CONSTRAINT briefing_source_snapshot_ok_payload_check CHECK (status <> \'ok\' OR payload IS NOT NULL)');
        $this->addSql('ALTER TABLE briefing_source_snapshot ADD CONSTRAINT briefing_source_snapshot_fetched_check CHECK ((payload IS NULL) = (fetched_at IS NULL))');
        $this->addSql('ALTER TABLE briefing_source_snapshot ADD CONSTRAINT briefing_source_snapshot_payload_check CHECK (jsonb_typeof(payload) = \'object\')');
        $this->addSql('ALTER TABLE briefing_discovery ADD CONSTRAINT briefing_discovery_id_check CHECK (uuid_extract_version(id) = 5)');
        $this->addSql('ALTER TABLE briefing_discovery ADD CONSTRAINT briefing_discovery_type_check CHECK (type IN (\'apod\', \'event\', \'person\'))');
        $this->addSql('ALTER TABLE briefing_discovery ADD CONSTRAINT briefing_discovery_key_check CHECK (starts_with(key, type || \':\'))');
        $this->addSql('ALTER TABLE briefing_discovery ADD CONSTRAINT briefing_discovery_date_check CHECK (type <> \'apod\' OR date IS NOT NULL)');
        $this->addSql('ALTER TABLE briefing_discovery ADD CONSTRAINT briefing_discovery_title_check CHECK (title <> \'\')');
        $this->addSql('ALTER TABLE briefing_discovery ADD CONSTRAINT briefing_discovery_data_check CHECK (jsonb_typeof(data) = \'object\')');
        $this->addSql('ALTER TABLE bookmark ADD CONSTRAINT bookmark_id_check CHECK (uuid_extract_version(id) = 7)');
        $this->addSql('ALTER TABLE bookmark ADD CONSTRAINT bookmark_user_id_check CHECK (uuid_extract_version(user_id) = 7)');
        $this->addSql('ALTER TABLE bookmark ADD CONSTRAINT bookmark_discovery_id_check CHECK (uuid_extract_version(discovery_id) = 5)');
    }
}

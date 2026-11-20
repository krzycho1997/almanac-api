<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120203720 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the last attempt and the last good payload of every source snapshot';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE briefing_source_snapshot (
                source VARCHAR(16) NOT NULL,
                scope_key VARCHAR(64) NOT NULL,
                date DATE DEFAULT NULL,
                status VARCHAR(16) NOT NULL,
                error VARCHAR(32) DEFAULT NULL,
                payload JSONB DEFAULT NULL,
                fetched_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                last_attempt_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (source, scope_key),
                CONSTRAINT briefing_source_snapshot_source_check CHECK (source IN ('apod', 'on_this_day', 'weather')),
                CONSTRAINT briefing_source_snapshot_scope_key_check CHECK (scope_key <> ''),
                CONSTRAINT briefing_source_snapshot_date_check CHECK ((source = 'on_this_day') = (date IS NULL)),
                CONSTRAINT briefing_source_snapshot_status_check CHECK (status IN ('ok', 'failed')),
                CONSTRAINT briefing_source_snapshot_error_check CHECK (error IN ('unavailable', 'rate_limited', 'rejected', 'misconfigured', 'invalid_payload')),
                CONSTRAINT briefing_source_snapshot_failure_check CHECK ((status = 'failed') = (error IS NOT NULL)),
                CONSTRAINT briefing_source_snapshot_ok_payload_check CHECK (status <> 'ok' OR payload IS NOT NULL),
                CONSTRAINT briefing_source_snapshot_fetched_check CHECK ((payload IS NULL) = (fetched_at IS NULL)),
                CONSTRAINT briefing_source_snapshot_payload_check CHECK (jsonb_typeof(payload) = 'object')
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE briefing_source_snapshot');
    }
}

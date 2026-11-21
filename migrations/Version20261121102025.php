<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121102025 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store every picture, event and person that a briefing can bookmark under a deterministic id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE briefing_discovery (
                id UUID NOT NULL,
                key VARCHAR(300) NOT NULL,
                type VARCHAR(16) NOT NULL,
                date DATE DEFAULT NULL,
                title TEXT NOT NULL,
                data JSONB NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT briefing_discovery_id_check CHECK (uuid_extract_version(id) = 5),
                CONSTRAINT briefing_discovery_type_check CHECK (type IN ('apod', 'event', 'person')),
                CONSTRAINT briefing_discovery_key_check CHECK (starts_with(key, type || ':')),
                CONSTRAINT briefing_discovery_date_check CHECK (type <> 'apod' OR date IS NOT NULL),
                CONSTRAINT briefing_discovery_title_check CHECK (title <> ''),
                CONSTRAINT briefing_discovery_data_check CHECK (jsonb_typeof(data) = 'object')
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX briefing_discovery_key_unique ON briefing_discovery (key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE briefing_discovery');
    }
}

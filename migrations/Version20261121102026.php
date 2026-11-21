<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121102026 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store which discoveries each user bookmarked, without foreign keys into other modules';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE bookmark (
                id UUID NOT NULL,
                user_id UUID NOT NULL,
                discovery_id UUID NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT bookmark_id_check CHECK (uuid_extract_version(id) = 7),
                CONSTRAINT bookmark_user_id_check CHECK (uuid_extract_version(user_id) = 7),
                CONSTRAINT bookmark_discovery_id_check CHECK (uuid_extract_version(discovery_id) = 5)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX bookmark_user_discovery_unique ON bookmark (user_id, discovery_id)');
        $this->addSql('CREATE INDEX bookmark_user_created_idx ON bookmark (user_id, created_at DESC, id DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE bookmark');
    }
}

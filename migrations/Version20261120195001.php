<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120195001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Identity users and their access tokens';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE identity_access_token (id UUID NOT NULL, token_hash CHAR(64) NOT NULL, expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_identity_access_token_user ON identity_access_token (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_identity_access_token_hash ON identity_access_token (token_hash)');
        $this->addSql('CREATE TABLE identity_user (id UUID NOT NULL, email VARCHAR(254) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_identity_user_email ON identity_user (email)');
        $this->addSql('ALTER TABLE identity_access_token ADD CONSTRAINT FK_8A1E913DA76ED395 FOREIGN KEY (user_id) REFERENCES identity_user (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE identity_access_token DROP CONSTRAINT FK_8A1E913DA76ED395');
        $this->addSql('DROP TABLE identity_access_token');
        $this->addSql('DROP TABLE identity_user');
    }
}

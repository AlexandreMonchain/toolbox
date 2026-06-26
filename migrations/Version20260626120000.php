<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260626120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create drop_text table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE drop_text (
            id UUID NOT NULL,
            token VARCHAR(64) NOT NULL,
            payload TEXT NOT NULL,
            nonce VARCHAR(32) NOT NULL,
            passphrase_hash VARCHAR(72) DEFAULT NULL,
            language VARCHAR(32) NOT NULL DEFAULT \'plaintext\',
            max_reads INT DEFAULT NULL,
            read_count INT NOT NULL DEFAULT 0,
            expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_DROP_TEXT_TOKEN ON drop_text (token)');
        $this->addSql('CREATE INDEX IDX_DROP_TEXT_EXPIRES_AT ON drop_text (expires_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE drop_text');
    }
}

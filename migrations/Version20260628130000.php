<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260628130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add passphrase_hash to burn_note (optional passphrase protection, Argon2ID)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE burn_note ADD COLUMN passphrase_hash VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE burn_note DROP COLUMN passphrase_hash');
    }
}

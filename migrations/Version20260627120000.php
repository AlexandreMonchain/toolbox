<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260627120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen drop_text.passphrase_hash to 255 chars (Argon2id hashes ~97 chars, bcrypt 60)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE drop_text ALTER COLUMN passphrase_hash TYPE VARCHAR(255)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE drop_text ALTER COLUMN passphrase_hash TYPE VARCHAR(72)');
    }
}

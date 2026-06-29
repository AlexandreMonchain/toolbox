<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260629120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'burn_note.views_remaining devient nullable : NULL = vues illimitées (remplace la sentinelle)';
    }

    public function up(Schema $schema): void
    {
        // Modif de catalogue uniquement (pas de réécriture de table) : instantané, aucune ligne touchée.
        $this->addSql('ALTER TABLE burn_note ALTER COLUMN views_remaining DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Échouera s'il existe des notes illimitées (views_remaining IS NULL) au moment du rollback.
        $this->addSql('ALTER TABLE burn_note ALTER COLUMN views_remaining SET NOT NULL');
    }
}

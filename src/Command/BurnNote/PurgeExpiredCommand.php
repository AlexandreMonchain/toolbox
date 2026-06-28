<?php

namespace App\Command\BurnNote;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:burnnote:purge',
    description: 'Supprime les BurnNotes expirées (TTL dépassé, jamais ouvertes).',
)]
class PurgeExpiredCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $deleted = $this->connection->executeStatement(
            'DELETE FROM burn_note WHERE expires_at < NOW()'
        );

        $io->success(sprintf('%d note(s) expirée(s) supprimée(s).', $deleted));

        return Command::SUCCESS;
    }
}

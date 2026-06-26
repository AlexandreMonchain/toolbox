<?php

namespace App\Command\DropText;

use App\Repository\DropTextRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:droptext:purge',
    description: 'Supprime les DropTexts expirés (TTL dépassé).',
)]
class PurgeExpiredCommand extends Command
{
    public function __construct(private readonly DropTextRepository $repository)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $deleted = $this->repository->deleteExpired();

        $io->success(sprintf('%d note(s) expirée(s) supprimée(s).', $deleted));

        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Command;

use App\Bot\Application\BotContentRegistry;
use App\Entity\BotButtonLabel;
use App\Entity\BotMessageTemplate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:bot-content:list', description: 'List bot content counts')]
final class BotContentListCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BotContentRegistry $registry,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $connection = $this->entityManager->getConnection();

        $messages = (int) $connection->fetchOne('SELECT COUNT(*) FROM bot_message_template');
        $buttons = (int) $connection->fetchOne('SELECT COUNT(*) FROM bot_button_label');
        $rows = $connection->fetchAllAssociative(
            'SELECT locale, SUM(message_count) AS messages, SUM(button_count) AS buttons FROM (
                SELECT locale, COUNT(*) AS message_count, 0 AS button_count FROM bot_message_template GROUP BY locale
                UNION ALL
                SELECT locale, 0 AS message_count, COUNT(*) AS button_count FROM bot_button_label GROUP BY locale
            ) s GROUP BY locale ORDER BY locale'
        );

        $io->title('Bot Content');
        $io->listing([
            sprintf('message templates: %d', $messages),
            sprintf('button labels: %d', $buttons),
        ]);
        $io->table(['Locale', 'Messages', 'Buttons'], array_map(static fn (array $row): array => [
            $row['locale'],
            (string) $row['messages'],
            (string) $row['buttons'],
        ], $rows));

        // Mini App guide texts (not seeded): each key with "customized" or "default" per language.
        $customized = [];
        foreach ($this->entityManager->getRepository(BotMessageTemplate::class)->findBy(['key' => $this->registry->guideKeys()]) as $row) {
            $customized[$row->getKey()][$row->getLocale()] = $row->isActive() ? 'customized' : 'customized (inactive)';
        }
        $io->section('Mini App guide texts');
        $io->table(['Key', 'fa', 'en'], array_map(static fn (string $key): array => [
            $key,
            $customized[$key]['fa'] ?? 'default',
            $customized[$key]['en'] ?? 'default',
        ], $this->registry->guideKeys()));

        return Command::SUCCESS;
    }
}

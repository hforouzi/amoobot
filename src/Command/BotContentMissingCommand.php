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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(name: 'app:bot-content:missing', description: 'Report missing bot content DB rows and fallback translations')]
final class BotContentMissingCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BotContentRegistry $registry,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Locale to check', BotContentRegistry::DEFAULT_LOCALE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $locale = trim((string) $input->getOption('locale')) ?: BotContentRegistry::DEFAULT_LOCALE;
        $missing = [];

        foreach ($this->registry->requiredMessageKeys() as $key) {
            if (!$this->entityManager->getRepository(BotMessageTemplate::class)->findOneBy(['key' => $key, 'locale' => $locale])) {
                $missing[] = ['message db', $key];
            }
            if ($this->translator->trans($key, [], 'bot', $locale) === $key) {
                $missing[] = ['message translation', $key];
            }
        }

        foreach ($this->registry->requiredButtonKeys() as $key) {
            if (!$this->entityManager->getRepository(BotButtonLabel::class)->findOneBy(['key' => $key, 'locale' => $locale])) {
                $missing[] = ['button db', $key];
            }
            if ($this->translator->trans($key, [], 'bot', $locale) === $key) {
                $missing[] = ['button translation', $key];
            }
        }

        if ([] === $missing) {
            $io->success(sprintf('No missing bot content for locale %s.', $locale));

            return Command::SUCCESS;
        }

        $io->table(['Type', 'Key'], $missing);

        return Command::FAILURE;
    }
}

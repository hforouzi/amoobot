<?php

declare(strict_types=1);

namespace App\Command;

use App\Bot\Application\BotContentRegistry;
use App\Entity\BotButtonLabel;
use App\Entity\BotMessageTemplate;
use App\Entity\Setting;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:bot-content:seed', description: 'Seed bot message templates and button labels')]
final class BotContentSeedCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BotContentRegistry $registry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Locale to seed: fa or en', BotContentRegistry::DEFAULT_LOCALE)
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite existing records');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $locale = trim((string) $input->getOption('locale'));
        $force = (bool) $input->getOption('force');

        if (!in_array($locale, ['fa', 'en'], true)) {
            $io->error('Locale must be fa or en.');

            return Command::FAILURE;
        }

        $messageCreated = 0;
        $messageUpdated = 0;
        foreach ($this->registry->messageTemplates($locale) as $key => $definition) {
            $template = $this->entityManager->getRepository(BotMessageTemplate::class)->findOneBy(['key' => $key, 'locale' => $locale]);
            if (!$template instanceof BotMessageTemplate) {
                $template = (new BotMessageTemplate())
                    ->setKey($key)
                    ->setLocale($locale);
                ++$messageCreated;
                $this->entityManager->persist($template);
            } elseif (!$force) {
                continue;
            } else {
                ++$messageUpdated;
            }

            $template
                ->setTitle($definition['title'])
                ->setBody($definition['body'])
                ->setParseMode('html')
                ->setVariables($definition['variables'] ?? null)
                ->setCategory($definition['category'])
                ->setIsActive(true)
                ->setIsSystem(true);
        }

        $buttonCreated = 0;
        $buttonUpdated = 0;
        foreach ($this->registry->buttonLabels($locale) as $key => $definition) {
            $label = $this->entityManager->getRepository(BotButtonLabel::class)->findOneBy(['key' => $key, 'locale' => $locale]);
            if (!$label instanceof BotButtonLabel) {
                $label = (new BotButtonLabel())
                    ->setKey($key)
                    ->setLocale($locale);
                ++$buttonCreated;
                $this->entityManager->persist($label);
            } elseif (!$force) {
                continue;
            } else {
                ++$buttonUpdated;
            }

            $label
                ->setLabel($definition['label'])
                ->setButtonType($definition['buttonType'])
                ->setCategory($definition['category'])
                ->setIsActive(true)
                ->setIsSystem(true);
        }

        $this->seedSetting('bot.brand_name', 'Amoobot', 'string');
        $this->seedSetting('bot.footer_text', '', 'string');
        $this->seedSetting('bot.default_locale', BotContentRegistry::DEFAULT_LOCALE, 'string');
        $this->seedSetting('bot.support_text', '', 'text');

        $this->entityManager->flush();

        $io->success(sprintf(
            'Seeded locale %s. Messages created=%d updated=%d; buttons created=%d updated=%d.',
            $locale,
            $messageCreated,
            $messageUpdated,
            $buttonCreated,
            $buttonUpdated
        ));

        return Command::SUCCESS;
    }

    private function seedSetting(string $key, string $value, string $type): void
    {
        $setting = $this->entityManager->getRepository(Setting::class)->findOneBy(['keyName' => $key]);
        if ($setting instanceof Setting) {
            return;
        }

        $this->entityManager->persist((new Setting())->setKeyName($key)->setValue($value)->setType($type));
    }
}

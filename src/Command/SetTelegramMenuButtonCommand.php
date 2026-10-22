<?php

declare(strict_types=1);

namespace App\Command;

use App\Bot\Application\BotTextResolver;
use App\Bot\Infrastructure\TelegramApiClient;
use App\MiniApp\Application\MiniAppLinks;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Points the bot's menu button (next to the message field, in every private chat) at the Mini App, on the public
 * address from DEFAULT_URI. Independent of the miniapp.enabled setting, which only governs the in-chat buttons.
 */
#[AsCommand(name: 'app:telegram:set-menu-button', description: 'Set (or remove) the bot menu button that opens the Mini App')]
class SetTelegramMenuButtonCommand extends Command
{
    public function __construct(
        private readonly TelegramApiClient $telegramApiClient,
        private readonly MiniAppLinks $miniAppLinks,
        private readonly BotTextResolver $botTextResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('remove', null, InputOption::VALUE_NONE, 'Restore Telegram\'s default menu button (the command list)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only show what would be sent to Telegram');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('remove')) {
            $menuButton = ['type' => 'default'];
        } else {
            $url = $this->miniAppLinks->publicUrl();
            if (null === $url) {
                $output->writeln('<error>The Mini App needs a public HTTPS address: set DEFAULT_URI to https://your-domain (see docs/en/miniapp.md).</error>');

                return Command::FAILURE;
            }
            $menuButton = ['type' => 'web_app', 'text' => $this->botTextResolver->button('button.miniapp.menu'), 'web_app' => ['url' => $url]];
        }

        $payload = json_encode(['menu_button' => $menuButton], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '';
        if ($input->getOption('dry-run')) {
            $output->writeln('setChatMenuButton (dry run, nothing sent):');
            $output->writeln($payload);

            return Command::SUCCESS;
        }

        try {
            $this->telegramApiClient->setChatMenuButton($menuButton);
        } catch (\RuntimeException $e) {
            // The client's messages name the method and Telegram's description, never the bot token.
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return Command::FAILURE;
        }
        $output->writeln($input->getOption('remove') ? 'Menu button reset to the default.' : 'Menu button set: '.$menuButton['web_app']['url']);

        return Command::SUCCESS;
    }
}

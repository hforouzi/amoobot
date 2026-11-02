<?php

declare(strict_types=1);

namespace App\MiniApp\Guide;

use App\Bot\Application\BotTextResolver;
use App\Bot\Application\DeepLink\StartPayloadHandler;
use App\Bot\Infrastructure\TelegramApiClient;
use App\Entity\TelegramAccount;
use App\MiniApp\Application\MiniAppLinks;

/**
 * Deep links into the guide (docs/design/help-guide.md, section 3.4), the "guide" prefix of StartPayloadRouter:
 *
 *  - Mini App: t.me/<bot>?startapp=guide_<slug> (a BotFather menu button, a channel post) opens the Help section at that
 *    topic, with Home behind the BackButton;
 *  - bot: "/start guide_<slug>" answers with the usual "open the app" message and a button opening the app at that
 *    topic.
 *
 * Only while the Mini App can be opened (MiniAppLinks::forTelegram) and only for a fixed topic slug whose topic is
 * shown now (GuideLinks::isVisible); anything else is not taken, so /start and the Mini App behave exactly as without a
 * payload. The slug is never logged or echoed.
 */
final class GuideStartHandler implements StartPayloadHandler
{
    public const PREFIX = 'guide';

    public function __construct(
        private readonly GuideLinks $links,
        private readonly MiniAppLinks $miniAppLinks,
        private readonly TelegramApiClient $telegram,
        private readonly BotTextResolver $texts,
    ) {
    }

    public function prefix(): string
    {
        return self::PREFIX;
    }

    public function enabled(): bool
    {
        return null !== $this->miniAppLinks->forTelegram();
    }

    public function accepts(#[\SensitiveParameter] string $value): bool
    {
        return $this->links->isVisible($value);
    }

    public function handleBot(TelegramAccount $account, string $chatId, #[\SensitiveParameter] string $value): void
    {
        $url = $this->miniAppLinks->forGuide($value);
        $keyboard = null === $url ? null : ['inline_keyboard' => [[['text' => $this->texts->button('button.miniapp.open'), 'web_app' => ['url' => $url]]]]];
        $this->telegram->sendMessage($chatId, $this->texts->message('miniapp.open'), $keyboard);
    }

    public function miniAppTarget(TelegramAccount $account, #[\SensitiveParameter] string $value): ?string
    {
        return GuideLinks::isTopicSlug($value) ? '/app/view/help?topic='.$value : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Subscription\Application;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The one text a customer sees while FinalConfigLinkProvider::linksPending() withholds a service's single links
 * (docs/design/plan-inbound-change.md 5.5): the subscription page, the Mini App, the payment return page and the bot all
 * show this key. It names no link, token, UUID or owner. The bot and the payment return page are Persian, so they ask for
 * BOT_LOCALE; the subscription page and the Mini App use the visitor's language.
 */
final class LinksUpdatingNotice
{
    public const KEY = 'link.updating';
    public const DOMAIN = 'subscription';
    public const BOT_LOCALE = 'fa';

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function text(string $locale = self::BOT_LOCALE): string
    {
        return $this->translator->trans(self::KEY, [], self::DOMAIN, $locale);
    }
}

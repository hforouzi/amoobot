<?php

declare(strict_types=1);

namespace App\Branding;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The shop's support chat: Setting "support.url", edited on the web settings page, "branding" tab
 * (SettingsAdministration::saveBranding()). Empty means no support button anywhere. When set, the Mini App guide's
 * support topic shows a "Message support" button (Telegram.WebApp.openTelegramLink) and the bot adds the same button
 * under its support message (TelegramUpdateHandler::handleSupport()).
 *
 * Only https://t.me/<username> or https://telegram.me/<username> is accepted: lower-case host, a username of 5 to 32
 * letters, digits or underscores, nothing after it (no path, query, fragment, port or credentials). The value is
 * checked again when read, so a row changed by hand (the "advanced" tab) that breaks the rule shows no button. It is
 * always given out as https://t.me/<username> (the same chat; Telegram opens only t.me links inside the app).
 */
final class SupportLinkSettings implements ResetInterface
{
    public const URL = 'support.url';
    public const MAX_LENGTH = 64;
    private const PATTERN = '#^https://(?:t|telegram)\.me/([A-Za-z0-9_]{5,32})$#D';

    private string|false|null $current = false;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /** The support chat as https://t.me/<username>, or null when none is set (or the stored value is not valid). */
    public function url(): ?string
    {
        if (false !== $this->current) {
            return $this->current;
        }
        $value = $this->entityManager->getConnection()->fetchOne('SELECT value FROM setting WHERE key_name = ?', [self::URL]);

        return $this->current = self::canonical(is_string($value) ? $value : '');
    }

    /** Whether a value may be saved: '' (no button) or a valid Telegram chat link. */
    public static function isValid(string $value): bool
    {
        return '' === $value || null !== self::canonical($value);
    }

    /** https://t.me/<username> for a valid link, else null ('' included). */
    public static function canonical(string $value): ?string
    {
        if (strlen($value) > self::MAX_LENGTH || 1 !== preg_match(self::PATTERN, $value, $m)) {
            return null;
        }

        return 'https://t.me/'.$m[1];
    }

    /** Forget what was read (after a save, and between requests in a long-running worker). */
    public function reset(): void
    {
        $this->current = false;
    }
}

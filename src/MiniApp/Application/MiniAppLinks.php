<?php

declare(strict_types=1);

namespace App\MiniApp\Application;

use App\Provisioning\Domain\PublicUrlPolicy;
use App\Shared\Infrastructure\SettingValueProvider;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Where the bot points its "Open app" buttons: the Mini App (/app) on the shop's own address (DEFAULT_URI outside a
 * request). Only while the setting miniapp.enabled is on (off by default) and only for a public HTTPS address:
 * Telegram refuses a web_app button to anything else, and with it the whole message.
 */
final class MiniAppLinks
{
    public const ENABLED_SETTING = 'miniapp.enabled';

    public function __construct(
        private readonly SettingValueProvider $settings,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->settings->getBool(self::ENABLED_SETTING, false);
    }

    /** The app for a bot button, or null (setting off, or no public HTTPS address). */
    public function forTelegram(?int $serviceId = null): ?string
    {
        return $this->isEnabled() ? $this->publicUrl($serviceId) : null;
    }

    /**
     * The app's public HTTPS URL whatever the setting (the menu button command), or null when Telegram cannot open it.
     * With a service id the app opens that service (app.js reads ?service=; the server still checks ownership).
     */
    public function publicUrl(?int $serviceId = null): ?string
    {
        $url = $this->urlGenerator->generate('miniapp_entry', null === $serviceId ? [] : ['service' => $serviceId], UrlGeneratorInterface::ABSOLUTE_URL);

        return str_starts_with($url, 'https://') && PublicUrlPolicy::isPubliclyUsableUrl($url) ? $url : null;
    }
}

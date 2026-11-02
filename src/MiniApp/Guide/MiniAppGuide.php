<?php

declare(strict_types=1);

namespace App\MiniApp\Guide;

use App\Bot\Application\BotTextResolver;
use App\Subscription\Domain\ClientPlatform;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Twig\Markup;

/**
 * The customer guide of the Mini App's Help section (docs/design/help-guide.md): the topics the shop's current state
 * allows (GuideTopic::isVisible: in code, never in the texts), in order, each with its title and its body rendered by
 * GuideRenderer. The texts are the bot texts guide.<slug>.title / guide.<slug>.body, read through
 * BotTextResolver::guideText(): the admin's row for that language (Bot Content, parse mode "guide"), else the shipped
 * default (translations/bot.*.yaml). A topic whose title or body is empty, missing or renders to nothing is not shown,
 * so an admin hides a topic by emptying its body. The "connect" topic also shows the client apps the admin set up for
 * the reader's device (GuideClientApps, templates/miniapp/view/_guide_apps.html.twig) at its {{ apps }} line, or on top
 * when its text has none. Nothing here depends on who is reading: only on the language and the device chosen.
 */
final class MiniAppGuide
{
    public function __construct(
        private readonly BotTextResolver $texts,
        private readonly GuideRenderer $renderer,
        private readonly GuideStateProvider $stateProvider,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly GuideClientApps $clientApps,
        private readonly Environment $twig,
    ) {
    }

    /**
     * @return list<array{slug: string, title: string, body: Markup}>
     */
    public function topics(string $locale, ?GuideState $state = null, ?ClientPlatform $platform = null): array
    {
        $state ??= $this->stateProvider->current();
        $candidates = [];
        foreach (GuideTopic::cases() as $topic) {
            if (!$topic->isVisible($state)) {
                continue;
            }
            $title = $this->renderer->title($this->text($topic->titleKey(), $locale));
            $body = $this->text($topic->bodyKey(), $locale);
            if ('' !== $title && '' !== trim($body)) {
                $candidates[$topic->value] = [$title, $body];
            }
        }

        // Links between topics only to the topics listed here.
        $topicUrl = fn (string $slug): ?string => isset($candidates[$slug]) ? $this->urlGenerator->generate('miniapp_help', ['topic' => $slug]) : null;
        $topics = [];
        foreach ($candidates as $slug => [$title, $body]) {
            $blocks = [];
            if (GuideTopic::CONNECT->value === $slug) {
                $blocks['apps'] = $this->appsBlock($locale, $platform);
                if (!self::hasAppsLine($body)) {
                    $body = GuideRenderer::PLACEHOLDER_APPS."\n\n".$body;
                }
            }
            $html = $this->renderer->render($body, $state, $topicUrl, $blocks);
            if ('' !== (string) $html) {
                $topics[] = ['slug' => $slug, 'title' => $title, 'body' => $html];
            }
        }

        return $topics;
    }

    /**
     * The topic ?topic= asks for, if it is one of $topics (anything else: none).
     *
     * @param list<array{slug: string, title: string, body: Markup}> $topics
     */
    public static function requestedTopic(mixed $value, array $topics): ?string
    {
        if (!is_string($value) || 1 !== preg_match('/^[a-z_]{1,32}$/D', $value)) {
            return null;
        }

        return in_array($value, array_column($topics, 'slug'), true) ? $value : null;
    }

    /** The client-app block of the "connect" topic: the device switch and that device's apps, or the generic text. */
    private function appsBlock(string $locale, ?ClientPlatform $platform): Markup
    {
        $apps = $this->clientApps->forPlatform($platform);

        return new Markup($this->twig->render('miniapp/view/_guide_apps.html.twig', [
            'locale' => $locale,
            'platform' => $platform,
            'platforms' => ClientPlatform::cases(),
            'anyApps' => [] !== $apps || $this->clientApps->any(),
            'recommended' => $apps[0] ?? null,
            'others' => array_slice($apps, 1),
            'importButtons' => [] !== array_filter($apps, static fn (GuideClientApp $app): bool => $app->hasImportButton),
        ]), 'UTF-8');
    }

    /**
     * Whether topics() lists this one topic right now (its feature is on, its title and body are not empty and the body
     * renders to something), worked out for that topic alone: what a deep link (GuideLinks) checks, so a view with a
     * link to the guide does not render the whole guide. The connect topic always has the client apps block. The shop's
     * state is asked for only when the answer depends on it (a topic behind a feature switch, a text with "::: if" or
     * {{ payment_methods }}), so a link to an always-shown topic costs no settings reads.
     *
     * @param (\Closure(): GuideState)|null $state the current state (default: GuideStateProvider::current())
     */
    public function isListed(GuideTopic $topic, string $locale, ?\Closure $state = null): bool
    {
        $state ??= $this->stateProvider->current(...);
        // Topic visibility only ever needs features to be ON: a topic shown with everything off is always shown.
        $nothingOn = new GuideState([]);
        if (!$topic->isVisible($nothingOn) && !$topic->isVisible($state())) {
            return false;
        }
        $title = $this->renderer->title($this->text($topic->titleKey(), $locale));
        $body = $this->text($topic->bodyKey(), $locale);
        if ('' === $title || '' === trim($body)) {
            return false;
        }
        if (GuideTopic::CONNECT === $topic) {
            return true;
        }
        $usesState = str_contains($body, ':::') || str_contains($body, '{{ payment_methods }}');

        return '' !== (string) $this->renderer->render($body, $usesState ? $state() : $nothingOn, static fn (string $slug): ?string => null);
    }

    /** Whether the text places the client apps itself ({{ apps }} on a line of its own). */
    public static function hasAppsLine(string $body): bool
    {
        foreach (explode("\n", $body) as $line) {
            if (GuideRenderer::PLACEHOLDER_APPS === trim($line, " \r")) {
                return true;
            }
        }

        return false;
    }

    public function plain(string $text): Markup
    {
        return $this->renderer->plain($text);
    }

    /** The admin's text for this language or the shipped default ('' when neither exists), guide placeholders kept. */
    private function text(string $key, string $locale): string
    {
        return $this->texts->guideText($key, $locale, GuideRenderer::PLACEHOLDERS);
    }
}

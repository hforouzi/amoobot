<?php

declare(strict_types=1);

namespace App\MiniApp\Guide;

use App\Subscription\Domain\ClientPlatform;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Deep links into the guide (docs/design/help-guide.md, section 3.4): the in-app links of the other views
 * (guide_link() in Twig) and the entry points (?guide=<slug> on /app, startapp=guide_<slug>). A slug is used only when it
 * is one of the fixed topic slugs (GuideTopic) AND that topic is listed in the guide right now (its feature is on and
 * its text is not empty, exactly as MiniAppGuide lists it), so no link ever leads to a hidden topic. Anything else gives
 * null, and the caller shows nothing (or the usual home). Each topic is checked on its own (MiniAppGuide::isListed),
 * once per request, so a link costs the same whatever else the shop has switched on.
 */
final class GuideLinks
{
    /** The longest slug (GuideTopic values are lower-case letters and "_"). */
    private const SLUG = '/^[a-z_]{1,32}$/D';

    /** @var array<string, bool> "<slug>|<locale>" => listed, for the request in $visibleFor */
    private array $visible = [];

    private ?string $visibleFor = null;

    private ?GuideState $state = null;

    public function __construct(
        private readonly MiniAppGuide $guide,
        private readonly GuideStateProvider $stateProvider,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * One of the guide's fixed topic slugs (the allow-list), checked exactly: no trimming, no case folding.
     *
     * @phpstan-assert-if-true non-empty-string $slug
     */
    public static function isTopicSlug(mixed $slug): bool
    {
        return is_string($slug) && 1 === preg_match(self::SLUG, $slug) && null !== GuideTopic::tryFrom($slug);
    }

    /** @return list<string> the allow-list, in the guide's order */
    public static function topicSlugs(): array
    {
        return array_map(static fn (GuideTopic $topic): string => $topic->value, GuideTopic::cases());
    }

    /**
     * The in-app URL of a topic's card (/app/view/help?topic=<slug>[&platform=<p>]), or null when $slug is not a topic
     * or that topic is not shown right now.
     */
    public function url(mixed $slug, string $locale, ?ClientPlatform $platform = null): ?string
    {
        if (!$this->isVisible($slug, $locale)) {
            return null;
        }
        $parameters = ['topic' => $slug];
        if (null !== $platform && GuideTopic::CONNECT->value === $slug) {
            $parameters['platform'] = $platform->value;
        }

        return $this->urlGenerator->generate('miniapp_help', $parameters);
    }

    /**
     * Whether a topic may be opened by a deep link: a fixed slug whose topic is listed now. Without $locale only the
     * feature switches are checked (a start payload: the language is not known yet; app.js falls back to home when
     * the topic's card is not there).
     */
    public function isVisible(mixed $slug, ?string $locale = null): bool
    {
        if (!self::isTopicSlug($slug)) {
            return false;
        }
        $this->forThisRequest();
        if (null === $locale) {
            return GuideTopic::from($slug)->isVisible($this->state());
        }

        return $this->visible[$slug.'|'.$locale] ??= $this->guide->isListed(GuideTopic::from($slug), $locale, $this->state(...));
    }

    /**
     * Keeps answers for one HTTP request only; outside a request (a bot update in a worker) nothing is kept, so a
     * long-running process never uses an old answer.
     */
    private function forThisRequest(): void
    {
        $request = $this->requestStack->getMainRequest();
        $for = null === $request ? null : (string) spl_object_id($request);
        if (null === $for || $for !== $this->visibleFor) {
            $this->visible = [];
            $this->state = null;
            $this->visibleFor = $for;
        }
    }

    /** The shop's state, read at most once per request (and only when a link needs it). */
    private function state(): GuideState
    {
        return $this->state ??= $this->stateProvider->current();
    }
}

<?php

declare(strict_types=1);

namespace App\MiniApp\Guide;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Markup;

/**
 * The customer guide of the Mini App's Help section (docs/design/help-guide.md): the topics the shop's current state
 * allows (GuideTopic::isVisible), in order, each with its title and its body rendered by GuideRenderer. The texts are
 * guide.<slug>.title / guide.<slug>.body of the bot domain (translations/bot.*.yaml, fa and en). A topic whose title
 * or body is empty, missing or renders to nothing is not shown. Nothing here depends on who is reading.
 */
final class MiniAppGuide
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly GuideRenderer $renderer,
        private readonly GuideStateProvider $stateProvider,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return list<array{slug: string, title: string, body: Markup}>
     */
    public function topics(string $locale, ?GuideState $state = null): array
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
            $html = $this->renderer->render($body, $state, $topicUrl);
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

    public function plain(string $text): Markup
    {
        return $this->renderer->plain($text);
    }

    /** The translation, or '' when there is none (the translator answers with the key itself). */
    private function text(string $key, string $locale): string
    {
        $text = $this->translator->trans($key, [], 'bot', $locale);

        return $text === $key ? '' : $text;
    }
}

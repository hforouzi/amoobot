<?php

declare(strict_types=1);

namespace App\MiniApp\Guide;

use App\Bot\Application\BotTextResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * The admin's live preview of a guide text (Bot Content edit page, docs/design/help-guide.md, section 6): the topic's
 * card as the Mini App shows it, with the text being edited in place of the saved one and the topic's other text as
 * the customer sees it now. The same BotTextResolver placeholders and the same GuideRenderer as MiniAppGuide; Persian
 * right to left, English left to right. The result is a whole HTML document for a sandboxed iframe (no script runs in
 * it); links to other topics are inert there.
 */
final class GuidePreview
{
    /** Longer input is not previewed (saving it is refused anyway: GuideTextRules::BODY_MAX). */
    public const MAX_INPUT = 20000;

    public function __construct(
        private readonly BotTextResolver $texts,
        private readonly GuideRenderer $renderer,
        private readonly GuideStateProvider $stateProvider,
        private readonly TranslatorInterface $translator,
        private readonly Environment $twig,
    ) {
    }

    /**
     * @return array{document: string, problems: list<string>, notes: list<string>}
     */
    public function preview(string $key, string $locale, string $text): array
    {
        $topic = self::topic($key) ?? throw new \InvalidArgumentException('Not a guide key.');
        $locale = 'en' === $locale ? 'en' : 'fa';
        $state = $this->stateProvider->current();
        $editsTitle = $topic->titleKey() === $key;
        $title = $this->renderer->title($editsTitle ? $this->texts->fillGuideText($text, GuideRenderer::PLACEHOLDERS) : $this->texts->guideText($topic->titleKey(), $locale, GuideRenderer::PLACEHOLDERS));
        $body = $editsTitle ? $this->texts->guideText($topic->bodyKey(), $locale, GuideRenderer::PLACEHOLDERS) : $this->texts->fillGuideText($text, GuideRenderer::PLACEHOLDERS);

        $visible = [];
        foreach (GuideTopic::cases() as $other) {
            $visible[$other->value] = $other->isVisible($state);
        }
        $html = $this->renderer->render($body, $state, static fn (string $slug): ?string => ($visible[$slug] ?? false) ? '#guide-'.$slug : null);

        $notes = [];
        if (!$topic->isVisible($state)) {
            $notes[] = $this->translator->trans('guide_text.preview.hidden_feature', [], 'admin');
        }
        if ('' === $title || '' === trim($body) || '' === (string) $html) {
            $notes[] = $this->translator->trans('guide_text.preview.hidden_empty', [], 'admin');
        }
        $problems = array_map(fn (array $p): string => $this->translator->trans($p['message'], $p['parameters'], 'validators'), GuideTextRules::problems($key, $text));

        return [
            'document' => $this->twig->render('admin/bot_text/guide_preview_frame.html.twig', [
                'locale' => $locale,
                'dir' => 'fa' === $locale ? 'rtl' : 'ltr',
                'slug' => $topic->value,
                'title' => $title,
                'body' => $html,
            ]),
            'problems' => $problems,
            'notes' => $notes,
        ];
    }

    public static function topic(string $key): ?GuideTopic
    {
        if (!GuideTextRules::isGuideKey($key)) {
            return null;
        }

        return GuideTopic::tryFrom(explode('.', $key)[1]);
    }
}

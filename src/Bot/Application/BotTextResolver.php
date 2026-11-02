<?php

declare(strict_types=1);

namespace App\Bot\Application;

use App\Entity\BotButtonLabel;
use App\Entity\BotMessageTemplate;
use App\Shared\Infrastructure\SettingValueProvider;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class BotTextResolver
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly BotContentRegistry $registry,
        private readonly SettingValueProvider $settingValueProvider,
        private readonly LoggerInterface $logger,
        private readonly string $defaultLocale = BotContentRegistry::DEFAULT_LOCALE,
    ) {
    }

    /**
     * @param array<string, mixed> $variables
     */
    public function message(string $key, array $variables = [], ?string $locale = null): string
    {
        $locale = $this->locale($locale);

        try {
            $template = $this->rawTemplate($key, $locale);
            if ($template instanceof BotMessageTemplate) {
                return $this->render($template->getBody(), $this->withBotVariables($variables));
            }
        } catch (\Throwable $e) {
            $this->logger->error('Bot template rendering failed.', ['key' => $key, 'locale' => $locale, 'exception' => $e]);
        }

        $translated = $this->translate($key, $locale);
        if (null !== $translated) {
            return $this->render($translated, $this->withBotVariables($variables));
        }

        return $this->render($this->registry->emergencyMessage($key, $locale), $this->withBotVariables($variables));
    }

    /**
     * A text of the Mini App guide (guide.<slug>.title / guide.<slug>.body, docs/design/help-guide.md): the admin's
     * active row for exactly this language, else the shipped default of this language (translations/bot.<locale>.yaml),
     * else ''. Unlike message(), the default language's row is not used for another language, so a Persian edit never
     * shows on the English guide. An active row with an empty body is returned as '' (the guide then hides the topic).
     * {{ bot.* }} variables are filled in as in message(); the placeholders named in $keep (the guide's own, e.g.
     * payment_methods) are left as written for GuideRenderer; any other {{ … }} becomes empty, as in message().
     *
     * @param list<string> $keep
     */
    public function guideText(string $key, string $locale, array $keep = []): string
    {
        $variables = [];
        foreach ($keep as $name) {
            $variables[$name] = '{{ '.$name.' }}';
        }
        $variables = $this->withBotVariables($variables);

        try {
            $template = $this->findTemplate($key, $locale);
            if ($template instanceof BotMessageTemplate) {
                return $this->render($template->getBody(), $variables);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Bot template rendering failed.', ['key' => $key, 'locale' => $locale, 'exception' => $e]);
        }

        $translated = $this->translate($key, $locale);

        return null === $translated ? '' : $this->render($translated, $variables);
    }

    public function button(string $key, ?string $locale = null): string
    {
        $locale = $this->locale($locale);

        try {
            $label = $this->findButton($key, $locale);
            if ($label instanceof BotButtonLabel && '' !== trim($label->getLabel())) {
                return $label->getLabel();
            }

            if ($locale !== $this->defaultLocale) {
                $fallbackLabel = $this->findButton($key, $this->defaultLocale);
                if ($fallbackLabel instanceof BotButtonLabel && '' !== trim($fallbackLabel->getLabel())) {
                    return $fallbackLabel->getLabel();
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Bot button resolution failed.', ['key' => $key, 'locale' => $locale, 'exception' => $e]);
        }

        $translated = $this->translate($key, $locale);
        if (null !== $translated) {
            return $translated;
        }

        return $this->registry->emergencyButton($key, $locale);
    }

    public function rawTemplate(string $key, ?string $locale = null): ?BotMessageTemplate
    {
        $locale = $this->locale($locale);
        $template = $this->findTemplate($key, $locale);
        if ($template instanceof BotMessageTemplate) {
            return $template;
        }

        if ($locale !== $this->defaultLocale) {
            return $this->findTemplate($key, $this->defaultLocale);
        }

        return null;
    }

    private function findTemplate(string $key, string $locale): ?BotMessageTemplate
    {
        $template = $this->entityManager->getRepository(BotMessageTemplate::class)->findOneBy([
            'key' => $key,
            'locale' => $locale,
            'isActive' => true,
        ]);

        return $template instanceof BotMessageTemplate ? $template : null;
    }

    private function findButton(string $key, string $locale): ?BotButtonLabel
    {
        $label = $this->entityManager->getRepository(BotButtonLabel::class)->findOneBy([
            'key' => $key,
            'locale' => $locale,
            'isActive' => true,
        ]);

        return $label instanceof BotButtonLabel ? $label : null;
    }

    private function translate(string $key, string $locale): ?string
    {
        $translated = $this->translator->trans($key, [], 'bot', $locale);

        return $translated === $key ? null : $translated;
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function render(string $template, array $variables): string
    {
        return (string) preg_replace_callback('/{{\s*([A-Za-z0-9_.]+)\s*}}/', function (array $matches) use ($variables): string {
            $value = $this->readPath($variables, (string) $matches[1]);
            if (null === $value) {
                return '';
            }

            if ($value instanceof \DateTimeInterface) {
                return $value->format('Y-m-d H:i:s');
            }

            if (is_scalar($value) || $value instanceof \Stringable) {
                return (string) $value;
            }

            return '';
        }, $template);
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function readPath(array $variables, string $path): mixed
    {
        $current = $variables;
        foreach (explode('.', $path) as $part) {
            if (is_array($current) && array_key_exists($part, $current)) {
                $current = $current[$part];
                continue;
            }

            return null;
        }

        return $current;
    }

    /**
     * @param array<string, mixed> $variables
     *
     * @return array<string, mixed>
     */
    private function withBotVariables(array $variables): array
    {
        $variables['bot'] = [
            'brandName' => $this->settingValueProvider->get('bot.brand_name', 'Amoobot'),
            'footerText' => $this->settingValueProvider->get('bot.footer_text', ''),
            'supportText' => $this->settingValueProvider->get('bot.support_text', ''),
        ];

        return $variables;
    }

    private function locale(?string $locale): string
    {
        $candidate = trim((string) $locale);
        if ('' !== $candidate) {
            return $candidate;
        }

        return $this->settingValueProvider->get('bot.default_locale', $this->defaultLocale);
    }
}

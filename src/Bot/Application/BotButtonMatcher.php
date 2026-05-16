<?php

declare(strict_types=1);

namespace App\Bot\Application;

final class BotButtonMatcher
{
    public function __construct(
        private readonly BotTextResolver $resolver,
        private readonly BotContentRegistry $registry,
    ) {
    }

    public function matchReplyButton(string $text, ?string $locale = null): ?string
    {
        $normalized = $this->normalize($text);
        if ('' === $normalized) {
            return null;
        }

        foreach ($this->registry->replyButtonKeys() as $key) {
            $current = $this->normalize($this->resolver->button($key, $locale));
            $fallback = $this->normalize($this->registry->emergencyButton($key, $locale ?? BotContentRegistry::DEFAULT_LOCALE));
            $defaultFallback = $this->normalize($this->registry->emergencyButton($key));
            if ($normalized === $current || $normalized === $fallback || $normalized === $defaultFallback) {
                return $key;
            }
        }

        return null;
    }

    private function normalize(string $text): string
    {
        return preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
    }
}

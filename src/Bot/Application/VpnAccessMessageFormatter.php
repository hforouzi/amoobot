<?php

declare(strict_types=1);

namespace App\Bot\Application;

use App\Entity\VpnService;
use App\Provisioning\Application\FinalConfigLinkProvider;

final class VpnAccessMessageFormatter
{
    private const MAX_MESSAGE_LENGTH = 3500;

    public function __construct(
        private readonly FinalConfigLinkProvider $finalConfigLinkProvider,
    ) {
    }

    /**
     * @return list<string>
     */
    public function formatServiceAccessMessages(VpnService $service, string $headline = '✅ سرویس شما آماده شد', string $sourceFlow = 'telegram_access_message'): array
    {
        $subscriptionUrl = trim((string) ($service->getSubscriptionUrl() ?? ''));
        $configLinks = $this->finalConfigLinkProvider->getFinalLinksForService($service, $sourceFlow);

        if ('' === $subscriptionUrl && [] === $configLinks) {
            return ['سرویس ساخته شد، اما لینک دسترسی پیدا نشد. لطفاً با پشتیبانی تماس بگیرید.'];
        }

        $header = [
            $this->html($headline),
            '',
            'نام سرویس:',
            $this->code($this->serviceName($service)),
        ];

        if ('' !== $subscriptionUrl) {
            $header[] = '';
            $header[] = '🔗 لینک اشتراک:';
            $header[] = sprintf('<a href="%s">باز کردن لینک اشتراک</a>', $this->attr($subscriptionUrl));
            $header[] = '';
            $header[] = 'برای کپی:';
            $header[] = $this->code($subscriptionUrl);
        }

        if ([] === $configLinks) {
            return $this->splitMessage(implode("\n", $header));
        }

        $messages = [];
        $current = implode("\n", [...$header, '', '⚙️ کانفیگ‌ها:']);
        foreach ($configLinks as $index => $configUrl) {
            $block = sprintf(
                "%d. <a href=\"%s\">کانفیگ %s</a>\n%s",
                $index + 1,
                $this->attr($configUrl),
                $this->persianNumber($index + 1),
                $this->code($configUrl)
            );

            if (mb_strlen($current."\n\n".$block) > self::MAX_MESSAGE_LENGTH) {
                $messages[] = $current;
                $current = "⚙️ کانفیگ‌ها:\n\n".$block;
                continue;
            }

            $current .= "\n\n".$block;
        }

        $messages[] = $current;

        return $messages;
    }

    private function serviceName(VpnService $service): string
    {
        foreach ([$service->getClientEmail(), $service->getUsername(), $service->getClientUuid(), $service->getRemoteId()] as $candidate) {
            $value = trim((string) $candidate);
            if ('' !== $value) {
                return $value;
            }
        }

        return sprintf('#%d', $service->getId() ?? 0);
    }

    /**
     * @return list<string>
     */
    private function splitMessage(string $message): array
    {
        $message = trim($message);
        if (mb_strlen($message) <= self::MAX_MESSAGE_LENGTH) {
            return [$message];
        }

        $chunks = [];
        $remaining = $message;
        while (mb_strlen($remaining) > self::MAX_MESSAGE_LENGTH) {
            $slice = mb_substr($remaining, 0, self::MAX_MESSAGE_LENGTH);
            $breakPos = mb_strrpos($slice, "\n\n");
            if (false === $breakPos || $breakPos < 1000) {
                $breakPos = mb_strrpos($slice, "\n");
            }
            if (false === $breakPos || $breakPos < 1000) {
                $breakPos = self::MAX_MESSAGE_LENGTH;
            }
            $chunks[] = trim(mb_substr($remaining, 0, (int) $breakPos));
            $remaining = trim(mb_substr($remaining, (int) $breakPos));
        }
        if ('' !== $remaining) {
            $chunks[] = $remaining;
        }

        return $chunks;
    }

    private function html(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function code(string $value): string
    {
        return '<code>'.$this->html($value).'</code>';
    }

    private function persianNumber(int $number): string
    {
        return strtr((string) $number, [
            '0' => '۰',
            '1' => '۱',
            '2' => '۲',
            '3' => '۳',
            '4' => '۴',
            '5' => '۵',
            '6' => '۶',
            '7' => '۷',
            '8' => '۸',
            '9' => '۹',
        ]);
    }
}

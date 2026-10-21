<?php

declare(strict_types=1);

namespace App\Bot\Application;

use App\Bot\Infrastructure\TelegramApiClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Sends a QR code to a Telegram chat: as a photo when the content is short (it stays scannable after Telegram's
 * recompression), otherwise as an uncompressed PNG document. Every QR sent to customers goes through here.
 */
final class QrCodeSender
{
    public function __construct(
        private readonly TelegramApiClient $telegramApiClient,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return bool false when the image could not be created (nothing was sent)
     */
    public function send(string $chatId, string $content, string $caption, string $fileName): bool
    {
        try {
            $png = QrCodeImage::png($content);
        } catch (\Throwable) {
            return false;
        }

        $dir = $this->projectDir.'/var/cache/qr';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $safeName = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $fileName) ?: 'qr';
        $path = sprintf('%s/%s-%s.png', $dir, $safeName, bin2hex(random_bytes(4)));
        if (!is_dir($dir) || false === @file_put_contents($path, $png)) {
            return false;
        }

        try {
            if (QrCodeImage::fitsInAPhoto($content)) {
                $this->telegramApiClient->sendPhotoFile($chatId, $path, $caption);
            } else {
                $this->telegramApiClient->sendDocumentFile($chatId, $path, $caption, null, $safeName.'.png', 'image/png');
            }
        } finally {
            @unlink($path);
        }

        return true;
    }
}

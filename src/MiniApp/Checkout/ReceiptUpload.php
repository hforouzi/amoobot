<?php

declare(strict_types=1);

namespace App\MiniApp\Checkout;

use App\Bot\Infrastructure\TelegramApiClient;
use App\Entity\Payment;
use App\MiniApp\Security\MiniAppUser;
use App\Shop\Application\Checkout\ReceiptSubmission;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * A card-to-card receipt photo uploaded in the Mini App, stored exactly like one sent to the bot: the image is
 * checked (size, real image content, dimensions), posted into the customer's own chat with the bot, and the file id
 * Telegram returns becomes the payment's receipt (ReceiptSubmission, which alerts the admins). Nothing is kept on disk.
 */
final class ReceiptUpload
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    private const MAX_PIXELS_PER_SIDE = 10000;
    private const TYPES = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_WEBP => 'image/webp'];

    public function __construct(
        private readonly ReceiptSubmission $receiptSubmission,
        private readonly TelegramApiClient $telegramApiClient,
    ) {
    }

    /**
     * @throws CheckoutFailure receipt_not_manual, receipt_already_submitted, receipt_not_pending, receipt_missing,
     *                         receipt_too_large, receipt_invalid, receipt_upload_failed
     */
    public function submit(MiniAppUser $user, Payment $payment, ?UploadedFile $file): void
    {
        if (!ReceiptSubmission::isManual($payment)) {
            throw new CheckoutFailure('receipt_not_manual', 409);
        }
        match ($this->receiptSubmission->check($payment, $user->customer())) {
            ReceiptSubmission::NOT_YOURS => throw new CheckoutFailure('payment_not_found', 404),
            ReceiptSubmission::ALREADY_SUBMITTED => throw new CheckoutFailure('receipt_already_submitted', 409),
            ReceiptSubmission::NOT_PENDING => throw new CheckoutFailure('receipt_not_pending', 409),
            default => null,
        };

        if (!$file instanceof UploadedFile || !$file->isValid()) {
            throw new CheckoutFailure(UPLOAD_ERR_INI_SIZE === $file?->getError() || UPLOAD_ERR_FORM_SIZE === $file?->getError() ? 'receipt_too_large' : 'receipt_missing', 422);
        }
        $path = $file->getPathname();
        $size = (int) @filesize($path);
        if ($size > self::MAX_BYTES) {
            throw new CheckoutFailure('receipt_too_large', 422);
        }
        if ($size <= 0 || !$this->isImage($path)) {
            throw new CheckoutFailure('receipt_invalid', 422);
        }

        $order = $payment->getOrder();
        $fileId = $this->telegramApiClient->uploadPhoto($user->telegramUserId(), $path, sprintf('🧾 %s', (string) ($order->getTrackingCode() ?? $order->getId())));
        if (null === $fileId) {
            throw new CheckoutFailure('receipt_upload_failed', 502);
        }

        $this->receiptSubmission->submitPhoto($payment, $fileId);
    }

    /** The content (not the name or the browser's type) is a JPEG, PNG or WebP picture of a sane size. */
    private function isImage(string $path): bool
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $info = @getimagesize($path);
        if (!is_array($info) || !isset(self::TYPES[$info[2]]) || self::TYPES[$info[2]] !== $mime) {
            return false;
        }

        return $info[0] > 0 && $info[1] > 0 && $info[0] <= self::MAX_PIXELS_PER_SIDE && $info[1] <= self::MAX_PIXELS_PER_SIDE;
    }
}

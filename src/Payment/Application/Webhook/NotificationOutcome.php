<?php

declare(strict_types=1);

namespace App\Payment\Application\Webhook;

use App\Entity\Payment;
use App\Payment\Application\Settlement\SettlementResult;

final readonly class NotificationOutcome
{
    public function __construct(
        public int $httpStatus,
        /** machine-readable code, e.g. confirmed, needs_review, not_paid, payment_not_found, invalid_signature */
        public string $code,
        public ?Payment $payment = null,
        public ?SettlementResult $settlement = null,
    ) {
    }

    /**
     * Persian text for browser callbacks.
     */
    public function customerMessage(): string
    {
        if (null !== $this->settlement) {
            return $this->settlement->customerMessage();
        }

        return match ($this->code) {
            'already_confirmed' => 'پرداخت قبلاً تایید شده است.',
            'already_rejected' => 'این پرداخت رد شده است.',
            'already_needs_review', 'already_provisioning_failed' => 'پرداخت ثبت شده و در حال پیگیری است.',
            'payment_not_found' => 'پرداخت یافت نشد.',
            'not_paid', 'pending' => 'پرداخت تایید نشد.',
            'failed' => 'پرداخت ناموفق بود یا منقضی شده است.',
            'verification_failed' => 'خطا در بررسی پرداخت.',
            default => 'درخواست نامعتبر است.',
        };
    }
}

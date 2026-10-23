<?php

declare(strict_types=1);

namespace App\Payment\Application\Settlement;

use App\Entity\Payment;
use App\Entity\VpnService;
use App\Payment\Domain\Money\AmountMatch;
use App\Payment\Domain\PaymentStatus;

final readonly class SettlementResult
{
    /**
     * @param Payment|null $payment the managed, up-to-date payment (after a failed attempt the entity
     *                              manager is cleared, so callers must use this instance, not their own)
     */
    public function __construct(
        public SettlementOutcome $outcome,
        public string $message,
        public ?Payment $payment = null,
        public ?VpnService $vpnService = null,
        public ?AmountMatch $amountMatch = null,
    ) {
    }

    public function isConfirmed(): bool
    {
        return SettlementOutcome::CONFIRMED === $this->outcome;
    }

    public function isAlreadyProcessed(): bool
    {
        return SettlementOutcome::ALREADY_PROCESSED === $this->outcome;
    }

    /**
     * True when the payment is now safely recorded (confirmed, parked for review, or marked failed),
     * i.e. a gateway should not retry its notification.
     */
    public function isRecorded(): bool
    {
        return SettlementOutcome::ERROR !== $this->outcome;
    }

    /**
     * Short Persian text for the paying customer (return pages, callbacks).
     */
    public function customerMessage(): string
    {
        return match ($this->outcome) {
            SettlementOutcome::CONFIRMED => 'پرداخت با موفقیت تایید شد.',
            SettlementOutcome::ALREADY_PROCESSED => match ($this->payment?->getStatus()) {
                PaymentStatus::CONFIRMED => 'پرداخت قبلاً تایید شده است.',
                PaymentStatus::REJECTED => 'این پرداخت رد شده است.',
                default => 'پرداخت ثبت شده و در حال پیگیری است.',
            },
            SettlementOutcome::NEEDS_REVIEW => 'پرداخت دریافت شد و برای بررسی به پشتیبانی ارسال شد.',
            SettlementOutcome::PROVISIONING_FAILED => 'پرداخت دریافت شد؛ سرویس شما به‌زودی آماده می‌شود. نیازی به پرداخت مجدد نیست.',
            SettlementOutcome::REJECTED => 'این پرداخت رد شد.',
            SettlementOutcome::ORDER_ALREADY_FULFILLED => 'پرداخت دریافت شد و برای بررسی به پشتیبانی ارسال شد.',
            SettlementOutcome::WALLET_DEBIT_MISSING => 'این پرداخت از کیف پول تایید نشد؛ مبلغی از کیف پول شما برای آن کسر نشده است.',
            SettlementOutcome::TOPUP_NOTHING_TO_CREDIT => 'مبلغ دریافت‌شده برای شارژ کیف پول کمتر از ۱ تومان است و قابل واریز نیست؛ برای پیگیری با پشتیبانی تماس بگیرید.',
            SettlementOutcome::ERROR => 'ثبت پرداخت موقتاً ممکن نشد؛ لطفاً کمی بعد دوباره تلاش کنید.',
        };
    }
}

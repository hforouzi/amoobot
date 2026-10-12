<?php

declare(strict_types=1);

namespace App\Payment\Domain\Webhook;

/**
 * What a gateway extracted from an incoming callback/webhook request.
 *
 * It carries NO payment status: the core only uses it to authenticate the request and to find the
 * payment by references stored at createPayment time; whether it was paid is always decided by
 * verifyPayment() against the provider.
 */
final readonly class GatewayNotification
{
    /**
     * @param list<string>         $references gateway-side ids to match against stored payment references
     * @param array<string, mixed> $payload    request data, handed to verifyPayment() as hints only
     */
    private function __construct(
        public bool $authenticated,
        public int $rejectStatus,
        public string $rejectReason,
        public array $references,
        public array $payload,
        public bool $reportedNotPaid,
    ) {
    }

    /**
     * @param list<string|null>    $references
     * @param array<string, mixed> $payload
     */
    public static function accepted(array $references, array $payload, bool $reportedNotPaid = false): self
    {
        $clean = [];
        foreach ($references as $reference) {
            $reference = null === $reference ? '' : trim($reference);
            if ('' !== $reference && !in_array($reference, $clean, true)) {
                $clean[] = $reference;
            }
        }

        return new self(true, 200, '', $clean, $payload, $reportedNotPaid);
    }

    public static function rejected(int $status, string $reason): self
    {
        return new self(false, $status, $reason, [], [], false);
    }
}

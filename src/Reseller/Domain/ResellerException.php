<?php

declare(strict_types=1);

namespace App\Reseller\Domain;

/**
 * Why a reseller action was refused, as a stable error code (the bot and the admin pages word it). Nothing was written
 * when it is thrown.
 */
final class ResellerException extends \RuntimeException
{
    /**
     * @param WholesaleQuote|null $quote   price_changed: the price now (the caller shows a fresh confirmation)
     * @param int|null            $orderId already_processed: the order the first delivery created
     */
    public function __construct(
        public readonly string $error,
        public readonly ?WholesaleQuote $quote = null,
        public readonly ?int $orderId = null,
    ) {
        parent::__construct($error);
    }
}

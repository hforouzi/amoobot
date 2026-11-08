<?php

declare(strict_types=1);

namespace Amoobot\Plugin\HooshPay;

/**
 * Transport or protocol failure talking to HooshPay (timeout, TLS, non-JSON body, oversized body).
 * Messages are fixed texts: they never contain the API key, the Secret, an invoice uid or a URL.
 */
final class HooshPayException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $timeout = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}

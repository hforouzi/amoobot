<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http;

/**
 * A failed panel call. The message is always passed through SecretRedactor before it is stored here, so it is safe
 * for logs, flash messages and console output; the previous exception is never kept (its message may hold the token).
 */
final class PanelApiException extends \RuntimeException
{
    public function __construct(
        public readonly PanelFailure $failure,
        string $safeMessage,
        public readonly ?int $httpStatus = null,
        public readonly ?string $panelMessage = null,
    ) {
        parent::__construct($safeMessage);
    }
}

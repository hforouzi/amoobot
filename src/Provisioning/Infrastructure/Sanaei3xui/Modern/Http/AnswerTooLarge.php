<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http;

/**
 * Thrown inside ModernPanelHttpClient (from the on_progress callback or after the download) when an answer exceeds the
 * caller's size cap; it never leaves the client, which turns it into a PanelApiException.
 *
 * @internal
 */
final class AnswerTooLarge extends \RuntimeException
{
}

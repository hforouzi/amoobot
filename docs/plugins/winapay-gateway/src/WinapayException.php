<?php

declare(strict_types=1);

namespace Amoobot\Plugin\Winapay;

/**
 * Transport or protocol failure talking to Winapay (timeout, TLS, non-JSON body, oversized body).
 * Messages are safe to show to an admin: they never contain the MerchantID.
 */
final class WinapayException extends \RuntimeException
{
}

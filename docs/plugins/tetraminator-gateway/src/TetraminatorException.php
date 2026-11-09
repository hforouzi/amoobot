<?php

declare(strict_types=1);

namespace Amoobot\Plugin\Tetraminator;

/**
 * Transport or protocol failure talking to Tetraminator (timeout, TLS, non-JSON body, oversized body).
 * Messages are fixed texts: they never contain the API key, a pay_id or a URL.
 */
final class TetraminatorException extends \RuntimeException
{
}

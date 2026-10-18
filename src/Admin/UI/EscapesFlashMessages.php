<?php

declare(strict_types=1);

namespace App\Admin\UI;

/**
 * EasyAdmin renders flash messages as raw HTML, and panel errors carry text from the panel (or from exceptions):
 * every string flash is HTML-escaped here, so a controller can pass dynamic text as is.
 */
trait EscapesFlashMessages
{
    protected function addFlash(string $type, mixed $message): void
    {
        parent::addFlash($type, is_string($message) ? htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $message);
    }
}

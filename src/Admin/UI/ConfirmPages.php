<?php

declare(strict_types=1);

namespace App\Admin\UI;

use Symfony\Component\HttpFoundation\Response;

/**
 * The first step of an admin action that changes something: a GET page saying what will happen, whose form POSTs to the
 * same URL with a CSRF token (like admin/wallet/confirm.html.twig). Only that POST does it.
 */
trait ConfirmPages
{
    /**
     * @param list<array{0: string, 1?: array<string, string|int|float|null>}> $lines  translation keys (admin domain), each with its parameters
     * @param string|null                                                      $submit the button's label, null when the action cannot run (no form)
     * @param array<string, string>                                            $hidden extra fields of the form
     * @param string|null                                                      $typedName what the admin must type back
     */
    protected function confirmPage(string $title, array $lines, string $csrf, ?string $submit, string $cancelUrl, string $style = 'primary', array $hidden = [], ?string $typedName = null): Response
    {
        return $this->render('admin/action_confirm.html.twig', [
            'title' => $title,
            'lines' => $lines,
            'csrf' => $csrf,
            'submit' => $submit,
            'cancelUrl' => $cancelUrl,
            'style' => $style,
            'hidden' => $hidden,
            'typedName' => $typedName,
        ]);
    }
}

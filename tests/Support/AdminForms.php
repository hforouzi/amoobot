<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The admin's two-step actions and token-carrying forms, as a browser does them.
 */
trait AdminForms
{
    /** Opens an action's confirm page (ConfirmPages) and presses its button. */
    private function confirmAction(KernelBrowser $client, string $url): void
    {
        $page = $client->request('GET', $url);
        self::assertSame(200, $client->getResponse()->getStatusCode(), 'GET '.$url);
        $client->submit($page->filter('[data-action-confirm] form')->form());
    }

    /** The token of a form on the page. */
    private function csrfOf(KernelBrowser $client, string $url, string $form = '[data-action-confirm] form'): string
    {
        $page = $client->request('GET', $url);
        self::assertSame(200, $client->getResponse()->getStatusCode(), 'GET '.$url);

        return (string) $page->filter($form.' input[name="_token"]')->attr('value');
    }

    /**
     * Saves a settings tab with the page's token.
     *
     * @param array<string, string|null> $fields
     */
    private function saveSettings(KernelBrowser $client, string $tab, array $fields): void
    {
        $client->request('POST', '/admin/settings?tab='.$tab, $fields + ['_token' => $this->csrfOf($client, '/admin/settings?tab='.$tab, 'form.admin-settings-form')]);
    }
}

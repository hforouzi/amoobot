<?php

declare(strict_types=1);

namespace App\Wallet\UI;

use App\Entity\User;
use App\Wallet\Application\WalletService;
use EasyCorp\Bundle\EasyAdminBundle\Event\BeforeEntityDeletedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * The admin's "delete" of a customer who has a wallet (EasyAdmin delete and batch delete): refused before anything is
 * removed, with a clear flash message. The wallet ledger is financial history; the database refuses the delete as well
 * (ON DELETE RESTRICT), but as a raw foreign key error page. Customers without a wallet are deleted as before; the
 * permission to delete is unchanged.
 */
final class CustomerWithWalletDeleteGuard implements EventSubscriberInterface
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly RequestStack $requestStack,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Before the audit subscriber notes the delete.
        return [BeforeEntityDeletedEvent::class => ['refuseWithWallet', 10]];
    }

    public function refuseWithWallet(BeforeEntityDeletedEvent $event): void
    {
        $customer = $event->getEntityInstance();
        if (!$customer instanceof User || null === $this->wallets->find($customer)) {
            return;
        }

        $session = $this->requestStack->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('danger', new TranslatableMessage('flash.customer.delete_has_wallet', ['%id%' => (string) $customer->getId()], 'admin'));
        }
        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('admin_customer_index')));
    }
}

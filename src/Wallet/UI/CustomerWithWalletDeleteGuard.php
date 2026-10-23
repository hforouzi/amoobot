<?php

declare(strict_types=1);

namespace App\Wallet\UI;

use App\Entity\Reseller;
use App\Entity\User;
use App\Wallet\Application\WalletService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\EA;
use EasyCorp\Bundle\EasyAdminBundle\Event\BeforeCrudActionEvent;
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
 * (ON DELETE RESTRICT), but as a raw foreign key error page. A batch delete is refused as a whole, before anything is
 * deleted, when any selected customer has a wallet. A customer with a reseller profile is refused the same way (orders
 * and services point at the profile, ON DELETE RESTRICT). Customers without either are deleted as before; the permission
 * to delete is unchanged.
 */
final class CustomerWithWalletDeleteGuard implements EventSubscriberInterface
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Before the audit subscriber notes the delete.
        return [
            BeforeEntityDeletedEvent::class => ['refuseWithWallet', 10],
            BeforeCrudActionEvent::class => ['refuseBatchWithWallet', 10],
        ];
    }

    /**
     * A batch delete deletes the selected customers one by one: refused per customer, the ones before a customer with a
     * wallet would already be gone. So the whole batch is refused up front, before anything is deleted, when any of the
     * selected customers has a wallet.
     */
    public function refuseBatchWithWallet(BeforeCrudActionEvent $event): void
    {
        $context = $event->getAdminContext();
        if (null === $context || Action::BATCH_DELETE !== $context->getCrud()?->getCurrentAction() || User::class !== $context->getEntity()->getFqcn()) {
            return;
        }
        $ids = array_values(array_filter(array_map('intval', $context->getRequest()->request->all(EA::BATCH_ACTION_ENTITY_IDS)), static fn (int $id): bool => $id > 0));
        if ([] === $ids) {
            return;
        }
        $connection = $this->entityManager->getConnection();
        $withWallet = $connection->fetchFirstColumn('SELECT user_id FROM wallet WHERE user_id IN (?) ORDER BY user_id', [$ids], [ArrayParameterType::INTEGER]);
        $resellers = [] === $withWallet ? $connection->fetchFirstColumn('SELECT user_id FROM reseller WHERE user_id IN (?) ORDER BY user_id', [$ids], [ArrayParameterType::INTEGER]) : [];
        if ([] === $withWallet && [] === $resellers) {
            return;
        }

        $session = $this->requestStack->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('danger', [] !== $withWallet
                ? new TranslatableMessage('flash.customer.batch_delete_has_wallet', ['%ids%' => implode(', #', $withWallet)], 'admin')
                : new TranslatableMessage('flash.customer.batch_delete_is_reseller', ['%ids%' => implode(', #', $resellers)], 'admin'));
        }
        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('admin_customer_index')));
    }

    public function refuseWithWallet(BeforeEntityDeletedEvent $event): void
    {
        $customer = $event->getEntityInstance();
        if (!$customer instanceof User) {
            return;
        }
        $hasWallet = null !== $this->wallets->find($customer);
        if (!$hasWallet && null === $this->entityManager->getRepository(Reseller::class)->findOneBy(['user' => $customer])) {
            return;
        }

        $session = $this->requestStack->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('danger', new TranslatableMessage($hasWallet ? 'flash.customer.delete_has_wallet' : 'flash.customer.delete_is_reseller', ['%id%' => (string) $customer->getId()], 'admin'));
        }
        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('admin_customer_index')));
    }
}

<?php

declare(strict_types=1);

namespace App\Admin\UI;

use App\Entity\AdminUser;
use App\Entity\User;
use App\Entity\Wallet;
use App\Security\AdminActor;
use App\Wallet\Application\WalletAdministration;
use App\Wallet\Application\WalletQueries;
use App\Wallet\Application\WalletService;
use App\Wallet\Domain\WalletException;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Customer wallets in the web panel: the list (balance, credit limit, last activity) with the totals, and one wallet
 * with its full ledger. WALLETS_VIEW to look; the writes (manual adjustment with a mandatory reason and a confirmation
 * step, credit limit, freeze/unfreeze) need WALLETS_MANAGE (AdminCrudPermissions::ROUTES), are POST only with a CSRF
 * token, go through WalletService (WalletAdministration) and are audited.
 */
final class WalletAdminController extends AbstractController
{
    use TranslatedFlashes;

    private const PAGE_SIZE = 25;
    private const CSRF_PREFIX = 'wallet_admin_';
    private const CSRF_OPEN_PREFIX = 'wallet_admin_open_';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WalletService $wallets,
        private readonly WalletQueries $queries,
        private readonly WalletAdministration $administration,
    ) {
    }

    #[AdminRoute(path: '/wallets', name: 'wallets')]
    public function index(Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $search = trim((string) $request->query->get('q', ''));
        $result = $this->queries->wallets($page, self::PAGE_SIZE, $search);

        return $this->render('admin/wallet/index.html.twig', [
            'wallets' => $result['items'],
            'hasNext' => $result['hasNext'],
            'page' => $page,
            'search' => $search,
            'totals' => $this->queries->totals(),
        ]);
    }

    #[AdminRoute(path: '/wallets/{id}', name: 'wallet', options: ['requirements' => ['id' => '\d+']])]
    public function show(Request $request, int $id): Response
    {
        $wallet = $this->wallet($id);
        $page = max(1, $request->query->getInt('page', 1));
        $ledger = $this->queries->transactions($wallet, $page, self::PAGE_SIZE);

        return $this->render('admin/wallet/show.html.twig', [
            'wallet' => $wallet,
            'transactions' => $ledger['items'],
            'hasNext' => $ledger['hasNext'],
            'page' => $page,
            'formKey' => WalletAdministration::newFormKey(),
            'csrf' => self::CSRF_PREFIX.$wallet->getId(),
        ]);
    }

    /**
     * The customer's wallet. Looking never writes: without a wallet this shows an empty one (balance 0), and an admin who
     * may manage wallets can open it with a POST (customerOpen()) to adjust it.
     */
    #[AdminRoute(path: '/wallets/customer/{id}', name: 'wallet_customer', options: ['requirements' => ['id' => '\d+']])]
    public function customer(int $id): Response
    {
        $user = $this->entityManager->getRepository(User::class)->find($id) ?? throw new NotFoundHttpException();
        $wallet = $this->wallets->find($user);
        if ($wallet instanceof Wallet) {
            return $this->redirectToRoute('admin_wallet', ['id' => $wallet->getId()]);
        }

        return $this->render('admin/wallet/none.html.twig', [
            'customer' => $user,
            'csrf' => self::CSRF_OPEN_PREFIX.$user->getId(),
        ]);
    }

    /** Creates the customer's (empty) wallet, so an admin can adjust it before the customer ever used it. */
    #[AdminRoute(path: '/wallets/customer/{id}/open', name: 'wallet_customer_open', options: ['requirements' => ['id' => '\d+'], 'methods' => ['POST']])]
    public function customerOpen(Request $request, int $id): RedirectResponse
    {
        $user = $this->entityManager->getRepository(User::class)->find($id) ?? throw new NotFoundHttpException();
        $token = $request->request->get('_token');
        if (!is_string($token) || !$this->isCsrfTokenValid(self::CSRF_OPEN_PREFIX.$user->getId(), $token)) {
            $this->flash('danger', 'flash.csrf_expired');

            return $this->redirectToRoute('admin_wallet_customer', ['id' => $user->getId()]);
        }

        return $this->redirectToRoute('admin_wallet', ['id' => $this->wallets->walletFor($user)->getId()]);
    }

    /**
     * Manual credit / debit. The first POST shows what will happen (confirmation page); only the confirmed POST writes.
     * The form's key makes a repeated submit apply it once.
     */
    #[AdminRoute(path: '/wallets/{id}/adjust', name: 'wallet_adjust', options: ['requirements' => ['id' => '\d+'], 'methods' => ['POST']])]
    public function adjust(Request $request, int $id): Response
    {
        $wallet = $this->wallet($id);
        if (!$this->validToken($wallet, $request)) {
            return $this->back($wallet, 'flash.csrf_expired', 'danger');
        }
        $direction = (string) $request->request->get('direction', '');
        $amount = (string) $request->request->get('amount', '');
        $reason = (string) $request->request->get('reason', '');
        $formKey = (string) $request->request->get('form_key', '');
        $problem = $this->administration->adjustmentProblem($direction, $amount, $reason, $formKey);
        if (null !== $problem) {
            return $this->back($wallet, $problem, 'danger');
        }

        if ('1' !== $request->request->get('confirmed')) {
            return $this->render('admin/wallet/confirm.html.twig', [
                'wallet' => $wallet,
                'direction' => $direction,
                'amount' => (int) str_replace([',', '٬', ' '], '', $amount),
                'reason' => trim($reason),
                'formKey' => $formKey,
                'csrf' => self::CSRF_PREFIX.$wallet->getId(),
            ]);
        }

        try {
            $this->administration->adjust($wallet->getUser(), $direction, $amount, $reason, $formKey, $this->admin());
        } catch (WalletException $e) {
            return $this->back($wallet, 'wallet_admin.error.'.$e->error, 'danger');
        }

        return $this->back($wallet, 'wallet_admin.flash.adjusted', 'success');
    }

    #[AdminRoute(path: '/wallets/{id}/credit-limit', name: 'wallet_credit_limit', options: ['requirements' => ['id' => '\d+'], 'methods' => ['POST']])]
    public function creditLimit(Request $request, int $id): RedirectResponse
    {
        $wallet = $this->wallet($id);
        if (!$this->validToken($wallet, $request)) {
            return $this->back($wallet, 'flash.csrf_expired', 'danger');
        }
        try {
            $this->administration->setCreditLimit($wallet->getUser(), $request->request->get('credit_limit', ''), $this->admin());
        } catch (WalletException $e) {
            return $this->back($wallet, 'wallet_admin.error.'.$e->error, 'danger');
        } catch (\InvalidArgumentException) {
            return $this->back($wallet, 'wallet_admin.error.amount', 'danger');
        }

        return $this->back($wallet, 'wallet_admin.flash.credit_limit', 'success');
    }

    #[AdminRoute(path: '/wallets/{id}/freeze', name: 'wallet_freeze', options: ['requirements' => ['id' => '\d+'], 'methods' => ['POST']])]
    public function freeze(Request $request, int $id): RedirectResponse
    {
        return $this->setFrozen($request, $id, true);
    }

    #[AdminRoute(path: '/wallets/{id}/unfreeze', name: 'wallet_unfreeze', options: ['requirements' => ['id' => '\d+'], 'methods' => ['POST']])]
    public function unfreeze(Request $request, int $id): RedirectResponse
    {
        return $this->setFrozen($request, $id, false);
    }

    private function setFrozen(Request $request, int $id, bool $frozen): RedirectResponse
    {
        $wallet = $this->wallet($id);
        if (!$this->validToken($wallet, $request)) {
            return $this->back($wallet, 'flash.csrf_expired', 'danger');
        }
        try {
            $this->administration->setFrozen($wallet->getUser(), $frozen, $this->admin());
        } catch (WalletException $e) {
            return $this->back($wallet, 'wallet_admin.error.'.$e->error, 'danger');
        }

        return $this->back($wallet, $frozen ? 'wallet_admin.flash.frozen' : 'wallet_admin.flash.unfrozen', 'success');
    }

    private function wallet(int $id): Wallet
    {
        $wallet = $this->entityManager->getRepository(Wallet::class)->find($id);
        if (!$wallet instanceof Wallet) {
            throw new NotFoundHttpException();
        }
        $this->entityManager->refresh($wallet);

        return $wallet;
    }

    private function validToken(Wallet $wallet, Request $request): bool
    {
        $token = $request->request->get('_token');

        return is_string($token) && $this->isCsrfTokenValid(self::CSRF_PREFIX.$wallet->getId(), $token);
    }

    private function admin(): AdminActor
    {
        $user = $this->getUser();
        if (!$user instanceof AdminUser) {
            throw $this->createAccessDeniedException();
        }

        return AdminActor::fromAdminUser($user);
    }

    private function back(Wallet $wallet, string $message, string $type): RedirectResponse
    {
        $this->flash($type, $message);

        return $this->redirectToRoute('admin_wallet', ['id' => $wallet->getId()]);
    }
}

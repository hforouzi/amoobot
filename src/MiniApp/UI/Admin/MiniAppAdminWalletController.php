<?php

declare(strict_types=1);

namespace App\MiniApp\UI\Admin;

use App\Entity\User;
use App\MiniApp\Admin\AdminActions;
use App\MiniApp\Admin\AdminText;
use App\MiniApp\Checkout\CheckoutFailure;
use App\MiniApp\Checkout\CheckoutIdempotency;
use App\MiniApp\Security\MiniAppUser;
use App\MiniApp\UI\MiniAppFragments;
use App\MiniApp\UI\MiniAppLocale;
use App\Wallet\Application\WalletAdministration;
use App\Wallet\Application\WalletQueries;
use App\Wallet\Application\WalletService;
use App\Wallet\Domain\WalletException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A customer's wallet in the Mini App admin: the ledger (WALLETS_VIEW; the balance card is on the customer's detail)
 * and a manual adjustment (WALLETS_MANAGE): credit or debit with a mandatory reason, confirmed in Telegram's dialog
 * (AdminActions' confirm token), once per Idempotency-Key, through WalletService and audited with source miniapp.
 */
#[IsGranted(MiniAppUser::ROLE)]
final class MiniAppAdminWalletController extends AbstractController
{
    private const PAGE_SIZE = 20;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WalletService $wallets,
        private readonly WalletQueries $queries,
        private readonly WalletAdministration $administration,
        private readonly AdminActions $actions,
        private readonly MiniAppFragments $fragments,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/app/admin/customers/{id}/wallet', name: 'miniapp_admin_customer_wallet', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.customers'], methods: ['GET'])]
    public function ledger(Request $request, int $id): Response
    {
        $customer = $this->customer($id);
        $wallet = $this->wallets->find($customer);
        $page = AdminQuery::page($request);
        $ledger = null !== $wallet ? $this->queries->transactions($wallet, $page, self::PAGE_SIZE) : ['items' => [], 'hasNext' => false];

        return $this->fragments->render($request, 'miniapp/admin/wallet.html.twig', [
            'customer' => AdminCustomerView::summary($customer),
            'wallet' => $wallet,
            'transactions' => $ledger['items'],
            'page' => $page,
            'hasNext' => $ledger['hasNext'],
        ]);
    }

    #[Route('/app/api/admin/customers/{id}/wallet/adjust', name: 'miniapp_admin_customer_wallet_adjust', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.customers'], methods: ['POST'])]
    public function adjust(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        $customer = $this->customer($id);
        $body = json_decode($request->getContent(), true);
        $body = is_array($body) ? $body : $request->request->all();
        $direction = (string) ($body['direction'] ?? '');
        $amount = AdminText::integer($body['amount'] ?? null);
        $reason = trim((string) ($body['reason'] ?? ''));
        // The call's Idempotency-Key is the adjustment's key: a double tap or retry is applied once.
        $key = (string) $request->headers->get(CheckoutIdempotency::HEADER);
        $locale = MiniAppLocale::fromRequest($request);

        $problem = $this->administration->adjustmentProblem($direction, null === $amount ? '' : (string) $amount, $reason, $key);
        if (null !== $problem && 'wallet_admin.error.form_expired' !== $problem) {
            $error = substr($problem, strlen('wallet_admin.error.'));

            return AdminActions::json(['error' => 'wallet_'.$error, 'message' => $this->translator->trans('admin.wallet.error.'.$error, [], 'miniapp', $locale)], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $confirm = $this->translator->trans('admin.wallet.confirm.'.$direction, ['%amount%' => number_format((int) $amount), '%customer%' => AdminCustomerView::summary($customer)['name'] ?: ('#'.$id)], 'miniapp', $locale);

        return $this->actions->run($request, $user, $id, $confirm, function () use ($customer, $direction, $amount, $reason, $key, $user, $locale): array {
            $admin = $user->admin ?? throw new CheckoutFailure('forbidden', Response::HTTP_FORBIDDEN);
            try {
                $transaction = $this->administration->adjust($customer, $direction, (string) $amount, $reason, $key, $admin);
            } catch (WalletException $e) {
                return ['error' => $e->error, 'message' => $this->translator->trans('admin.wallet.error.'.$e->error, [], 'miniapp', $locale), 'status' => Response::HTTP_CONFLICT];
            }

            return ['message' => $this->translator->trans('admin.wallet.adjusted', ['%balance%' => number_format($transaction->getBalanceAfter())], 'miniapp', $locale), 'balance' => $transaction->getBalanceAfter()];
        });
    }

    private function customer(int $id): User
    {
        $customer = $this->entityManager->find(User::class, $id);

        return $customer instanceof User ? $customer : throw new NotFoundHttpException();
    }
}

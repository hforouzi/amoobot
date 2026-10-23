<?php

declare(strict_types=1);

namespace App\MiniApp\UI;

use App\MiniApp\Security\MiniAppUser;
use App\Wallet\Application\WalletQueries;
use App\Wallet\Application\WalletService;
use App\Wallet\Application\WalletSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "My wallet" in the Mini App: balance, credit limit, frozen notice, the top-up (quick amounts or an amount, sent to
 * MiniAppCheckoutController::walletTopUp) and the ledger, paged. Read only; 404 while the wallet is switched off.
 * Viewing never creates a wallet.
 */
#[IsGranted(MiniAppUser::ROLE)]
final class MiniAppWalletController extends AbstractController
{
    private const PAGE_SIZE = 10;

    public function __construct(
        private readonly MiniAppFragments $fragments,
        private readonly WalletSettings $settings,
        private readonly WalletService $wallets,
        private readonly WalletQueries $queries,
    ) {
    }

    #[Route('/app/view/wallet', name: 'miniapp_wallet', defaults: ['_miniapp_section' => 'services'], methods: ['GET'])]
    public function wallet(Request $request, #[CurrentUser] MiniAppUser $user): Response
    {
        if (!$this->settings->enabled()) {
            throw new NotFoundHttpException();
        }
        $page = max(1, min(1000, $request->query->getInt('page', 1)));
        $wallet = $this->wallets->find($user->customer());
        $transactions = null !== $wallet ? $this->queries->transactions($wallet, $page, self::PAGE_SIZE) : ['items' => [], 'hasNext' => false];

        return $this->fragments->render($request, 'miniapp/view/wallet.html.twig', [
            'wallet' => $wallet,
            'transactions' => $transactions['items'],
            'page' => $page,
            'hasNext' => $transactions['hasNext'],
            'quickAmounts' => $this->settings->quickAmounts(),
            'topUpMin' => $this->settings->topUpMin(),
            'topUpMax' => $this->settings->topUpMax(),
        ]);
    }
}

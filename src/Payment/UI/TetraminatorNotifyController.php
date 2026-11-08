<?php

declare(strict_types=1);

namespace App\Payment\UI;

use App\Entity\Payment;
use App\Payment\Application\Reconcile\TetraminatorReconciler;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Tetraminator's webhook (docs/plugins/tetraminator-gateway/design.md, decision 2):
 * GET /payment/notify/tetraminator/{paymentId}/{token}.
 *
 * The provider's GET has no signature and no body, so it proves nothing: it only rings the doorbell for one inquiry.
 * The id selects one row by primary key (a deliberate exception to SDK rule 2), and the token, whose SHA-256 the
 * plugin stored in Payment::authority, authenticates it. Steps, stopping at the first failure:
 *
 *   1. per client IP limit (payment_notify_ip), 2. formats (no database yet), 3. the row: Tetraminator gateway and
 *   hash_equals() on the token hash, 4. per payment limit (payment_notify_payment, so a valid URL cannot hammer the
 *   provider), 5. TetraminatorReconciler::reconcile(), inline.
 *
 * Every outcome answers the same bytes (HTTP 200, one constant page, the same headers): a caller learns nothing about
 * ids, tokens, limits or payment state. The URL holds a credential, so it is never logged here (nginx: access_log off
 * for /payment/notify/), and the firewall gives this path no session.
 */
final class TetraminatorNotifyController
{
    public const PAGE = '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><meta name="robots" content="noindex, nofollow"><title>OK</title></head>'
        .'<body><p>دریافت شد. می‌توانید به ربات برگردید.</p><p dir="ltr" lang="en">Received. You can go back to the bot.</p></body></html>';
    public const ID_PATTERN = '/^\d{1,18}$/D';
    public const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{43}$/D';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TetraminatorReconciler $reconciler,
        #[Autowire(service: 'limiter.payment_notify_ip')]
        private readonly RateLimiterFactory $ipLimiter,
        #[Autowire(service: 'limiter.payment_notify_payment')]
        private readonly RateLimiterFactory $paymentLimiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Anything after the prefix reaches this action, so that a malformed URL gets the same page as a wrong token. */
    #[Route('/payment/notify/tetraminator/{paymentId}/{token}', name: 'payment_notify_tetraminator', requirements: ['paymentId' => '[^/]+', 'token' => '.+'], methods: ['GET'])]
    public function notify(Request $request, string $paymentId, string $token): Response
    {
        try {
            $this->handle((string) $request->getClientIp(), $paymentId, $token);
        } catch (\Throwable $e) {
            // Neither the URL nor the message: an exception text may quote the token.
            $this->logger->error('Tetraminator notify failed.', ['error' => $e::class]);
        }

        return self::page();
    }

    private function handle(string $clientIp, string $paymentId, string $token): void
    {
        if (!$this->ipLimiter->create($clientIp)->consume()->isAccepted()) {
            return;
        }
        if (1 !== preg_match(self::ID_PATTERN, $paymentId) || 1 !== preg_match(self::TOKEN_PATTERN, $token)) {
            return;
        }

        $payment = $this->entityManager->find(Payment::class, (int) $paymentId);
        $storedHash = $payment instanceof Payment ? (string) $payment->getAuthority() : '';
        if (!$payment instanceof Payment || TetraminatorReconciler::PLUGIN_CODE !== $payment->getGateway()?->getPluginCode()
            || 64 !== strlen($storedHash) || !hash_equals($storedHash, hash('sha256', $token))) {
            return;
        }

        $id = (int) $payment->getId();
        if (!$this->paymentLimiter->create('payment_'.$id)->consume()->isAccepted()) {
            $this->logger->warning('Tetraminator notify: over the per-payment limit, no inquiry.', ['payment_id' => $id]);

            return;
        }

        $this->reconciler->reconcile($id, TetraminatorReconciler::SOURCE_WEBHOOK);
    }

    private static function page(): Response
    {
        $response = new Response(self::PAGE, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8']);
        // Symfony adds "private" to a Cache-Control without a public/private directive anyway.
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}

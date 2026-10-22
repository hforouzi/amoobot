<?php

declare(strict_types=1);

namespace App\MiniApp\Admin;

use App\MiniApp\Checkout\CheckoutFailure;
use App\MiniApp\Checkout\CheckoutIdempotency;
use App\MiniApp\Security\MiniAppUser;
use App\MiniApp\UI\MiniAppLocale;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Runs one state-changing Mini App admin call (the route's permission is already checked by MiniAppAdminGuard and
 * the CSRF token by the firewall):
 *  1. a money or destructive action without a valid confirm token is answered 428 with a fresh token and the text
 *     to confirm (AdminConfirmTokens); the app shows Telegram's confirm dialog and repeats the call with the token;
 *  2. per-admin rate limit (limiter miniapp_admin_action);
 *  3. once per Idempotency-Key (CheckoutIdempotency::claimOnce, the M2 table): a double tap or a retry gets the first
 *     answer, a parallel duplicate is refused while the first one runs.
 * The action returns its answer: ['message' => ..., 'status' => HTTP status (default 200)]; it audits itself.
 */
final class AdminActions
{
    public function __construct(
        private readonly AdminConfirmTokens $confirmTokens,
        private readonly CheckoutIdempotency $idempotency,
        private readonly TranslatorInterface $translator,
        #[Autowire(service: 'limiter.miniapp_admin_action')]
        private readonly RateLimiterFactory $limiter,
    ) {
    }

    /**
     * @param string|null                       $confirm the text to confirm (money / destructive actions), null: no server confirm step
     * @param callable(): array<string, mixed> $action
     */
    public function run(Request $request, MiniAppUser $user, int $target, ?string $confirm, callable $action): JsonResponse
    {
        $route = (string) $request->attributes->get('_route');
        if (null !== $confirm && !$this->confirmTokens->isValid($user, $route, $target, $request->headers->get(AdminConfirmTokens::HEADER))) {
            return self::json(['error' => 'confirm_required', 'confirm' => ['token' => $this->confirmTokens->issue($user, $route, $target), 'message' => $confirm]], Response::HTTP_PRECONDITION_REQUIRED);
        }

        try {
            if (!$this->limiter->create('admin:'.$user->telegramUserId())->consume()->isAccepted()) {
                throw new CheckoutFailure('rate_limited', Response::HTTP_TOO_MANY_REQUESTS);
            }
            $answer = $this->idempotency->claimOnce($user->customer(), $request->headers->get(CheckoutIdempotency::HEADER), 'admin:'.$route.':'.$target, $action);
        } catch (CheckoutFailure $failure) {
            return self::json(['error' => $failure->error, 'message' => $this->message($failure->error, $request)], $failure->status);
        }

        $status = (int) ($answer['status'] ?? Response::HTTP_OK);
        unset($answer['status']);

        return self::json($answer, $status);
    }

    public function message(string $key, Request $request): string
    {
        $locale = MiniAppLocale::fromRequest($request);
        $id = 'admin.error.'.$key;
        $text = $this->translator->trans($id, [], 'miniapp', $locale);

        return $text === $id ? $this->translator->trans('state.error', [], 'miniapp', $locale) : $text;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function json(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}

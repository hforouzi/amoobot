<?php

declare(strict_types=1);

namespace App\MiniApp\UI\Reseller;

use App\MiniApp\Checkout\CheckoutFailure;
use App\MiniApp\Checkout\CheckoutIdempotency;
use App\MiniApp\Reseller\InvalidResellerInput;
use App\MiniApp\Reseller\MiniAppResellerAccess;
use App\MiniApp\Reseller\ResellerInput;
use App\MiniApp\Security\MiniAppUser;
use App\MiniApp\UI\MiniAppFragments;
use App\MiniApp\UI\MiniAppLocale;
use App\Reseller\Application\ResellerCustomers;
use App\Reseller\Domain\ResellerCustomerInvalid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A reseller's own customer labels (docs/design/reseller-and-claim.md, S3): the list (with each label's services,
 * active count and soonest expiry), one label with its services, create / edit / delete. A label is only what he typed;
 * nothing here reads or shows a Telegram account. Every id is checked to be his (ResellerCustomers; 404 otherwise),
 * every value exactly (422 with the field's error, nothing trimmed). Creating runs once per Idempotency-Key (a double
 * tap is one label); writes are rate limited per Telegram user (miniapp_reseller). CSRF by the firewall.
 */
#[IsGranted(MiniAppUser::ROLE)]
final class MiniAppResellerCustomerController extends AbstractController
{
    public function __construct(
        private readonly MiniAppFragments $fragments,
        private readonly MiniAppResellerAccess $access,
        private readonly ResellerCustomers $customers,
        private readonly CheckoutIdempotency $idempotency,
        private readonly TranslatorInterface $translator,
        #[Autowire(service: 'limiter.miniapp_reseller')]
        private readonly RateLimiterFactory $limiter,
    ) {
    }

    #[Route('/app/view/reseller/customers', name: 'miniapp_reseller_customers', defaults: ['_miniapp_section' => 'reseller'], methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] MiniAppUser $user): Response
    {
        $reseller = $this->access->require($user);
        try {
            $query = ResellerInput::search($request);
            $page = ResellerInput::page($request);
        } catch (InvalidResellerInput) {
            throw new NotFoundHttpException();
        }

        return $this->fragments->render($request, 'miniapp/reseller/customers.html.twig', [
            'list' => $this->customers->list($reseller, $query, $page),
            'query' => $query,
        ]);
    }

    #[Route('/app/view/reseller/customers/new', name: 'miniapp_reseller_customer_new', defaults: ['_miniapp_section' => 'reseller'], methods: ['GET'])]
    public function newForm(Request $request, #[CurrentUser] MiniAppUser $user): Response
    {
        $this->access->require($user);

        return $this->fragments->render($request, 'miniapp/reseller/customer_form.html.twig', ['customer' => null]);
    }

    #[Route('/app/view/reseller/customers/{id}', name: 'miniapp_reseller_customer', requirements: ['id' => '[1-9]\d{0,9}'], defaults: ['_miniapp_section' => 'reseller'], methods: ['GET'])]
    public function show(Request $request, #[CurrentUser] MiniAppUser $user, int $id): Response
    {
        $reseller = $this->access->require($user);
        $customer = $this->customers->find($reseller, $id) ?? throw new NotFoundHttpException();

        return $this->fragments->render($request, 'miniapp/reseller/customer.html.twig', [
            'customer' => $customer,
            'services' => $this->customers->servicesOf($reseller, $customer),
            'ownerId' => $reseller->getUser()->getId(),
        ]);
    }

    #[Route('/app/view/reseller/customers/{id}/edit', name: 'miniapp_reseller_customer_edit', requirements: ['id' => '[1-9]\d{0,9}'], defaults: ['_miniapp_section' => 'reseller'], methods: ['GET'])]
    public function editForm(Request $request, #[CurrentUser] MiniAppUser $user, int $id): Response
    {
        $reseller = $this->access->require($user);
        $customer = $this->customers->find($reseller, $id) ?? throw new NotFoundHttpException();

        return $this->fragments->render($request, 'miniapp/reseller/customer_form.html.twig', ['customer' => $customer]);
    }

    #[Route('/app/api/reseller/customers', name: 'miniapp_reseller_customer_create', defaults: ['_miniapp_section' => 'reseller'], methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] MiniAppUser $user): JsonResponse
    {
        $reseller = $this->access->require($user);
        $locale = MiniAppLocale::fromRequest($request);
        if (!$this->limiter->create($user->telegramUserId())->consume()->isAccepted()) {
            return $this->failure('rate_limited', Response::HTTP_TOO_MANY_REQUESTS, $locale);
        }
        try {
            $answer = $this->idempotency->once($user->customer(), $request->headers->get(CheckoutIdempotency::HEADER), 'reseller_customer_create', function () use ($reseller, $request, $locale): array {
                $customer = $this->customers->create($reseller, ResellerInput::body($request));

                return ['redirect' => $this->generateUrl('miniapp_reseller_customer', ['id' => $customer->getId()]), 'message' => $this->translator->trans('reseller.customer.saved', [], 'miniapp', $locale)];
            });
        } catch (ResellerCustomerInvalid $e) {
            return $this->invalid($e, $locale);
        } catch (CheckoutFailure $failure) {
            return $this->failure($failure->error, $failure->status, $locale);
        }

        return self::answer($answer);
    }

    #[Route('/app/api/reseller/customers/{id}', name: 'miniapp_reseller_customer_update', requirements: ['id' => '[1-9]\d{0,9}'], defaults: ['_miniapp_section' => 'reseller'], methods: ['POST'])]
    public function update(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        $reseller = $this->access->require($user);
        $locale = MiniAppLocale::fromRequest($request);
        if (!$this->limiter->create($user->telegramUserId())->consume()->isAccepted()) {
            return $this->failure('rate_limited', Response::HTTP_TOO_MANY_REQUESTS, $locale);
        }
        try {
            $customer = $this->customers->update($reseller, $id, ResellerInput::body($request));
        } catch (ResellerCustomerInvalid $e) {
            return $this->invalid($e, $locale);
        }
        if (null === $customer) {
            return $this->failure('not_found', Response::HTTP_NOT_FOUND, $locale);
        }

        return self::answer(['redirect' => $this->generateUrl('miniapp_reseller_customer', ['id' => $customer->getId()]), 'message' => $this->translator->trans('reseller.customer.saved', [], 'miniapp', $locale)]);
    }

    /** Deletes the label; its services stay as they are (only unassigned). */
    #[Route('/app/api/reseller/customers/{id}/delete', name: 'miniapp_reseller_customer_delete', requirements: ['id' => '[1-9]\d{0,9}'], defaults: ['_miniapp_section' => 'reseller'], methods: ['POST'])]
    public function delete(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        $reseller = $this->access->require($user);
        $locale = MiniAppLocale::fromRequest($request);
        if (!$this->customers->delete($reseller, $id)) {
            return $this->failure('not_found', Response::HTTP_NOT_FOUND, $locale);
        }

        return self::answer(['redirect' => $this->generateUrl('miniapp_reseller_customers'), 'message' => $this->translator->trans('reseller.customer.deleted', [], 'miniapp', $locale)]);
    }

    private function invalid(ResellerCustomerInvalid $e, string $locale): JsonResponse
    {
        $errors = [];
        foreach ($e->errors as $field => $codes) {
            $errors[$field] = array_map(fn (string $code): string => $this->translator->trans('reseller.customer.error.'.$code, [], 'miniapp', $locale), $codes);
        }

        return self::answer(['error' => 'invalid', 'errors' => $errors, 'message' => $this->translator->trans('reseller.customer.error.form', [], 'miniapp', $locale)], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function failure(string $error, int $status, string $locale): JsonResponse
    {
        $key = match ($error) {
            'not_found' => 'reseller.error.not_found',
            default => 'checkout.message.'.$error,
        };
        $text = $this->translator->trans($key, [], 'miniapp', $locale);

        return self::answer(['error' => $error, 'message' => $text === $key ? $this->translator->trans('state.error', [], 'miniapp', $locale) : $text], $status);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function answer(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}

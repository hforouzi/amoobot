<?php

declare(strict_types=1);

namespace App\Payment\UI;

use App\Entity\PaymentGateway;
use App\Payment\Application\Webhook\GatewayNotificationProcessor;
use App\Payment\Application\Webhook\NotificationOutcome;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Domain\Webhook\NotificationAwareGatewayInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Single entry point for gateway notifications: /payment/webhook/{type}/{code}
 *
 *   type: zibal | nowpayments | custom_api (custom-api) | plugin
 *   code: plugin code for plugins; gateway id or "default" (= every active gateway of that type)
 *
 * The historical URLs below are thin aliases so gateway dashboards and stored callback URLs keep
 * working. All of them run GatewayNotificationProcessor (auth -> stored-reference lookup ->
 * verifyPayment -> PaymentSettlementService).
 */
final class PaymentWebhookController
{
    private const TYPES = [
        'zibal' => PaymentGatewayType::ZIBAL,
        'nowpayments' => PaymentGatewayType::NOWPAYMENTS,
        'custom_api' => PaymentGatewayType::CUSTOM_API,
        'custom-api' => PaymentGatewayType::CUSTOM_API,
        'plugin' => 'plugin',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GatewayNotificationProcessor $processor,
    ) {
    }

    #[Route('/payment/webhook/{type}/{code}', name: 'payment_webhook', requirements: ['type' => 'zibal|nowpayments|custom_api|custom-api|plugin', 'code' => '[A-Za-z0-9_.-]+'], methods: ['GET', 'POST'])]
    public function webhook(Request $request, string $type, string $code): JsonResponse
    {
        return $this->json($this->run($request, $type, $code, NotificationAwareGatewayInterface::CHANNEL_WEBHOOK));
    }

    #[Route('/payment/webhook/nowpayments', name: 'payment_webhook_nowpayments', methods: ['POST'], priority: 10)]
    public function nowPaymentsAlias(Request $request): JsonResponse
    {
        return $this->json($this->run($request, 'nowpayments', 'default', NotificationAwareGatewayInterface::CHANNEL_WEBHOOK));
    }

    #[Route('/payment/webhook/custom-api/{gatewayId}', name: 'payment_webhook_custom_api', requirements: ['gatewayId' => '\d+'], methods: ['POST'], priority: 10)]
    public function customApiWebhookAlias(Request $request, string $gatewayId): JsonResponse
    {
        return $this->json($this->run($request, 'custom_api', $gatewayId, NotificationAwareGatewayInterface::CHANNEL_WEBHOOK));
    }

    #[Route('/payment/webhook/plugin/{code}', name: 'payment_webhook_plugin', methods: ['POST'], priority: 10)]
    public function pluginWebhookAlias(Request $request, string $code): JsonResponse
    {
        return $this->json($this->run($request, 'plugin', $code, NotificationAwareGatewayInterface::CHANNEL_WEBHOOK));
    }

    #[Route('/payment/callback/zibal', name: 'payment_callback_zibal', methods: ['GET', 'POST'])]
    public function zibalCallback(Request $request): Response
    {
        return $this->html($this->run($request, 'zibal', 'default', NotificationAwareGatewayInterface::CHANNEL_CALLBACK));
    }

    #[Route('/payment/callback/custom-api/{gatewayId}', name: 'payment_callback_custom_api', requirements: ['gatewayId' => '\d+'], methods: ['GET', 'POST'])]
    public function customApiCallback(Request $request, string $gatewayId): Response
    {
        return $this->html($this->run($request, 'custom_api', $gatewayId, NotificationAwareGatewayInterface::CHANNEL_CALLBACK));
    }

    #[Route('/payment/callback/plugin/{code}', name: 'payment_callback_plugin', methods: ['GET', 'POST'])]
    public function pluginCallback(Request $request, string $code): Response
    {
        return $this->html($this->run($request, 'plugin', $code, NotificationAwareGatewayInterface::CHANNEL_CALLBACK));
    }

    private function run(Request $request, string $type, string $code, string $channel): NotificationOutcome
    {
        $gatewayType = self::TYPES[$type] ?? null;
        if (null === $gatewayType) {
            return new NotificationOutcome(404, 'gateway_not_found');
        }

        $gateways = $this->activeGateways($gatewayType, trim($code));
        $source = sprintf('%s_%s', 'plugin' === $gatewayType ? 'plugin_'.$code : $gatewayType, $channel);

        return $this->processor->process($gateways, $request, $channel, $source);
    }

    /**
     * @return list<PaymentGateway>
     */
    private function activeGateways(string $gatewayType, string $code): array
    {
        $repository = $this->entityManager->getRepository(PaymentGateway::class);
        if ('plugin' === $gatewayType) {
            return '' === $code ? [] : $repository->findBy(['pluginCode' => $code, 'isActive' => true]);
        }

        $criteria = ['type' => $gatewayType, 'isActive' => true];
        if ('default' !== $code) {
            if (1 !== preg_match('/^\d+$/', $code)) {
                return [];
            }
            $criteria['id'] = (int) $code;
        }

        return $repository->findBy($criteria, ['id' => 'ASC']);
    }

    private function json(NotificationOutcome $outcome): JsonResponse
    {
        return new JsonResponse(['ok' => $outcome->httpStatus < 400, 'message' => $outcome->code], $outcome->httpStatus);
    }

    private function html(NotificationOutcome $outcome): Response
    {
        return new Response(sprintf(
            '<html><head><meta charset="UTF-8"></head><body><h3>%s</h3></body></html>',
            htmlspecialchars($outcome->customerMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        ), $outcome->httpStatus);
    }
}

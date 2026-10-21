<?php

declare(strict_types=1);

namespace App\Shared\UI;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liveness/readiness probe for the reverse proxy, deploy script and uptime monitors.
 * Public and deliberately uninformative: only "ok" or "error", never versions or error details.
 */
class HealthController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/healthz', name: 'healthz', methods: ['GET', 'HEAD'])]
    public function __invoke(): JsonResponse
    {
        try {
            $this->connection->executeQuery('SELECT 1')->fetchOne();
        } catch (\Throwable $e) {
            $this->logger->error('Health check: database unreachable', ['exception' => $e]);

            return $this->noStore(new JsonResponse(['status' => 'error'], 503));
        }

        return $this->noStore(new JsonResponse(['status' => 'ok']));
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}

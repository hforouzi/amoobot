<?php

declare(strict_types=1);

namespace App\Subscription\UI;

use App\Entity\AppDownload;
use App\Subscription\Application\AppDownloadStorage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public app downloads (/d/{id}): an uploaded installer streamed from outside public/ (Range requests supported, so
 * a large APK can resume), or a redirect to an external page. A download is counted when the response starts at the
 * first byte (the whole file, or a range from byte 0); resumed parts and HEAD requests are not.
 */
final class AppDownloadController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AppDownloadStorage $storage,
        #[Autowire(service: 'limiter.app_download')]
        private readonly RateLimiterFactory $limiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/d/{id}', name: 'app_download', requirements: ['id' => '\d+'], methods: ['GET', 'HEAD'])]
    public function download(Request $request, int $id): Response
    {
        $limit = $this->limiter->create((string) $request->getClientIp())->consume();
        if (!$limit->isAccepted()) {
            return $this->headers(new Response('', Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time())]));
        }

        $download = $this->entityManager->find(AppDownload::class, $id);
        if (!$download instanceof AppDownload || !$download->isActive()) {
            return $this->notFound();
        }

        if (!$download->isFile()) {
            $url = (string) $download->getExternalUrl();
            if (1 !== preg_match('#^https?://#i', $url)) {
                return $this->notFound();
            }
            if (!$request->isMethod('HEAD')) {
                $this->count($download);
            }

            return $this->headers(new RedirectResponse($url));
        }

        if (!$this->storage->exists($download)) {
            $this->logger->error('App download file is missing.', ['download_id' => $id]);

            return $this->notFound();
        }

        $response = new BinaryFileResponse($this->storage->path($download), Response::HTTP_OK, [], false, null, false, false);
        $response->headers->set('Content-Type', (string) ($download->getMimeType() ?: 'application/octet-stream'));
        $name = (string) $download->getOriginalName();
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $name, $name);
        // BinaryFileResponse::prepare() answers Range requests (206 / 416) and sets Accept-Ranges.
        $response->prepare($request);
        $fromStart = Response::HTTP_OK === $response->getStatusCode() || str_starts_with((string) $response->headers->get('Content-Range'), 'bytes 0-');
        if ($fromStart && !$request->isMethod('HEAD')) {
            $this->count($download);
        }

        return $this->headers($response);
    }

    private function count(AppDownload $download): void
    {
        // Atomic: concurrent downloads do not lose counts.
        $this->entityManager->getConnection()->executeStatement('UPDATE app_download SET download_count = download_count + 1 WHERE id = ?', [$download->getId()]);
    }

    private function notFound(): Response
    {
        return $this->headers(new Response('Not found', Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/plain; charset=UTF-8']));
    }

    private function headers(Response $response): Response
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}

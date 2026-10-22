<?php

declare(strict_types=1);

namespace App\MiniApp\Session;

use App\Entity\MiniAppSession;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Server-side Mini App sessions: a 256-bit random token handed to the client once (only its SHA-256 is stored), valid
 * for 12 hours, only for the Telegram user it was issued to and the user agent that asked for it, revocable.
 * The CSRF token is derived from the session token, so it needs no storage and dies with the session.
 */
final class MiniAppSessionManager
{
    public const TTL_SECONDS = 12 * 3600;
    private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{43}$/';
    /** lastSeenAt is written at most this often (not on every request). */
    private const TOUCH_INTERVAL_SECONDS = 300;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        #[\SensitiveParameter]
        private readonly string $appSecret,
    ) {
    }

    public function issue(string $telegramUserId, ?string $userAgent): IssuedSession
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $now = $this->clock->now();
        $session = new MiniAppSession(self::hash($token), $telegramUserId, self::hash((string) $userAgent), $now, $now->modify(sprintf('+%d seconds', self::TTL_SECONDS)));
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return new IssuedSession($token, $this->csrfToken($token), $session->getExpiresAt());
    }

    /**
     * The live session of this token, if it is not expired or revoked and the user agent is the one it was issued to.
     */
    public function find(string $token, ?string $userAgent): ?MiniAppSession
    {
        if (1 !== preg_match(self::TOKEN_PATTERN, $token)) {
            return null;
        }
        $session = $this->entityManager->getRepository(MiniAppSession::class)->findOneBy(['tokenHash' => self::hash($token)]);
        $now = $this->clock->now();
        if (!$session instanceof MiniAppSession || !$session->isUsableAt($now) || !hash_equals($session->getUserAgentHash(), self::hash((string) $userAgent))) {
            return null;
        }

        if (null === $session->getLastSeenAt() || $now->getTimestamp() - $session->getLastSeenAt()->getTimestamp() >= self::TOUCH_INTERVAL_SECONDS) {
            $session->setLastSeenAt($now);
            $this->entityManager->flush();
        }

        return $session;
    }

    public function revoke(MiniAppSession $session): void
    {
        $session->revoke($this->clock->now());
        $this->entityManager->flush();
    }

    /** Ends every open session of a Telegram user (e.g. when an account must be locked out). */
    public function revokeAllFor(string $telegramUserId): int
    {
        return (int) $this->entityManager->createQuery('UPDATE '.MiniAppSession::class.' s SET s.revokedAt = :now WHERE s.telegramUserId = :id AND s.revokedAt IS NULL')
            ->setParameter('now', $this->clock->now())
            ->setParameter('id', $telegramUserId)
            ->execute();
    }

    public function csrfToken(string $sessionToken): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', 'miniapp-csrf|'.$sessionToken, $this->appSecret, true)), '+/', '-_'), '=');
    }

    public function isValidCsrfToken(string $sessionToken, ?string $csrfToken): bool
    {
        return null !== $csrfToken && '' !== $csrfToken && hash_equals($this->csrfToken($sessionToken), $csrfToken);
    }

    private static function hash(string $value): string
    {
        return hash('sha256', $value);
    }
}

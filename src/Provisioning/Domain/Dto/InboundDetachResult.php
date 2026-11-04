<?php

declare(strict_types=1);

namespace App\Provisioning\Domain\Dto;

/**
 * What InboundDetachDriverInterface::detachInbounds() did (docs/design/plan-inbound-change.md 4.3 step 5, 4.4).
 *
 * - DONE: the panel no longer lists the requested inbounds, confirmed by a read after the detach call;
 * - ALREADY_DETACHED: the read before showed none of them listed; nothing was sent (idempotent, a success);
 * - REFUSED: one of our guards said no; nothing was sent to change the panel (reason: a REFUSED_* constant);
 * - FAILED: the panel could not be read, refused, or could not confirm (reason: a FAILED_* constant). The panel may or
 *   may not have applied a detach whose answer was lost: the caller reads again before acting (a retry is safe, the
 *   detach skips what is done).
 *
 * The message is safe for logs, the admin UI and the console: no token, base path, link, UUID, subscription id or
 * customer Telegram id (SecretRedactor::redactAccess()).
 */
final class InboundDetachResult
{
    public const DONE = 'done';
    public const ALREADY_DETACHED = 'already_detached';
    public const REFUSED = 'refused';
    public const FAILED = 'failed';

    /** The panel is not a sanaei_3xui panel in modern mode (same text as InboundAttachDriverInterface::MODERN_PANEL_REQUIRED). */
    public const REFUSED_NOT_MODERN = 'not_modern';
    /** The remote id cannot be read, or names another panel. */
    public const REFUSED_INVALID_SERVICE = 'invalid_service';
    /** No inbound given, an inbound of another panel, or an invalid remote inbound id. */
    public const REFUSED_INVALID_INBOUND = 'invalid_inbound';
    /** An inbound given is the service's primary (local, or as its remote id records it). */
    public const REFUSED_PRIMARY = 'primary';
    /** The detach would leave the client on no inbound at all (3x-ui allows it; we never do). */
    public const REFUSED_LAST_INBOUND = 'last_inbound';
    /** The client the panel has under this email is not this service's (another UUID). */
    public const REFUSED_NOT_THIS_CLIENT = 'not_this_client';

    /** The client could not be read before the detach (panel unreachable, error, time limit). Nothing sent. */
    public const FAILED_UNREADABLE = 'unreadable';
    /** The panel has no client under this email. Nothing sent. */
    public const FAILED_CLIENT_MISSING = 'client_missing';
    /** The answer did not list the client's inbounds (or its UUID): a partial answer is never trusted. Nothing sent. */
    public const FAILED_NOT_LISTED = 'not_listed';
    /** The client is not on the service's primary inbound (a hand edit on the panel?). Nothing sent. */
    public const FAILED_NOT_ON_PRIMARY = 'not_on_primary';
    /** The panel refused the detach call or could not be reached for it (nothing applied). */
    public const FAILED_PANEL = 'panel';
    /** The detach was sent but its effect could not be read back (lost answer, unreadable or partial read). */
    public const FAILED_NOT_CONFIRMED = 'not_confirmed';
    /** The read after the detach still lists an inbound that was to be detached. */
    public const FAILED_STILL_LISTED = 'still_listed';

    /**
     * @param list<int>      $detached         remote inbound ids this call removed (DONE)
     * @param list<int>      $alreadyDetached  remote inbound ids requested that the client was not on
     * @param list<int>|null $inboundIdsAfter  the client's remote inbound ids as last read (null: not known)
     */
    private function __construct(
        public readonly string $outcome,
        public readonly ?string $reason = null,
        public readonly ?string $message = null,
        public readonly array $detached = [],
        public readonly array $alreadyDetached = [],
        public readonly ?array $inboundIdsAfter = null,
    ) {
    }

    /**
     * @param list<int> $detached
     * @param list<int> $alreadyDetached
     * @param list<int> $inboundIdsAfter
     */
    public static function done(array $detached, array $alreadyDetached, array $inboundIdsAfter): self
    {
        return new self(self::DONE, null, null, $detached, $alreadyDetached, $inboundIdsAfter);
    }

    /**
     * @param list<int> $alreadyDetached
     * @param list<int> $inboundIds
     */
    public static function alreadyDetached(array $alreadyDetached, array $inboundIds): self
    {
        return new self(self::ALREADY_DETACHED, null, null, [], $alreadyDetached, $inboundIds);
    }

    /**
     * @param list<int>|null $inboundIds
     */
    public static function refused(string $reason, string $safeMessage, ?array $inboundIds = null): self
    {
        return new self(self::REFUSED, $reason, $safeMessage, [], [], $inboundIds);
    }

    /**
     * @param list<int>|null $inboundIds
     */
    public static function failed(string $reason, string $safeMessage, ?array $inboundIds = null): self
    {
        return new self(self::FAILED, $reason, $safeMessage, [], [], $inboundIds);
    }

    /** DONE or ALREADY_DETACHED: the requested inbounds are off the client. */
    public function succeeded(): bool
    {
        return self::DONE === $this->outcome || self::ALREADY_DETACHED === $this->outcome;
    }
}

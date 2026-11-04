<?php

declare(strict_types=1);

namespace App\Provisioning\Domain\Dto;

/**
 * What InboundMoveDriverInterface::attachForMove() did (docs/design/plan-inbound-change.md 4.3 step 2).
 *
 * - ATTACHED: the client was not on B; one attach call was sent and a read afterwards lists B;
 * - ALREADY_ON_TARGET: the read lists B already (by hand, or an earlier pass): nothing was sent;
 * - REFUSED: a guard of ours said no before any request (reason: REFUSED_*);
 * - FAILED: the panel could not be read, refused, or did not confirm (reason: FAILED_*). The caller backs off; the next
 *   pass reads again first (an attach skips what is done).
 *
 * panelUnreadable is true when no read of the client succeeded at all (the panel did not answer): the runner counts those
 * in a row and pauses the run (5.4). The message is safe for logs, the console and the database: no token, base path,
 * link, UUID, subscription id or client email.
 */
final class InboundMoveAttachResult
{
    public const ATTACHED = 'attached';
    public const ALREADY_ON_TARGET = 'already_on_target';
    public const REFUSED = 'refused';
    public const FAILED = 'failed';

    /** The panel is not a sanaei_3xui panel in modern mode. */
    public const REFUSED_NOT_MODERN = 'not_modern';
    /** The remote id cannot be read, has no numeric inbound, or names another panel. */
    public const REFUSED_INVALID_SERVICE = 'invalid_service';
    /** The target is on another panel or has an invalid remote inbound id. */
    public const REFUSED_INVALID_TARGET = 'invalid_target';

    /** The client could not be read (panel unreachable, error, time limit of the pass). Nothing sent. */
    public const FAILED_UNREADABLE = 'unreadable';
    /** The panel has no client under this email. Nothing sent. */
    public const FAILED_CLIENT_MISSING = 'client_missing';
    /** The answer did not list the client's inbounds or its UUID: never trusted. Nothing sent. */
    public const FAILED_NOT_LISTED = 'not_listed';
    /** The client under this email has another UUID: not this service's. Nothing sent. */
    public const FAILED_NOT_THIS_CLIENT = 'not_this_client';
    /** The client is on neither the old inbound A nor the target B (a hand edit). Nothing sent. */
    public const FAILED_NOT_ON_EXPECTED = 'not_on_expected';
    /** The attach was refused, or B is still not listed after it. */
    public const FAILED_NOT_ATTACHED = 'not_attached';
    /** The attach was sent but the read afterwards failed: not known. */
    public const FAILED_NOT_CONFIRMED = 'not_confirmed';

    /** The client is not on the expected inbound: the text of 4.3 step 2. */
    public const NOT_ON_EXPECTED_TEXT = 'the client is not on the expected inbound (neither the old nor the new one): changed by hand on the panel? Nothing sent.';

    /**
     * @param list<int>|null $inboundIds the client's remote inbound ids as last read (null: not known)
     */
    private function __construct(
        public readonly string $outcome,
        public readonly ?string $reason,
        public readonly ?string $message,
        public readonly ?array $inboundIds,
        public readonly bool $panelUnreadable = false,
    ) {
    }

    /** @param list<int> $inboundIds */
    public static function attached(array $inboundIds): self
    {
        return new self(self::ATTACHED, null, null, $inboundIds);
    }

    /** @param list<int> $inboundIds */
    public static function alreadyOnTarget(array $inboundIds): self
    {
        return new self(self::ALREADY_ON_TARGET, null, null, $inboundIds);
    }

    public static function refused(string $reason, string $message): self
    {
        return new self(self::REFUSED, $reason, $message, null);
    }

    /** @param list<int>|null $inboundIds */
    public static function failed(string $reason, string $message, ?array $inboundIds = null, bool $panelUnreadable = false): self
    {
        return new self(self::FAILED, $reason, $message, $inboundIds, $panelUnreadable);
    }

    /** B is on the client (now or already): the service may be switched to it. */
    public function onTarget(): bool
    {
        return self::ATTACHED === $this->outcome || self::ALREADY_ON_TARGET === $this->outcome;
    }
}

<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

/**
 * What ServiceInboundAttacher::retry() did. $error is safe to show (ServiceErrorText).
 */
final readonly class InboundAttachRetry
{
    /** The rows were tried: $attached now attached, $failed still failed. */
    public const DONE = 'done';
    /** The panel could not be asked (client missing, panel unreachable): every claimed row stays failed. */
    public const FAILED = 'failed';
    /** No failed row: nothing to attach. */
    public const NOTHING_TO_DO = 'nothing_to_do';
    /** Another retry holds the rows right now. */
    public const IN_PROGRESS = 'in_progress';
    public const SWITCHED_OFF = 'switched_off';
    /** A deleted service, or one without a panel. */
    public const NOT_APPLICABLE = 'not_applicable';
    /** The panel is not (any more) in modern mode. */
    public const NOT_MODERN = 'not_modern';

    public function __construct(
        public string $outcome,
        public int $attached = 0,
        public int $failed = 0,
        public ?string $error = null,
    ) {
    }
}

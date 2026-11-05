<?php

declare(strict_types=1);

namespace App\Security;

/**
 * A logged-in user that may act as an admin outside the web panel (the Mini App's MiniAppUser): AdminAuditLogger
 * records an entry written during its request without an explicit actor (shared code such as the inbound move's
 * planner and controls) as this admin, not as an anonymous web request.
 */
interface AdminActorUser
{
    /** The admin identity resolved for this request, null when this user is not an admin. */
    public function adminActor(): ?AdminActor;
}

<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\AdminUser;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Web side of AdminAccessPolicy: is_granted('PERM_...') for the logged-in AdminUser (the subject is ignored).
 *
 * @extends Voter<string, mixed>
 */
final class AdminPermissionVoter extends Voter
{
    public function __construct(private readonly AdminAccessPolicy $policy)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return null !== AdminPermission::tryFrom($attribute);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof AdminUser) {
            return false;
        }

        return $this->policy->can(AdminActor::fromAdminUser($user), AdminPermission::from($attribute));
    }
}

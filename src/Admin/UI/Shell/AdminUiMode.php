<?php

declare(strict_types=1);

namespace App\Admin\UI\Shell;

use App\Entity\AdminUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * Which admin layout a request gets (docs/design/admin-redesign.md, 5.1): "classic" (today's panel, the default) or
 * "modern" (the 7-item sidebar of the redesign). One reader, asked by the dashboard configuration, the layout override
 * and the shell listeners; it reads the setting once per request.
 *
 * - Setting admin.ui.layout = classic | modern (a Setting row; anything else is classic). Edited on the settings
 *   page's "advanced" tab until the settings hub has its card (AD7).
 * - ADMIN_UI_FORCE_CLASSIC=1 (env) wins over everything: the way back when the modern layout breaks the very page
 *   that would switch it back.
 * - Preview before the flip, super admin only (decision 2): ?admin_ui=preview sets a session flag, ?admin_ui=classic
 *   clears it (AdminUiPreviewListener). The flag counts only while the admin's assigned roles include
 *   ROLE_SUPER_ADMIN, so an admin or support who sends the parameter (or loses the role later) gets classic.
 */
final class AdminUiMode implements ResetInterface
{
    public const SETTING = 'admin.ui.layout';
    public const CLASSIC = 'classic';
    public const MODERN = 'modern';

    /** The query parameter of the preview and its values. */
    public const QUERY = 'admin_ui';
    public const QUERY_PREVIEW = 'preview';
    public const QUERY_CLASSIC = 'classic';
    public const SESSION_PREVIEW = 'admin_ui.preview';

    private ?string $setting = null;
    private ?bool $modern = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly Security $security,
        #[Autowire('%env(default::ADMIN_UI_FORCE_CLASSIC)%')]
        private readonly ?string $forceClassic = null,
    ) {
    }

    /** Whether this request renders the modern layout. */
    #[AsTwigFunction('admin_ui_modern')]
    public function isModern(): bool
    {
        if (null !== $this->modern) {
            return $this->modern;
        }
        if ($this->isForcedClassic()) {
            return false;
        }
        if (self::MODERN === $this->setting()) {
            return $this->modern = true;
        }

        // Not cached: the preview flag can change during the request (AdminUiPreviewListener runs first, but a test
        // client reuses this service across requests).
        return $this->isPreviewing();
    }

    /** The stored layout: classic or modern (classic for a missing row or any other value). */
    public function setting(): string
    {
        if (null === $this->setting) {
            $value = $this->entityManager->getConnection()->fetchOne('SELECT value FROM setting WHERE key_name = ?', [self::SETTING]);
            $this->setting = self::MODERN === (is_string($value) ? strtolower(trim($value)) : '') ? self::MODERN : self::CLASSIC;
        }

        return $this->setting;
    }

    public function isForcedClassic(): bool
    {
        return in_array(strtolower(trim((string) $this->forceClassic)), ['1', 'true', 'yes', 'on'], true);
    }

    /** Whether the current admin is a super admin with the preview flag on (and the setting still classic). */
    public function isPreviewing(): bool
    {
        if (!self::maySeePreview($this->security->getUser())) {
            return false;
        }
        $session = $this->requestStack->getCurrentRequest()?->hasSession() ? $this->requestStack->getSession() : null;

        return true === $session?->get(self::SESSION_PREVIEW);
    }

    /** The preview is for super admins only (decision 2): their assigned roles, not the ROLE_ADMIN every admin gets. */
    public static function maySeePreview(mixed $user): bool
    {
        return $user instanceof AdminUser && in_array(AdminUser::ROLE_SUPER_ADMIN, $user->getAssignedRoles(), true);
    }

    public function reset(): void
    {
        $this->setting = null;
        $this->modern = null;
    }
}

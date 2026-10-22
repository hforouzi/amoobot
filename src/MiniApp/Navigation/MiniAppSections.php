<?php

declare(strict_types=1);

namespace App\MiniApp\Navigation;

use App\MiniApp\Security\MiniAppUser;
use App\Security\AdminAccessPolicy;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The registry of Mini App sections and the one rule for who may open each: the bottom navigation, a parent's list
 * and the server-side guard (MiniAppSectionGuard) all ask isAllowed(), so the menu and the routes cannot disagree.
 */
final class MiniAppSections
{
    /** @var array<string, MiniAppSection>|null */
    private ?array $sections = null;

    /**
     * @param iterable<MiniAppSectionProvider> $providers
     */
    public function __construct(
        #[AutowireIterator('app.miniapp_section_provider')]
        private readonly iterable $providers,
        private readonly AdminAccessPolicy $accessPolicy,
    ) {
    }

    /**
     * @return iterable<MiniAppSectionProvider>
     */
    public function providers(): iterable
    {
        return $this->providers;
    }

    public function get(string $key): ?MiniAppSection
    {
        return $this->all()[$key] ?? null;
    }

    public function isAllowed(string $key, MiniAppUser $user): bool
    {
        $section = $this->get($key);
        if (null === $section) {
            return false;
        }
        if (null !== $section->parent && !$this->isAllowed($section->parent, $user)) {
            return false;
        }

        return match ($section->audience) {
            MiniAppAudience::CUSTOMER => [] === $section->anyOf || $this->accessPolicy->canAny($user->admin, $section->anyOf),
            MiniAppAudience::ADMIN => [] !== $this->accessPolicy->permissions($user->admin)
                && ([] === $section->anyOf || $this->accessPolicy->canAny($user->admin, $section->anyOf)),
        };
    }

    /**
     * The bottom navigation: top-level sections this user may open, in order.
     *
     * @return list<MiniAppSection>
     */
    public function navigation(MiniAppUser $user): array
    {
        return $this->visible(static fn (MiniAppSection $s): bool => null === $s->parent, $user);
    }

    /**
     * @return list<MiniAppSection>
     */
    public function children(string $parent, MiniAppUser $user): array
    {
        return $this->visible(static fn (MiniAppSection $s): bool => $parent === $s->parent, $user);
    }

    /**
     * @param callable(MiniAppSection): bool $filter
     *
     * @return list<MiniAppSection>
     */
    private function visible(callable $filter, MiniAppUser $user): array
    {
        $sections = array_values(array_filter($this->all(), fn (MiniAppSection $s): bool => $filter($s) && $this->isAllowed($s->key, $user)));
        usort($sections, static fn (MiniAppSection $a, MiniAppSection $b): int => [$a->order, $a->key] <=> [$b->order, $b->key]);

        return $sections;
    }

    /**
     * @return array<string, MiniAppSection>
     */
    private function all(): array
    {
        if (null === $this->sections) {
            $this->sections = [];
            foreach ($this->providers as $provider) {
                foreach ($provider->sections() as $section) {
                    if (isset($this->sections[$section->key])) {
                        throw new \LogicException(sprintf('Mini App section "%s" is registered twice.', $section->key));
                    }
                    $this->sections[$section->key] = $section;
                }
            }
        }

        return $this->sections;
    }
}

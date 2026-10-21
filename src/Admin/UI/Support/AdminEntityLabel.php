<?php

declare(strict_types=1);

namespace App\Admin\UI\Support;

use App\Entity;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * How the admin names a related record (list and detail cells, form selects), in the admin's language: its title or
 * name when it has one, else "<entity> #<id>" ("سفارش #12"). The entities' own __toString() is English and stays
 * for logs and the console.
 */
final class AdminEntityLabel
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function of(object $entity): string
    {
        return match (true) {
            $entity instanceof Entity\User => $this->userLabel($entity),
            $entity instanceof Entity\Plan, $entity instanceof Entity\TrialPlan, $entity instanceof Entity\VpnPanel, $entity instanceof Entity\VpnInbound, $entity instanceof Entity\PaymentGateway, $entity instanceof Entity\StorePaymentMethod, $entity instanceof Entity\BroadcastMessage => '' !== trim((string) $entity->getTitle()) ? (string) $entity->getTitle() : $this->numbered($entity),
            $entity instanceof Entity\DiscountCode => $entity->getCode(),
            $entity instanceof Entity\TelegramAccount => null !== $entity->getUsername() && '' !== $entity->getUsername() ? self::isolated('@'.$entity->getUsername()) : $entity->getTelegramId(),
            $entity instanceof Entity\AdminUser => (string) $entity,
            default => $this->numbered($entity),
        };
    }

    private function userLabel(Entity\User $user): string
    {
        $name = trim((string) $user->getName());
        if ('' !== $name) {
            return $name;
        }
        $username = (string) $user->getTelegramAccount()?->getUsername();

        return '' !== $username ? self::isolated('@'.$username) : $this->numbered($user);
    }

    /** Left-to-right text kept in order inside a right-to-left line ("@name", not "name@"): FSI ... PDI. */
    private static function isolated(string $text): string
    {
        return "\u{2068}".$text."\u{2069}";
    }

    private function numbered(object $entity): string
    {
        $short = substr($entity::class, (int) strrpos('\\'.$entity::class, '\\'));
        $id = method_exists($entity, 'getId') ? $entity->getId() : null;

        return sprintf('%s #%s', $this->translator->trans('entity.'.AdminTranslationIdGenerator::snake($short).'.singular', [], 'admin'), (string) ($id ?? '?'));
    }
}

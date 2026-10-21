<?php

declare(strict_types=1);

namespace App\Admin\UI\Support;

use EasyCorp\Bundle\EasyAdminBundle\Contracts\Translation\EntityTranslationIdGeneratorInterface;

/**
 * Translation keys of the admin labels a CRUD does not set itself (Dashboard::useEntityTranslations()), in the
 * "admin" domain: "entity.vpn_service.singular|plural" for entity names, and one shared "field.<property>" key per
 * property for field and filter labels ("field.created_at", "field.traffic_limit_gb", "field.order_type" for
 * "order.type"), so a column means the same in every list.
 */
final class AdminTranslationIdGenerator implements EntityTranslationIdGeneratorInterface
{
    public function generateForEntity(string $entity, bool $singular): string
    {
        $short = substr($entity, (int) strrpos('\\'.$entity, '\\'));

        return sprintf('entity.%s.%s', self::snake($short), $singular ? 'singular' : 'plural');
    }

    public function generateForProperty(string $entity, string $property): string
    {
        return 'field.'.self::snake(str_replace('.', '_', $property));
    }

    public static function snake(string $name): string
    {
        return strtolower((string) preg_replace(['/([a-z0-9])([A-Z])/', '/([A-Z]+)([A-Z][a-z])/'], ['$1_$2', '$1_$2'], $name));
    }
}

<?php

declare(strict_types=1);

namespace App\Admin\UI\Support;

final class AdminStatusBadge
{
    /**
     * @param string[] $statuses
     *
     * @return array<string, string>
     */
    public static function choices(array $statuses): array
    {
        $choices = [];
        foreach ($statuses as $status) {
            $choices['status.'.$status] = $status;
        }

        return $choices;
    }

    /**
     * @return array<string, string>
     */
    public static function badgeMap(): array
    {
        return [
            'active' => 'success',
            'completed' => 'success',
            'paid' => 'success',
            'confirmed' => 'success',
            'pending' => 'warning',
            'waiting_payment' => 'warning',
            'payment_pending' => 'warning',
            'submitted' => 'info',
            'processing' => 'info',
            'failed' => 'danger',
            'rejected' => 'danger',
            'cancelled' => 'danger',
            'expired' => 'secondary',
            'suspended' => 'secondary',
            'deleted' => 'secondary',
            'inactive' => 'secondary',
        ];
    }

    public static function html(mixed $value): string
    {
        $label = (string) ($value ?: 'unknown');
        $class = match ($label) {
            'enabled', 'active', 'completed', 'paid', 'confirmed' => 'badge badge-success',
            'installed', 'pending', 'waiting_payment', 'payment_pending', 'submitted', 'processing' => 'badge badge-warning',
            'disabled', 'cancelled', 'expired', 'suspended', 'deleted', 'inactive' => 'badge badge-secondary',
            'error', 'failed', 'rejected' => 'badge badge-danger',
            default => 'badge badge-secondary',
        };

        return sprintf(
            '<span class="%s">%s</span>',
            $class,
            htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
    }

    public static function boolHtml(mixed $value): string
    {
        $enabled = true === $value || '1' === (string) $value;
        $label = $enabled ? 'yes' : 'no';
        $class = $enabled ? 'badge badge-success' : 'badge badge-secondary';

        return sprintf('<span class="%s">%s</span>', $class, $label);
    }
}

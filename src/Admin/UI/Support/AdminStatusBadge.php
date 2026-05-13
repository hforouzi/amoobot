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
}

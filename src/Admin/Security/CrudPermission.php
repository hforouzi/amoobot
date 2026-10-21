<?php

declare(strict_types=1);

namespace App\Admin\Security;

use App\Security\AdminPermission;

/**
 * Permissions of one admin page: to open it (list/detail and the controller's own actions), to create/edit, to delete.
 */
final class CrudPermission
{
    public function __construct(
        public readonly AdminPermission $view,
        public readonly AdminPermission $manage,
        public readonly AdminPermission $delete,
    ) {
    }
}

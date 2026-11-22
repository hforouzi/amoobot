<?php

declare(strict_types=1);

namespace App\Plugin;

/** Enabling was refused before anything changed (APP_MODULES_ALLOWED). The message names no secret. */
final class PluginEnableRefused extends \RuntimeException
{
}

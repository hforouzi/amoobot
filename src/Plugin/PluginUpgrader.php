<?php

declare(strict_types=1);

namespace App\Plugin;

use App\Entity\PaymentGateway;
use App\Entity\Plugin;
use App\Payment\Plugin\PaymentGatewayPluginInterface;
use App\Payment\Plugin\PluginConfigSchemaValidator;
use App\Security\AdminActor;
use App\Security\AdminAuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Upgrades an installed plugin in place from a ZIP (app:plugin:upgrade). The ZIP goes through install's own checks
 * (PluginManager::extractPackage()), then:
 *
 *  1. same code as an installed plugin, same type, a strictly greater semantic version;
 *  2. permissions it adds are refused unless accepted;
 *  3. every payment gateway of this code is checked against the new config schema: stored keys the schema dropped are kept
 *     and reported, a newly required field without a default that a gateway lacks refuses (gateway ids, never values);
 *  4. the doctor's checks run against the new files in the workspace, before anything live is touched (a dry run stops
 *     here);
 *  5. under PluginFilesLock: var/plugins/<code> becomes var/plugins/.upgrade-backup/<code>-<old>-<timestamp>, the new
 *     files take its place, the Plugin row is updated (status, enabledAt and installedAt kept) and the doctor runs again.
 *     Any failure after the first rename puts the old files and the row back. Only the latest backup is kept.
 *
 * Gateway rows are never written. Nothing here prints or logs a config value.
 */
final class PluginUpgrader
{
    public const BACKUP_DIR = '.upgrade-backup';

    /** @var (\Closure(Plugin): void)|null test seam: runs after the row is saved, before the doctor */
    private ?\Closure $afterSwitch = null;

    public function __construct(
        private readonly PluginManager $pluginManager,
        private readonly PluginFilesLock $filesLock,
        private readonly PaymentPluginDoctor $doctor,
        private readonly PluginConfigSchemaValidator $schemaValidator,
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminAuditLogger $auditLogger,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir,
    ) {
    }

    /**
     * @internal for tests: simulate a failure after the switch
     *
     * @param (\Closure(Plugin): void)|null $hook
     */
    public function setAfterSwitchHook(?\Closure $hook): void
    {
        $this->afterSwitch = $hook;
    }

    public function upgrade(string $zipPath, bool $dryRun, bool $acceptNewPermissions, string $actorLabel): PluginUpgradeResult
    {
        try {
            $lock = $this->filesLock->acquire();
        } catch (PluginInstallException $exception) {
            return PluginUpgradeResult::refused([$exception->getMessage()], null, $dryRun);
        }

        $package = null;
        try {
            $package = $this->pluginManager->extractPackage($zipPath);

            return $this->upgradeExtracted($package, $dryRun, $acceptNewPermissions, $actorLabel);
        } catch (PluginInstallException $exception) {
            $this->logger->warning('Plugin upgrade rejected: '.$exception->getMessage());

            return PluginUpgradeResult::refused([$exception->getMessage()], null, $dryRun);
        } catch (\Throwable $exception) {
            $this->logger->error('Plugin upgrade failed: '.$exception->getMessage());

            return PluginUpgradeResult::refused(['Plugin upgrade failed.'], null, $dryRun);
        } finally {
            $this->pluginManager->discardPackage($package);
            $this->filesLock->release($lock);
        }
    }

    private function upgradeExtracted(ExtractedPluginPackage $package, bool $dryRun, bool $acceptNewPermissions, string $actorLabel): PluginUpgradeResult
    {
        $manifest = $package->manifest;
        $code = (string) $manifest['code'];

        $plugin = $this->entityManager->getRepository(Plugin::class)->findOneBy(['code' => $code]);
        if (!$plugin instanceof Plugin) {
            return PluginUpgradeResult::refused([sprintf('Plugin "%s" is not installed; use app:plugin:install.', $code)], null, $dryRun);
        }
        if ((string) $manifest['type'] !== $plugin->getType()) {
            return PluginUpgradeResult::refused([sprintf('The plugin type cannot change (installed "%s", ZIP "%s").', $plugin->getType(), (string) $manifest['type'])], null, $dryRun);
        }

        $oldVersion = $plugin->getVersion();
        $newVersion = (string) $manifest['version'];
        $comparison = SemanticVersion::compare($newVersion, $oldVersion);
        if (null === $comparison) {
            return PluginUpgradeResult::refused([sprintf('Both versions must be semantic versions such as 1.2.0 (installed "%s", ZIP "%s").', $oldVersion, $newVersion)], null, $dryRun);
        }
        if ($comparison <= 0) {
            return PluginUpgradeResult::refused([sprintf('The ZIP version %s is not greater than the installed version %s.', $newVersion, $oldVersion)], null, $dryRun);
        }

        $liveDir = $this->pluginManager->pluginDirectory($code);
        $backupRoot = dirname($liveDir).\DIRECTORY_SEPARATOR.self::BACKUP_DIR;
        $pathErrors = $this->pathErrors($plugin, $liveDir, $backupRoot, $package->root);
        if ([] !== $pathErrors) {
            return PluginUpgradeResult::refused($pathErrors, null, $dryRun);
        }

        $plan = $this->plan($plugin, $manifest);
        $errors = [];
        if ([] !== $plan->addedPermissions && !$acceptNewPermissions) {
            $errors[] = sprintf('The new version asks for permissions the installed one does not have: %s. Run again with --accept-new-permissions to grant them.', implode(', ', $plan->addedPermissions));
        }
        foreach ($plan->missingRequired as $gatewayId => $fields) {
            $errors[] = sprintf('Payment gateway #%d lacks the newly required field(s) %s, which have no default. Fill them in first.', $gatewayId, implode(', ', $fields));
        }
        if ([] === $errors) {
            $errors = $this->checkNewFiles($plugin, $manifest, $package->root);
        }
        if ([] !== $errors) {
            return PluginUpgradeResult::refused($errors, $plan, $dryRun);
        }
        if ($dryRun) {
            return PluginUpgradeResult::planned($plan);
        }

        return $this->switch($plugin, $manifest, $package->root, $liveDir, $backupRoot, $plan, $actorLabel);
    }

    /**
     * The live directory, its parents in var/ and the backup directory are real directories (no symbolic link), the
     * plugin row points at var/plugins/<code>, and the new files hold no link either.
     *
     * @return list<string>
     */
    private function pathErrors(Plugin $plugin, string $liveDir, string $backupRoot, string $newRoot): array
    {
        $errors = [];
        $var = $this->projectDir.\DIRECTORY_SEPARATOR.'var';
        foreach ([$var, dirname($liveDir), $liveDir, $backupRoot] as $path) {
            if (is_link($path)) {
                $errors[] = sprintf('%s is a symbolic link; refusing to follow it.', $this->relative($path));
            }
        }
        if ([] !== $errors) {
            return $errors;
        }
        if (!is_dir($liveDir)) {
            return [sprintf('The installed files are missing (%s); nothing to upgrade.', $this->relative($liveDir))];
        }
        if ($this->relative($liveDir) !== str_replace('\\', '/', $plugin->getPath())) {
            return [sprintf('The plugin is not installed under %s (its path is %s); upgrade it by hand.', $this->relative($liveDir), $plugin->getPath())];
        }
        foreach ([$liveDir => 'The installed files', $newRoot => 'The ZIP'] as $dir => $what) {
            $link = $this->firstLink($dir);
            if (null !== $link) {
                $errors[] = sprintf('%s contain a symbolic link (%s); refusing to continue.', $what, $link);
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function plan(Plugin $plugin, array $manifest): PluginUpgradePlan
    {
        $oldPermissions = $plugin->getPermissions() ?? [];
        $newPermissions = $this->pluginManager->permissionsFromManifest($manifest) ?? [];

        $oldFields = $this->fieldsByName($plugin->getManifest()['configSchema'] ?? []);
        $newFields = $this->fieldsByName($manifest['configSchema'] ?? []);
        $newlyRequired = [];
        foreach ($newFields as $name => $field) {
            $requiredBefore = true === ($oldFields[$name]['required'] ?? false);
            if (true === $field['required'] && !$requiredBefore && !$this->hasDefault($field)) {
                $newlyRequired[] = $name;
            }
        }

        $gatewayIds = [];
        $obsoleteKeys = [];
        $missingRequired = [];
        $gateways = $this->entityManager->getRepository(PaymentGateway::class)->findBy(['pluginCode' => $plugin->getCode()], ['id' => 'ASC']);
        foreach ($gateways as $gateway) {
            $id = (int) $gateway->getId();
            $gatewayIds[] = $id;
            $config = $gateway->getConfig() ?? [];
            $obsolete = array_values(array_diff(array_map('strval', array_keys($config)), array_keys($newFields)));
            if ([] !== $obsolete) {
                $obsoleteKeys[$id] = $obsolete;
            }
            $missing = array_values(array_filter($newlyRequired, static function (string $name) use ($config): bool {
                $value = $config[$name] ?? null;

                return null === $value || (is_string($value) && '' === trim($value));
            }));
            if ([] !== $missing) {
                $missingRequired[$id] = $missing;
            }
        }

        return new PluginUpgradePlan(
            code: $plugin->getCode(),
            status: $plugin->getStatus(),
            oldVersion: $plugin->getVersion(),
            newVersion: (string) $manifest['version'],
            oldMainClass: (string) $plugin->getMainClass(),
            newMainClass: (string) $manifest['mainClass'],
            addedPermissions: array_values(array_diff($newPermissions, $oldPermissions)),
            removedPermissions: array_values(array_diff($oldPermissions, $newPermissions)),
            addedFields: array_values(array_diff(array_keys($newFields), array_keys($oldFields))),
            removedFields: array_values(array_diff(array_keys($oldFields), array_keys($newFields))),
            newlyRequiredFields: $newlyRequired,
            gatewayIds: $gatewayIds,
            obsoleteKeys: $obsoleteKeys,
            missingRequired: $missingRequired,
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function fieldsByName(mixed $schema): array
    {
        $fields = [];
        foreach ($this->schemaValidator->normalize($schema) as $field) {
            $fields[(string) $field['name']] = $field;
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $field
     */
    private function hasDefault(array $field): bool
    {
        $default = $field['default'] ?? null;

        return null !== $default && !(is_string($default) && '' === trim($default));
    }

    /**
     * The doctor's checks (as install and validate-package run them) against the new files in the workspace. When the
     * main class is already declared in this process (from the old files), class_exists() proves nothing about the new
     * ones, so they are loaded in a separate PHP process too.
     *
     * @param array<string, mixed> $manifest
     *
     * @return list<string>
     */
    private function checkNewFiles(Plugin $installed, array $manifest, string $root): array
    {
        $mainClass = trim((string) $manifest['mainClass']);
        $candidate = (new Plugin())
            ->setCode($installed->getCode())
            ->setType($installed->getType())
            ->setName($this->pluginManager->stringMap((array) $manifest['name']))
            ->setVersion((string) $manifest['version'])
            ->setPath($root)
            ->setMainClass($mainClass)
            ->setManifest($manifest)
            ->setStatus(Plugin::STATUS_INSTALLED);

        $preloaded = '' !== $mainClass && class_exists($mainClass, false);
        $errors = [];
        if (Plugin::TYPE_PAYMENT_GATEWAY === $installed->getType()) {
            $doctor = $this->doctor->inspect($candidate);
            if (!$doctor->ok()) {
                $errors = array_map(static fn (string $error): string => 'New files: '.$error, $doctor->errors);
            }
        }
        if ([] === $errors && $preloaded) {
            $interface = Plugin::TYPE_PAYMENT_GATEWAY === $installed->getType() ? PaymentGatewayPluginInterface::class : '';
            $error = $this->loadInSeparateProcess($root.\DIRECTORY_SEPARATOR.'src', $mainClass, $interface);
            if (null !== $error) {
                $errors[] = 'New files: '.$error;
            }
        }

        return $errors;
    }

    /**
     * Loads $mainClass from $srcDir in a fresh PHP CLI process (Composer's autoloader plus the plugin's PSR-4 rule, as
     * PluginAutoloader registers it) and checks it implements $interface.
     */
    private function loadInSeparateProcess(string $srcDir, string $mainClass, string $interface): ?string
    {
        $separator = strrpos($mainClass, '\\');
        $namespace = false === $separator ? '' : substr($mainClass, 0, $separator + 1);
        $script = <<<'PHP'
            [, $autoload, $src, $namespace, $class, $interface] = $argv;
            require $autoload;
            spl_autoload_register(static function (string $name) use ($src, $namespace): void {
                if ('' !== $namespace && !str_starts_with($name, $namespace)) {
                    return;
                }
                $path = $src.'/'.str_replace('\\', '/', substr($name, strlen($namespace))).'.php';
                if (is_file($path)) {
                    require $path;
                }
            });
            if (!class_exists($class)) {
                echo 'class_not_found';
                exit(3);
            }
            if ('' !== $interface && !is_subclass_of($class, $interface)) {
                echo 'interface_not_implemented';
                exit(4);
            }
            echo 'ok';
            PHP;

        if (!function_exists('proc_open')) {
            return 'the main class is already loaded in this process and no separate PHP process can be started to check the new files.';
        }
        $autoload = $this->projectDir.\DIRECTORY_SEPARATOR.'vendor'.\DIRECTORY_SEPARATOR.'autoload.php';
        $command = [PHP_BINARY, '-d', 'display_errors=stdout', '-d', 'log_errors=0', '-r', $script, '--', $autoload, $srcDir, $namespace, $mainClass, $interface];
        $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return 'a separate PHP process could not be started to check the new files.';
        }
        fclose($pipes[0]);
        // Errors are displayed on stdout (display_errors=stdout); stderr only in case PHP itself writes there.
        $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if (0 === $exitCode && str_ends_with(trim($output), 'ok')) {
            return null;
        }

        $line = trim((string) strtok(trim($output), "\n"));

        return '' === $line ? sprintf('the main class did not load (exit code %d).', $exitCode) : mb_substr($line, 0, 300);
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function switch(Plugin $plugin, array $manifest, string $newRoot, string $liveDir, string $backupRoot, PluginUpgradePlan $plan, string $actorLabel): PluginUpgradeResult
    {
        if (!is_dir($backupRoot) && !@mkdir($backupRoot, 0775, true) && !is_dir($backupRoot)) {
            return PluginUpgradeResult::refused([sprintf('Could not create %s.', $this->relative($backupRoot))], $plan);
        }
        $backupDir = $backupRoot.\DIRECTORY_SEPARATOR.sprintf('%s-%s-%s', $plugin->getCode(), $plan->oldVersion, gmdate('Ymd-His'));
        if (file_exists($backupDir) || is_link($backupDir)) {
            $backupDir .= '-'.bin2hex(random_bytes(3));
        }

        $snapshot = [
            'version' => $plugin->getVersion(),
            'name' => $plugin->getName(),
            'description' => $plugin->getDescription(),
            'mainClass' => $plugin->getMainClass(),
            'manifest' => $plugin->getManifest(),
            'permissions' => $plugin->getPermissions(),
            'updatedAt' => $plugin->getUpdatedAt(),
        ];

        if (!@rename($liveDir, $backupDir)) {
            return PluginUpgradeResult::refused(['Could not move the installed files aside; nothing was changed.'], $plan);
        }
        if (!@rename($newRoot, $liveDir)) {
            @rename($backupDir, $liveDir);

            return PluginUpgradeResult::refused(['Could not move the new files into place; the installed files were put back.'], $plan);
        }

        try {
            $plugin
                ->setVersion((string) $manifest['version'])
                ->setName($this->pluginManager->stringMap((array) $manifest['name']))
                ->setDescription(isset($manifest['description']) && is_array($manifest['description']) ? $this->pluginManager->stringMap($manifest['description']) : null)
                ->setMainClass((string) $manifest['mainClass'])
                ->setManifest($manifest)
                ->setPermissions($this->pluginManager->permissionsFromManifest($manifest))
                ->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            if (null !== $this->afterSwitch) {
                ($this->afterSwitch)($plugin);
            }

            if (Plugin::TYPE_PAYMENT_GATEWAY === $plugin->getType()) {
                // The plugin's own status is kept as it was, so an "error" status is not a reason to undo the upgrade.
                $errors = array_filter($this->doctor->inspect($plugin)->errors, static fn (string $error): bool => !str_starts_with($error, 'plugin_error'));
                if ([] !== $errors) {
                    throw new PluginInstallException('the doctor failed on the installed files: '.implode('; ', $errors));
                }
            }
        } catch (\Throwable $exception) {
            $restored = $this->restore($plugin, $snapshot, $liveDir, $backupDir);
            $this->logger->error('Plugin upgrade failed after the switch; previous version restored.', ['code' => $plugin->getCode(), 'error' => $exception->getMessage(), 'restored' => $restored]);
            $message = $exception instanceof PluginInstallException ? $exception->getMessage() : 'an unexpected error ('.get_debug_type($exception).').';

            return PluginUpgradeResult::refused([
                sprintf('Upgrade failed after the switch: %s', $message),
                $restored ? sprintf('Version %s was restored (files and plugin row).', $plan->oldVersion) : sprintf('Restoring version %s failed: check %s and %s by hand.', $plan->oldVersion, $this->relative($liveDir), $this->relative($backupDir)),
            ], $plan);
        }

        $this->auditLogger->record('plugin.upgrade', 'Plugin', $plugin->getId(), [
            'code' => $plugin->getCode(),
            'from' => $plan->oldVersion,
            'to' => $plan->newVersion,
            'by' => $actorLabel,
        ], AdminActor::console($actorLabel));
        $this->removeOlderBackups($backupRoot, $plugin->getCode(), $backupDir);
        $this->logger->info('Plugin upgraded.', ['code' => $plugin->getCode(), 'from' => $plan->oldVersion, 'to' => $plan->newVersion]);

        return PluginUpgradeResult::upgraded($plan, $backupDir);
    }

    /**
     * The old files back in place (the new ones removed) and the row as it was.
     *
     * @param array<string, mixed> $snapshot
     */
    private function restore(Plugin $plugin, array $snapshot, string $liveDir, string $backupDir): bool
    {
        $failedDir = $backupDir.'-failed';
        $filesBack = @rename($liveDir, $failedDir) && @rename($backupDir, $liveDir);
        if ($filesBack) {
            $this->pluginManager->removeDirectory($failedDir);
        }

        $rowBack = true;
        if ($this->entityManager->isOpen()) {
            try {
                $plugin
                    ->setVersion($snapshot['version'])
                    ->setName($snapshot['name'])
                    ->setDescription($snapshot['description'])
                    ->setMainClass($snapshot['mainClass'])
                    ->setManifest($snapshot['manifest'])
                    ->setPermissions($snapshot['permissions'])
                    ->setUpdatedAt($snapshot['updatedAt']);
                $this->entityManager->flush();
            } catch (\Throwable) {
                $rowBack = false;
            }
        }
        // A closed entity manager means the failing flush rolled its transaction back: the row was never changed.

        return $filesBack && $rowBack;
    }

    private function removeOlderBackups(string $backupRoot, string $code, string $keep): void
    {
        $pattern = '/^'.preg_quote($code, '/').'-\d+\.\d+\.\d+[0-9A-Za-z.+-]*-\d{8}-\d{6}(?:-[0-9a-f]{6})?(?:-failed)?$/';
        foreach (scandir($backupRoot) ?: [] as $entry) {
            $path = $backupRoot.\DIRECTORY_SEPARATOR.$entry;
            if ($path !== $keep && 1 === preg_match($pattern, $entry)) {
                $this->pluginManager->removeDirectory($path);
            }
        }
    }

    /** The first symbolic link inside $dir (relative to it), or null. Links are never followed. */
    private function firstLink(string $dir): ?string
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                return substr($item->getPathname(), strlen($dir) + 1);
            }
        }

        return null;
    }

    private function relative(string $path): string
    {
        $prefix = rtrim(str_replace('\\', '/', $this->projectDir), '/').'/';
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }
}

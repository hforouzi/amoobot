<?php

declare(strict_types=1);

use App\Admin\UI\Crud\VpnInboundCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use Symfony\Component\Yaml\Yaml;

require_once dirname(__DIR__, 2).'/vendor/autoload.php';

function runVpnInboundAdminReadOnlyTest(): void
{
    $controller = new VpnInboundCrudController();
    $fields = inboundFieldsByProperty($controller->configureFields(Crud::PAGE_INDEX), Crud::PAGE_INDEX);

    inboundAssertTrue(class_exists(VpnInboundCrudController::class), 'VpnInbound CRUD controller must exist.');
    inboundAssertTrue(isset($fields['panel']), 'Inbound list must show panel.');
    inboundAssertTrue(isset($fields['remoteInboundId']), 'Inbound list must show remote inbound ID.');
    inboundAssertTrue(isset($fields['title']), 'Inbound list must show name/title.');
    inboundAssertTrue(isset($fields['protocol']), 'Inbound list must show protocol.');
    inboundAssertTrue(isset($fields['port']), 'Inbound list must show port.');
    inboundAssertTrue(isset($fields['isActive']), 'Inbound list must show active status.');
    inboundAssertTrue(isset($fields['uploadTrafficBytes']), 'Inbound list must show upload traffic.');
    inboundAssertTrue(isset($fields['downloadTrafficBytes']), 'Inbound list must show download traffic.');
    inboundAssertTrue(isset($fields['totalTrafficBytes']), 'Inbound list must show total traffic.');
    inboundAssertTrue(isset($fields['remoteExpiryTime']), 'Inbound list must show expiry.');
    inboundAssertTrue(isset($fields['remoteTag']), 'Inbound list must show tag.');
    inboundAssertTrue(isset($fields['lastSyncedAt']), 'Inbound list must show last sync time.');

    $actions = $controller->configureActions(Actions::new())->getAsDto(null);
    inboundAssertContains(Action::NEW, $actions->getDisabledActions(), 'Create action must be disabled.');
    inboundAssertContains(Action::EDIT, $actions->getDisabledActions(), 'Edit action must be disabled.');
    inboundAssertContains(Action::DELETE, $actions->getDisabledActions(), 'Delete action must be disabled.');
    inboundAssertContains(Action::BATCH_DELETE, $actions->getDisabledActions(), 'Batch delete action must be disabled.');
    inboundAssertTrue(null !== $actions->getAction(Crud::PAGE_INDEX, Action::DETAIL), 'Detail action must be available from index.');
    inboundAssertTrue(null !== $actions->getAction(Crud::PAGE_INDEX, 'backToPanel'), 'Back-to-panel action must be available.');

    $panelCrudSource = file_get_contents(dirname(__DIR__, 2).'/src/Admin/UI/Crud/VpnPanelCrudController.php') ?: '';
    inboundAssertStringContains('viewInbounds', $panelCrudSource, 'Per-panel View Inbounds action must exist.');
    inboundAssertStringContains('admin_vpn_panel_view_inbounds', $panelCrudSource, 'Per-panel View Inbounds action must link to the panel inbound route.');

    $redacted = VpnInboundCrudController::formatJsonConfig([
        'remote' => ['up' => 1024, 'down' => 2048],
        'settings' => [
            'clients' => [
                ['id' => 'client-uuid-secret', 'email' => 'client@example.com', 'password' => 'client-password'],
            ],
            'apiToken' => 'secret-token',
        ],
        'streamSettings' => ['security' => 'reality', 'privateKey' => 'secret-key'],
    ]);
    inboundAssertStringNotContains('client-uuid-secret', $redacted, 'Client UUIDs must not be rendered from config JSON.');
    inboundAssertStringNotContains('client-password', $redacted, 'Client passwords must not be rendered from config JSON.');
    inboundAssertStringNotContains('secret-token', $redacted, 'Tokens must not be rendered from config JSON.');

    inboundAssertSame('1 KB', VpnInboundCrudController::formatBytes(1024), 'Traffic values must be formatted human-readably.');
    inboundAssertSame('-', VpnInboundCrudController::formatBytes(null), 'Missing traffic values must be shown safely.');

    $en = Yaml::parseFile(dirname(__DIR__, 2).'/translations/admin.en.yaml');
    $fa = Yaml::parseFile(dirname(__DIR__, 2).'/translations/admin.fa.yaml');
    foreach (['singular', 'plural', 'empty', 'actions', 'fields', 'sections'] as $key) {
        inboundAssertTrue(isset($en['vpn_inbound'][$key]), 'English inbound translations must include '.$key.'.');
        inboundAssertTrue(isset($fa['vpn_inbound'][$key]), 'Persian inbound translations must include '.$key.'.');
    }
    inboundAssertSame('No inbounds have been synchronized for this panel yet.', $en['vpn_inbound']['empty']['panel'] ?? null, 'English empty-state text must exist.');
    inboundAssertSame('هنوز هیچ Inboundی برای این پنل همگامسازی نشده است.', $fa['vpn_inbound']['empty']['panel'] ?? null, 'Persian empty-state text must exist.');

    echo "VPN inbound read-only admin tests passed.\n";
}

/**
 * @param iterable<mixed> $fields
 *
 * @return array<string, EasyCorp\Bundle\EasyAdminBundle\Dto\FieldDto>
 */
function inboundFieldsByProperty(iterable $fields, string $pageName): array
{
    $byProperty = [];
    foreach ($fields as $field) {
        if (!is_object($field) || !method_exists($field, 'getAsDto')) {
            continue;
        }

        $dto = $field->getAsDto();
        if (!$dto->isDisplayedOn($pageName)) {
            continue;
        }

        $byProperty[$dto->getProperty()] = $dto;
    }

    return $byProperty;
}

function inboundAssertTrue(bool $actual, string $message): void
{
    inboundAssertSame(true, $actual, $message);
}

function inboundAssertContains(mixed $needle, array $haystack, string $message): void
{
    if (!in_array($needle, $haystack, true)) {
        throw new RuntimeException($message.' Missing '.inboundDescribe($needle).'.');
    }
}

function inboundAssertStringNotContains(string $needle, string $haystack, string $message): void
{
    if (str_contains($haystack, $needle)) {
        throw new RuntimeException($message.' Found unexpected sensitive value.');
    }
}

function inboundAssertStringContains(string $needle, string $haystack, string $message): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message.' Missing '.$needle.'.');
    }
}

function inboundAssertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message.' Expected '.inboundDescribe($expected).', got '.inboundDescribe($actual).'.');
    }
}

function inboundDescribe(mixed $value): string
{
    if (is_object($value)) {
        return $value::class;
    }

    return var_export($value, true);
}

runVpnInboundAdminReadOnlyTest();

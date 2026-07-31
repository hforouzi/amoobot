<?php

declare(strict_types=1);

use App\Admin\UI\Crud\VpnPanelCrudController;
use App\Entity\VpnPanel;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Validator\Validation;

require_once dirname(__DIR__, 2).'/vendor/autoload.php';

function runVpnPanelAdminFormTest(): void
{
    $controller = new VpnPanelCrudController();
    $fields = fieldsByProperty($controller->configureFields(Crud::PAGE_NEW), Crud::PAGE_NEW);

    assertTrue(isset($fields['sanaeiIntegrationMode']), 'Sanaei integration field must be present.');
    assertStringContains('vpn-panel-mode-choice', $fields['sanaeiIntegrationMode']->getCssClass(), 'Integration mode must render as a clear mode choice.');
    assertSame(true, $fields['sanaeiIntegrationMode']->getFormTypeOption('expanded'), 'Integration mode must use expanded radio-style choices.');

    assertTrue(isset($fields['username']), 'Legacy username field must be present.');
    assertStringContains('vpn-panel-mode-legacy', $fields['username']->getCssClass(), 'Username must be marked Legacy-only.');
    assertStringContains('vpn-panel-auth-cookie', $fields['username']->getCssClass(), 'Username must be marked cookie-auth-only.');

    assertTrue(isset($fields['password']), 'Legacy password field must be present.');
    assertStringContains('vpn-panel-auth-cookie', $fields['password']->getCssClass(), 'Password must be marked cookie-auth-only.');
    assertSame(PasswordType::class, $fields['password']->getFormType(), 'Password must render as password input.');
    assertSame(true, $fields['password']->getFormTypeOption('always_empty'), 'Password field must never render the stored password.');

    assertTrue(isset($fields['apiToken']), 'API token field must be present.');
    assertStringContains('vpn-panel-token-section', $fields['apiToken']->getCssClass(), 'API token must be dynamically shown for Modern or legacy bearer.');
    assertSame(PasswordType::class, $fields['apiToken']->getFormType(), 'API token must render as password input.');
    assertSame(true, $fields['apiToken']->getFormTypeOption('always_empty'), 'API token field must never render the stored token.');

    assertTrue(isset($fields['apiVersion']), 'API version field must remain available for Legacy advanced configuration.');
    assertStringContains('vpn-panel-mode-legacy', $fields['apiVersion']->getCssClass(), 'API version must not be shown in Modern basic setup.');
    assertTrue(isset($fields['basePath']), 'Base path must remain available as an advanced Sanaei setting.');
    assertStringContains('vpn-panel-mode-sanaei', $fields['basePath']->getCssClass(), 'Base path must be shown only for Sanaei panels.');

    $validator = Validation::createValidatorBuilder()
        ->enableAttributeMapping()
        ->getValidator();

    $newModern = panel('modern')
        ->setTitle('Germany Panel 1')
        ->setBaseUrl('https://panel.example.com:2053');
    assertViolationPath($validator->validate($newModern), 'apiToken', 'New Modern panels must require an API token.');

    $existingModern = panel('modern')
        ->setTitle('Germany Panel 1')
        ->setBaseUrl('https://panel.example.com:2053')
        ->setApiToken('stored-modern-token');
    $existingModern->setApiToken('');
    assertNoViolationPath($validator->validate($existingModern), 'apiToken', 'Blank Modern token edit must preserve the stored token.');
    assertSame('stored-modern-token', $existingModern->getApiToken(), 'Blank Modern token edit must not erase the stored token.');

    $newLegacy = panel('legacy')
        ->setTitle('Legacy Panel')
        ->setBaseUrl('https://panel.example.com:2053')
        ->setAuthMode('cookie');
    assertViolationPath($validator->validate($newLegacy), 'username', 'New Legacy cookie panels must require username.');
    assertViolationPath($validator->validate($newLegacy), 'password', 'New Legacy cookie panels must require password.');

    $legacyBearer = panel('legacy')
        ->setTitle('Legacy Bearer')
        ->setBaseUrl('https://panel.example.com:2053')
        ->setAuthMode('bearer');
    assertViolationPath($validator->validate($legacyBearer), 'apiToken', 'Legacy bearer panels must require API token.');

    $switching = panel('legacy')
        ->setTitle('Switching Panel')
        ->setBaseUrl('https://panel.example.com:2053')
        ->setUsername('legacy-user')
        ->setPassword('legacy-password')
        ->setApiToken('modern-token');
    $switching->setSanaeiIntegrationMode(SanaeiIntegrationMode::MODERN);
    assertSame('legacy-user', $switching->getUsername(), 'Switching visible mode must not erase Legacy username.');
    assertSame('legacy-password', $switching->getPassword(), 'Switching visible mode must not erase Legacy password.');
    assertSame('modern-token', $switching->getApiToken(), 'Switching visible mode must not erase Modern token.');

    echo "VPN panel admin form tests passed.\n";
}

/**
 * @param iterable<mixed> $fields
 *
 * @return array<string, EasyCorp\Bundle\EasyAdminBundle\Dto\FieldDto>
 */
function fieldsByProperty(iterable $fields, string $pageName): array
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

function panel(string $mode): VpnPanel
{
    return (new VpnPanel())
        ->setType('sanaei_3xui')
        ->setSanaeiIntegrationMode('modern' === $mode ? SanaeiIntegrationMode::MODERN : SanaeiIntegrationMode::LEGACY)
        ->setUseProxy(false);
}

function assertViolationPath(Countable|iterable $violations, string $path, string $message): void
{
    foreach ($violations as $violation) {
        if ($violation->getPropertyPath() === $path) {
            return;
        }
    }

    throw new RuntimeException($message.' Expected violation at '.$path.'.');
}

function assertNoViolationPath(Countable|iterable $violations, string $path, string $message): void
{
    foreach ($violations as $violation) {
        if ($violation->getPropertyPath() === $path) {
            throw new RuntimeException($message.' Unexpected violation at '.$path.'.');
        }
    }
}

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message.' Expected '.describe($expected).', got '.describe($actual).'.');
    }
}

function assertTrue(bool $actual, string $message): void
{
    assertSame(true, $actual, $message);
}

function assertStringContains(string $needle, string $haystack, string $message): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message.' Missing '.$needle.'.');
    }
}

function describe(mixed $value): string
{
    if (is_object($value)) {
        return $value::class;
    }

    return var_export($value, true);
}

runVpnPanelAdminFormTest();

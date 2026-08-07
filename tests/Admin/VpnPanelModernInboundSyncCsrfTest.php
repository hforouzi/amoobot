<?php

declare(strict_types=1);

use App\Admin\UI\Crud\VpnPanelCrudController;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

require_once dirname(__DIR__, 2).'/vendor/autoload.php';

function runVpnPanelModernInboundSyncCsrfTest(): void
{
    $projectDir = dirname(__DIR__, 2);
    $html = renderSyncModernInboundsConfirmation($projectDir, 12);

    csrfAssertStringContains('<form', $html, 'Rendered action must contain a real form.');
    csrfAssertStringContains('method="post"', strtolower($html), 'Rendered action form must use POST.');
    csrfAssertStringContains('name="_token"', $html, 'Rendered action must submit _token in the POST body.');
    csrfAssertStringContains('value="csrf-for-sync_modern_inbounds_12"', $html, 'Rendered action must use the expected CSRF token ID.');
    csrfAssertStringContains('/admin/vpn-panel/12/sync-modern-inbounds', $html, 'Confirmation form must post to the selected panel ID.');
    csrfAssertStringNotContains('token=', $html, 'Rendered action must not put the CSRF token in the URL.');
    csrfAssertStringNotContains('csrf-for-sync_modern_inbounds_13', $html, 'Rendered action must not use another panel token.');

    $controllerSource = file_get_contents($projectDir.'/src/Admin/UI/VpnPanelInboundActionsController.php') ?: '';
    csrfAssertStringContains('admin_vpn_panel_sync_modern_inbounds_confirm', $controllerSource, 'Controller must expose the GET confirmation route.');
    csrfAssertStringContains("request->request->get('_token'", $controllerSource, 'Controller must read _token from POST body.');
    csrfAssertStringNotContains("request->query->get('token'", $controllerSource, 'Controller must not read CSRF token from query string.');
    csrfAssertStringContains("sprintf('sync_modern_inbounds_%d'", $controllerSource, 'Controller must validate with the shared token ID format.');

    $panelCrudSource = file_get_contents($projectDir.'/src/Admin/UI/Crud/VpnPanelCrudController.php') ?: '';
    csrfAssertStringContains("'admin_vpn_panel_sync_modern_inbounds_confirm'", $panelCrudSource, 'EasyAdmin action must link to the GET confirmation route.');
    csrfAssertStringContains('SanaeiIntegrationMode::MODERN === $panel->getSanaeiIntegrationMode()', $panelCrudSource, 'Modern sync action must be visible only for Modern panels.');
    csrfAssertStringContains("null !== \$panel->getId()", $panelCrudSource, 'Modern sync action must be visible only for persisted panels.');
    csrfAssertStringNotContains("getToken(\$this->modernInboundSyncTokenId", $panelCrudSource, 'CSRF token generation must not happen in route query parameters.');
    csrfAssertStringNotContains('sync_modern_inbounds.html.twig', $panelCrudSource, 'EasyAdmin action must not depend on a direct POST action template.');

    $controller = new VpnPanelCrudController();
    $actions = $controller->configureActions(actionsWithBuiltIns())->getAsDto(null);
    $action = $actions->getAction('index', 'syncModernInbounds');
    csrfAssertSame('admin_vpn_panel_sync_modern_inbounds_confirm', $action?->getRouteName(), 'Sync Modern Inbounds action must target the confirmation route.');

    echo "VPN panel Modern inbound sync CSRF tests passed.\n";
}

function renderSyncModernInboundsConfirmation(string $projectDir, int $panelId): string
{
    $twig = new Environment(new FilesystemLoader($projectDir.'/templates'));
    $twig->addFunction(new TwigFunction('path', static function (string $route, array $parameters = []): string {
        if ('admin_vpn_panel_sync_modern_inbounds' === $route) {
            return sprintf('/admin/vpn-panel/%d/sync-modern-inbounds', (int) ($parameters['id'] ?? 0));
        }

        return '#'.$route;
    }));
    $twig->addFunction(new TwigFunction('csrf_token', static fn (string $id): string => 'csrf-for-'.$id));
    $twig->addFunction(new TwigFunction('asset', static fn (string $path): string => '/'.$path));
    $twig->addExtension(new TranslationExtension(new Translator('en')));

    return $twig->render('admin/vpn_panel/sync_modern_inbounds_confirm.html.twig', [
        'panel' => [
            'id' => $panelId,
            'title' => 'Modern Panel',
        ],
        'cancel_url' => '/admin?crudAction=detail&entityId='.$panelId,
        'app' => [
            'request' => [
                'locale' => 'en',
                'uri' => '/admin/vpn-panel/'.$panelId.'/sync-modern-inbounds/confirm',
            ],
        ],
    ]);
}

function actionsWithBuiltIns(): EasyCorp\Bundle\EasyAdminBundle\Config\Actions
{
    return EasyCorp\Bundle\EasyAdminBundle\Config\Actions::new()
        ->add(EasyCorp\Bundle\EasyAdminBundle\Config\Crud::PAGE_NEW, EasyCorp\Bundle\EasyAdminBundle\Config\Action::SAVE_AND_RETURN)
        ->add(EasyCorp\Bundle\EasyAdminBundle\Config\Crud::PAGE_EDIT, EasyCorp\Bundle\EasyAdminBundle\Config\Action::SAVE_AND_RETURN)
        ->add(EasyCorp\Bundle\EasyAdminBundle\Config\Crud::PAGE_EDIT, EasyCorp\Bundle\EasyAdminBundle\Config\Action::SAVE_AND_CONTINUE);
}

function csrfAssertStringContains(string $needle, string $haystack, string $message): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message.' Missing '.$needle.'.');
    }
}

function csrfAssertStringNotContains(string $needle, string $haystack, string $message): void
{
    if (str_contains($haystack, $needle)) {
        throw new RuntimeException($message.' Found unexpected '.$needle.'.');
    }
}

function csrfAssertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message.' Expected '.var_export($expected, true).', got '.var_export($actual, true).'.');
    }
}

runVpnPanelModernInboundSyncCsrfTest();

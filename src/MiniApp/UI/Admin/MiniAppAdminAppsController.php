<?php

declare(strict_types=1);

namespace App\MiniApp\UI\Admin;

use App\Entity\AppDownload;
use App\Entity\ClientApp;
use App\MiniApp\Admin\AdminActions;
use App\MiniApp\Admin\Catalog\CatalogEditor;
use App\MiniApp\Security\MiniAppUser;
use App\MiniApp\UI\MiniAppFragments;
use App\MiniApp\UI\MiniAppLocale;
use App\Security\AdminAuditLogger;
use App\Subscription\Domain\ClientPlatform;
use App\Subscription\Domain\ImportLinkTemplate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Client apps and app downloads in the Mini App admin (APPS_MANAGE), management only: both lists in the web's order,
 * enable / disable (confirmed), reorder (one step up or down; downloads within their platform, as the subscription
 * page groups them); a client app's name, platforms and import link template, checked live with the entity's rules
 * (ImportLinkTemplate: no javascript: / data: / file:, a URL placeholder required) and previewed on an example
 * subscription URL as the web's detail page does; a download's title, version, description and platform, and a new
 * download only as an external http(s) link. Files (upload, replace) stay in the web panel; a file download shows
 * its size, SHA-256 and download count. Audit names are the web's (client_app.*, app_download.*), source miniapp.
 */
#[IsGranted(MiniAppUser::ROLE)]
final class MiniAppAdminAppsController extends AbstractController
{
    /** What the import link is previewed with (as ClientAppCrudController's detail page). */
    public const EXAMPLE_URL = 'https://sub.example.com/sub/abc123';
    public const TABS = ['clients', 'downloads'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CatalogEditor $editor,
        private readonly AdminActions $actions,
        private readonly AdminAuditLogger $auditLogger,
        private readonly MiniAppFragments $fragments,
        private readonly ValidatorInterface $validator,
        private readonly TranslatorInterface $translator,
        private readonly LocaleSwitcher $localeSwitcher,
    ) {
    }

    #[Route('/app/admin/apps', name: 'miniapp_admin_apps', defaults: ['_miniapp_section' => 'admin.apps'], methods: ['GET'])]
    public function list(Request $request): Response
    {
        $tab = in_array($request->query->getString('tab'), self::TABS, true) ? $request->query->getString('tab') : 'clients';

        return $this->fragments->render($request, 'miniapp/admin/apps.html.twig', [
            'tab' => $tab,
            'tabs' => self::TABS,
            'counts' => ['clients' => $this->entityManager->getRepository(ClientApp::class)->count([]), 'downloads' => $this->entityManager->getRepository(AppDownload::class)->count([])],
            'clientApps' => 'clients' === $tab ? $this->clientApps() : [],
            'downloads' => 'downloads' === $tab ? $this->downloads(null) : [],
        ]);
    }

    #[Route('/app/admin/client-apps/{id}', name: 'miniapp_admin_client_app', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.apps'], methods: ['GET'])]
    public function clientApp(Request $request, int $id): Response
    {
        $app = $this->find(ClientApp::class, $id);

        return $this->fragments->render($request, 'miniapp/admin/client_app.html.twig', [
            'app' => $app,
            'platforms' => ClientPlatform::cases(),
            'example' => ImportLinkTemplate::build($app->getImportUrlTemplate(), self::EXAMPLE_URL, 'VPN'),
            'exampleUrl' => self::EXAMPLE_URL,
        ]);
    }

    #[Route('/app/api/admin/client-apps/{id}', name: 'miniapp_admin_client_app_update', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.apps'], methods: ['POST'])]
    public function updateClientApp(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        $app = $this->find(ClientApp::class, $id);
        $before = self::clientAppValues($app);
        $body = AdminForms::body($request);

        return $this->editor->submit(
            $request, $user, $id, $app,
            static fn (): array => self::applyClientApp($app, $body),
            fn (): array => $this->violations($request, $app),
            static fn (): ?string => null,
            function () use ($request, $user, $app, $before): array {
                $diff = CatalogEditor::diff($before, self::clientAppValues($app), []);
                $app->touch();
                $this->entityManager->flush();
                $this->auditLogger->record('client_app.update', 'ClientApp', $app->getId(), $diff, $user->admin);

                return ['message' => $this->message($request, [] === $diff['changed'] ? 'admin.form.nothing_changed' : 'admin.apps.saved')];
            },
        );
    }

    /** Live validation and the import link built on the example URL; nothing saved. */
    #[Route('/app/api/admin/client-apps/check', name: 'miniapp_admin_client_app_check', defaults: ['_miniapp_section' => 'admin.apps'], methods: ['POST'])]
    public function checkClientApp(Request $request): JsonResponse
    {
        $app = new ClientApp();
        self::applyClientApp($app, AdminForms::body($request));
        $errors = $this->violations($request, $app);
        $example = isset($errors['importUrlTemplate']) ? null : ImportLinkTemplate::build($app->getImportUrlTemplate(), self::EXAMPLE_URL, 'VPN');

        return AdminActions::json(['errors' => (object) $errors, 'warnings' => [], 'live' => ['example' => $example ?? '—']]);
    }

    #[Route('/app/api/admin/client-apps/{id}/activate', name: 'miniapp_admin_client_app_activate', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.apps'], methods: ['POST'])]
    public function activateClientApp(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        return $this->toggle($request, $user, $this->find(ClientApp::class, $id), true);
    }

    #[Route('/app/api/admin/client-apps/{id}/deactivate', name: 'miniapp_admin_client_app_deactivate', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.apps'], methods: ['POST'])]
    public function deactivateClientApp(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        return $this->toggle($request, $user, $this->find(ClientApp::class, $id), false);
    }

    #[Route('/app/api/admin/client-apps/{id}/move', name: 'miniapp_admin_client_app_move', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.apps'], methods: ['POST'])]
    public function moveClientApp(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        $app = $this->find(ClientApp::class, $id);

        return $this->move($request, $user, $app, fn (): array => $this->clientApps());
    }

    #[Route('/app/admin/downloads/new', name: 'miniapp_admin_download_new', defaults: ['_miniapp_section' => 'admin.apps'], methods: ['GET'])]
    public function newDownload(Request $request): Response
    {
        return $this->downloadPage($request, (new AppDownload())->setType(AppDownload::TYPE_URL));
    }

    #[Route('/app/admin/downloads/{id}', name: 'miniapp_admin_download', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.apps'], methods: ['GET'])]
    public function download(Request $request, int $id): Response
    {
        return $this->downloadPage($request, $this->find(AppDownload::class, $id));
    }

    /** A new download is always an external link (files are uploaded in the web panel). */
    #[Route('/app/api/admin/downloads', name: 'miniapp_admin_download_create', defaults: ['_miniapp_section' => 'admin.apps'], methods: ['POST'])]
    public function createDownload(Request $request, #[CurrentUser] MiniAppUser $user): JsonResponse
    {
        $download = (new AppDownload())->setType(AppDownload::TYPE_URL);
        $body = AdminForms::body($request);

        return $this->editor->submit(
            $request, $user, 0, null,
            static fn (): array => self::applyDownload($download, $body),
            fn (): array => $this->violations($request, $download),
            static fn (): ?string => null,
            function () use ($request, $user, $download): array {
                $download->setSortOrder(10 + count($this->downloads($download->getPlatform())) * 10);
                $this->entityManager->persist($download);
                $this->entityManager->flush();
                $this->auditLogger->record('app_download.create', 'AppDownload', $download->getId(), ['title' => $download->getTitle(), 'type' => $download->getType()], $user->admin);

                return ['message' => $this->message($request, 'admin.apps.download_created'), 'redirect' => $this->generateUrl('miniapp_admin_download', ['id' => $download->getId()])];
            },
        );
    }

    #[Route('/app/api/admin/downloads/{id}', name: 'miniapp_admin_download_update', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.apps'], methods: ['POST'])]
    public function updateDownload(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        $download = $this->find(AppDownload::class, $id);
        $before = self::downloadValues($download);
        $body = AdminForms::body($request);

        return $this->editor->submit(
            $request, $user, $id, $download,
            static fn (): array => self::applyDownload($download, $body),
            fn (): array => $this->violations($request, $download),
            static fn (): ?string => null,
            function () use ($request, $user, $download, $before): array {
                $diff = CatalogEditor::diff($before, self::downloadValues($download), []);
                $download->touch();
                $this->entityManager->flush();
                $this->auditLogger->record('app_download.update', 'AppDownload', $download->getId(), $diff, $user->admin);

                return ['message' => $this->message($request, [] === $diff['changed'] ? 'admin.form.nothing_changed' : 'admin.apps.saved')];
            },
        );
    }

    #[Route('/app/api/admin/downloads/check', name: 'miniapp_admin_download_check', defaults: ['_miniapp_section' => 'admin.apps'], methods: ['POST'])]
    public function checkDownload(Request $request): JsonResponse
    {
        $body = AdminForms::body($request);
        $id = (int) ($body['id'] ?? 0);
        // A detached copy with the stored one's kind (file or link): the check never touches a stored download.
        $download = (new AppDownload())->setType($id > 0 ? $this->find(AppDownload::class, $id)->getType() : AppDownload::TYPE_URL);
        self::applyDownload($download, $body);

        return AdminActions::json(['errors' => (object) $this->violations($request, $download), 'warnings' => []]);
    }

    #[Route('/app/api/admin/downloads/{id}/activate', name: 'miniapp_admin_download_activate', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.apps'], methods: ['POST'])]
    public function activateDownload(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        return $this->toggle($request, $user, $this->find(AppDownload::class, $id), true);
    }

    #[Route('/app/api/admin/downloads/{id}/deactivate', name: 'miniapp_admin_download_deactivate', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.apps'], methods: ['POST'])]
    public function deactivateDownload(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        return $this->toggle($request, $user, $this->find(AppDownload::class, $id), false);
    }

    #[Route('/app/api/admin/downloads/{id}/move', name: 'miniapp_admin_download_move', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.apps'], methods: ['POST'])]
    public function moveDownload(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        $download = $this->find(AppDownload::class, $id);

        return $this->move($request, $user, $download, fn (): array => $this->downloads($download->getPlatform()));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, string> nothing unreadable: every field is text or a choice the entity checks
     */
    private static function applyClientApp(ClientApp $app, array $body): array
    {
        $app->setName(mb_substr(self::text($body, 'name'), 0, 200));
        $platforms = is_array($body['platforms'] ?? null) ? $body['platforms'] : [];
        $app->setPlatforms(array_values(array_unique(array_map(static fn (mixed $p): string => is_scalar($p) ? (string) $p : '', $platforms))));
        $template = self::text($body, 'importUrlTemplate');
        $app->setImportUrlTemplate('' === $template ? null : mb_substr($template, 0, 2000));

        return [];
    }

    /**
     * The metadata the Mini App edits; the external link only on a link download (a file stays a file).
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, string>
     */
    private static function applyDownload(AppDownload $download, array $body): array
    {
        $download->setTitle(mb_substr(self::text($body, 'title'), 0, 300));
        $download->setPlatform(self::text($body, 'platform'));
        $version = self::text($body, 'version');
        $download->setVersion('' === $version ? null : mb_substr($version, 0, 100));
        $description = self::text($body, 'description');
        $download->setDescription('' === $description ? null : mb_substr($description, 0, 4000));
        if (!$download->isFile()) {
            $url = self::text($body, 'externalUrl');
            $download->setExternalUrl('' === $url ? null : mb_substr($url, 0, 1000));
        }

        return [];
    }

    /**
     * @return array<string, scalar|null>
     */
    private static function clientAppValues(ClientApp $app): array
    {
        return ['name' => $app->getName(), 'platforms' => implode(',', $app->getPlatforms()), 'importUrlTemplate' => $app->getImportUrlTemplate()];
    }

    /**
     * @return array<string, scalar|null>
     */
    private static function downloadValues(AppDownload $download): array
    {
        return ['title' => $download->getTitle(), 'platform' => $download->getPlatform(), 'version' => $download->getVersion(), 'description' => $download->getDescription(), 'externalUrl' => $download->getExternalUrl()];
    }

    private function toggle(Request $request, MiniAppUser $user, ClientApp|AppDownload $item, bool $active): JsonResponse
    {
        [$name, $type, $label] = $item instanceof ClientApp ? ['client_app', 'ClientApp', $item->getName()] : ['app_download', 'AppDownload', $item->getTitle()];
        $confirm = $this->message($request, $active ? 'admin.apps.confirm.activate' : 'admin.apps.confirm.deactivate', ['%name%' => $label]);

        return $this->actions->run($request, $user, (int) $item->getId(), $confirm, function () use ($request, $user, $item, $active, $name, $type, $label): array {
            if ($item->isActive() === $active) {
                return ['error' => 'state_changed', 'message' => $this->message($request, 'admin.form.nothing_changed'), 'status' => Response::HTTP_CONFLICT];
            }
            $item->setIsActive($active)->touch();
            $this->entityManager->flush();
            $this->auditLogger->record($name.'.'.($active ? 'activate' : 'deactivate'), $type, $item->getId(), ['name' => $label], $user->admin);

            return ['message' => $this->message($request, $active ? 'admin.plan.activated' : 'admin.plan.deactivated')];
        });
    }

    /**
     * One step up or down in its list; the list is then numbered 10, 20, … so ties of the old order disappear.
     *
     * @param callable(): list<ClientApp|AppDownload> $ordered
     */
    private function move(Request $request, MiniAppUser $user, ClientApp|AppDownload $item, callable $ordered): JsonResponse
    {
        $direction = AdminForms::body($request)['direction'] ?? null;
        if (!in_array($direction, ['up', 'down'], true)) {
            return $this->editor->invalid($request, ['direction' => [$this->message($request, 'admin.form.choice')]]);
        }

        return $this->actions->run($request, $user, (int) $item->getId(), null, function () use ($request, $user, $item, $ordered, $direction): array {
            $list = $ordered();
            $index = array_search($item, $list, true);
            $other = false === $index ? null : $index + ('up' === $direction ? -1 : 1);
            if (null === $other || !isset($list[$other])) {
                return ['message' => $this->message($request, 'admin.form.nothing_changed')];
            }
            [$list[$index], $list[$other]] = [$list[$other], $list[$index]];
            foreach ($list as $position => $entry) {
                $entry->setSortOrder(10 * ($position + 1));
            }
            $this->entityManager->flush();
            [$name, $type] = $item instanceof ClientApp ? ['client_app', 'ClientApp'] : ['app_download', 'AppDownload'];
            $this->auditLogger->record($name.'.reorder', $type, $item->getId(), ['direction' => $direction, 'position' => $other + 1], $user->admin);

            return ['message' => $this->message($request, 'admin.apps.moved')];
        });
    }

    private function downloadPage(Request $request, AppDownload $download): Response
    {
        return $this->fragments->render($request, 'miniapp/admin/download.html.twig', [
            'download' => $download,
            'isNew' => null === $download->getId(),
            'platforms' => ClientPlatform::cases(),
        ]);
    }

    /**
     * @return list<ClientApp>
     */
    private function clientApps(): array
    {
        return $this->entityManager->getRepository(ClientApp::class)->findBy([], ['sortOrder' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * The web list's order (platform, then order); one platform's downloads when given.
     *
     * @return list<AppDownload>
     */
    private function downloads(?string $platform): array
    {
        return $this->entityManager->getRepository(AppDownload::class)->findBy(null === $platform ? [] : ['platform' => $platform], ['platform' => 'ASC', 'sortOrder' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * The entity's rules (the web form's), by field, in the request's language.
     *
     * @return array<string, list<string>>
     */
    private function violations(Request $request, object $entity): array
    {
        $this->localeSwitcher->setLocale(MiniAppLocale::fromRequest($request));
        $errors = [];
        foreach ($this->validator->validate($entity) as $violation) {
            $path = (string) preg_replace('/\[\d+\]$/', '', $violation->getPropertyPath());
            $errors[$path][] = (string) $violation->getMessage();
        }

        return $errors;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function find(string $class, int $id): object
    {
        return $this->entityManager->find($class, $id) ?? throw new NotFoundHttpException();
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function text(array $body, string $field): string
    {
        return is_scalar($body[$field] ?? null) ? trim((string) $body[$field]) : '';
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function message(Request $request, string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, 'miniapp', MiniAppLocale::fromRequest($request));
    }
}

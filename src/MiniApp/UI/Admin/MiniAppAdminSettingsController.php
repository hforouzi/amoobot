<?php

declare(strict_types=1);

namespace App\MiniApp\UI\Admin;

use App\Admin\Application\SettingsAdministration;
use App\Entity\RequiredChannel;
use App\MiniApp\Admin\AdminActions;
use App\MiniApp\Admin\AdminText;
use App\MiniApp\Admin\Catalog\CatalogEditor;
use App\MiniApp\Security\MiniAppUser;
use App\MiniApp\UI\MiniAppFragments;
use App\MiniApp\UI\MiniAppLocale;
use App\Security\AdminAuditLogger;
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
 * Settings in the Mini App admin (SETTINGS_MANAGE, the super admin's, as on the web): the web settings page's two forms
 * (sales & pricing: sales switches, sales-closed message, renewal pricing, traffic add-on; automation: switches, batch
 * limit, incomplete-order expiry), read, checked and saved by SettingsAdministration, so the keys, the validation and
 * its messages are the web's; only those keys, nothing secret and no raw key / value editor. A valid change is
 * confirmed (it changes the shop for everyone) and audited with the keys it changed. Required channels: the list, add
 * (the entity's rules, shared with the web form), enable / disable and remove, each confirmed.
 */
#[IsGranted(MiniAppUser::ROLE)]
final class MiniAppAdminSettingsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SettingsAdministration $settings,
        private readonly CatalogEditor $editor,
        private readonly AdminActions $actions,
        private readonly AdminAuditLogger $auditLogger,
        private readonly MiniAppFragments $fragments,
        private readonly ValidatorInterface $validator,
        private readonly TranslatorInterface $translator,
        private readonly LocaleSwitcher $localeSwitcher,
    ) {
    }

    #[Route('/app/admin/settings', name: 'miniapp_admin_settings', defaults: ['_miniapp_section' => 'admin.settings'], methods: ['GET'])]
    public function show(Request $request): Response
    {
        return $this->fragments->render($request, 'miniapp/admin/settings.html.twig', [
            'sales' => $this->settings->salesValues(),
            'automation' => $this->settings->automationValues(),
            'channels' => $this->entityManager->getRepository(RequiredChannel::class)->count([]),
        ]);
    }

    #[Route('/app/api/admin/settings/sales', name: 'miniapp_admin_settings_sales', defaults: ['_miniapp_section' => 'admin.settings'], methods: ['POST'])]
    public function saveSales(Request $request, #[CurrentUser] MiniAppUser $user): JsonResponse
    {
        $form = self::form($request);

        return $this->save($request, $user, 'sales', $this->settings->salesProblem($form), function () use ($form): ?array {
            $before = $this->settings->salesValues();

            return null === $this->settings->saveSales($form) ? self::changed($before, $this->settings->salesValues()) : null;
        });
    }

    #[Route('/app/api/admin/settings/automation', name: 'miniapp_admin_settings_automation', defaults: ['_miniapp_section' => 'admin.settings'], methods: ['POST'])]
    public function saveAutomation(Request $request, #[CurrentUser] MiniAppUser $user): JsonResponse
    {
        $form = self::form($request);

        return $this->save($request, $user, 'automation', $this->settings->automationProblem($form), function () use ($form): ?array {
            $before = $this->settings->automationValues();

            return null === $this->settings->saveAutomation($form) ? self::changed($before, $this->settings->automationValues()) : null;
        });
    }

    #[Route('/app/admin/settings/channels', name: 'miniapp_admin_channels', defaults: ['_miniapp_section' => 'admin.settings'], methods: ['GET'])]
    public function channels(Request $request): Response
    {
        return $this->fragments->render($request, 'miniapp/admin/channels.html.twig', [
            'channels' => $this->entityManager->getRepository(RequiredChannel::class)->findBy([], ['sortOrder' => 'ASC', 'id' => 'ASC']),
        ]);
    }

    #[Route('/app/api/admin/channels', name: 'miniapp_admin_channel_create', defaults: ['_miniapp_section' => 'admin.settings'], methods: ['POST'])]
    public function createChannel(Request $request, #[CurrentUser] MiniAppUser $user): JsonResponse
    {
        $body = AdminForms::body($request);
        $text = static fn (string $field): string => is_scalar($body[$field] ?? null) ? trim((string) $body[$field]) : '';
        $on = static fn (string $field): bool => in_array($text($field), ['1', 'true', 'on'], true);
        $channel = (new RequiredChannel())
            ->setTitle(mb_substr($text('title'), 0, 300))
            ->setChatId(mb_substr($text('chatId'), 0, 300))
            ->setInviteUrl(mb_substr($text('inviteUrl'), 0, 300))
            ->setIsActive($on('isActive'))
            ->setRequireForPurchase($on('requireForPurchase'))
            ->setRequireForTrial($on('requireForTrial'));

        return $this->editor->submit(
            $request, $user, 0, null,
            static fn (): array => [],
            fn (): array => $this->violations($request, $channel),
            static fn (): ?string => null,
            function () use ($request, $user, $channel): array {
                $channel->setSortOrder(1 + (int) $this->entityManager->createQueryBuilder()->select('MAX(c.sortOrder)')->from(RequiredChannel::class, 'c')->getQuery()->getSingleScalarResult());
                $this->entityManager->persist($channel);
                $this->entityManager->flush();
                $this->auditLogger->record('required_channel.create', 'RequiredChannel', $channel->getId(), ['title' => $channel->getTitle(), 'chatId' => $channel->getChatId()], $user->admin);

                return ['message' => $this->message($request, 'admin.channel.created'), 'redirect' => $this->generateUrl('miniapp_admin_channels')];
            },
        );
    }

    #[Route('/app/api/admin/channels/{id}/activate', name: 'miniapp_admin_channel_activate', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.settings'], methods: ['POST'])]
    public function activateChannel(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        return $this->toggleChannel($request, $user, $id, true);
    }

    #[Route('/app/api/admin/channels/{id}/deactivate', name: 'miniapp_admin_channel_deactivate', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.settings'], methods: ['POST'])]
    public function deactivateChannel(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        return $this->toggleChannel($request, $user, $id, false);
    }

    #[Route('/app/api/admin/channels/{id}/delete', name: 'miniapp_admin_channel_delete', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'admin.settings'], methods: ['POST'])]
    public function deleteChannel(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        $channel = $this->channel($id);
        $confirm = $this->message($request, 'admin.channel.confirm.delete', ['%title%' => $channel->getTitle()]);

        return $this->actions->run($request, $user, $id, $confirm, function () use ($request, $user, $channel, $id): array {
            $details = ['title' => $channel->getTitle(), 'chatId' => $channel->getChatId()];
            $this->entityManager->remove($channel);
            $this->entityManager->flush();
            $this->auditLogger->record('required_channel.delete', 'RequiredChannel', $id, $details, $user->admin);

            return ['message' => $this->message($request, 'admin.channel.deleted'), 'redirect' => $this->generateUrl('miniapp_admin_channels')];
        });
    }

    /**
     * @param array{field: string, message: string}|null $problem
     * @param callable(): ?array<string, mixed>          $save    saves; the changed keys (null: refused meanwhile)
     */
    private function save(Request $request, MiniAppUser $user, string $page, ?array $problem, callable $save): JsonResponse
    {
        if (null !== $problem) {
            return $this->editor->invalid($request, [$problem['field'] => [$this->translator->trans($problem['message'], [], 'admin', MiniAppLocale::fromRequest($request))]]);
        }
        $confirm = $this->message($request, 'admin.settings.confirm.'.$page);

        return $this->actions->run($request, $user, 0, $confirm, function () use ($request, $user, $page, $save): array {
            $changed = $save();
            if (null === $changed) {
                return ['error' => 'invalid', 'message' => $this->message($request, 'admin.form.invalid'), 'status' => Response::HTTP_UNPROCESSABLE_ENTITY];
            }
            // The web page's audit (page: renewal_pricing / automation), with what changed.
            $this->auditLogger->record('settings.update', 'Setting', null, ['page' => 'sales' === $page ? 'renewal_pricing' : 'automation'] + $changed, $user->admin);

            return ['message' => $this->message($request, [] === $changed['changed'] ? 'admin.form.nothing_changed' : 'admin.settings.saved')];
        });
    }

    /**
     * The form as the web page gets it, numbers typed with Persian / Arabic digits read as Latin ones.
     *
     * @return array<string, mixed>
     */
    private static function form(Request $request): array
    {
        $form = AdminForms::body($request);
        foreach ($form as $field => $value) {
            if ('sales_disabled_message' !== $field && is_string($value)) {
                $form[$field] = AdminText::latinDigits($value);
            }
        }

        return $form;
    }

    /**
     * @param array<string, scalar> $before
     * @param array<string, scalar> $after
     *
     * @return array{changed: list<string>}
     */
    private static function changed(array $before, array $after): array
    {
        $keys = SettingsAdministration::SALES_KEYS + SettingsAdministration::AUTOMATION_KEYS;
        $changed = [];
        foreach ($after as $field => $value) {
            if (($before[$field] ?? null) != $value) {
                $changed[] = $keys[$field] ?? $field;
            }
        }

        return ['changed' => $changed];
    }

    private function toggleChannel(Request $request, MiniAppUser $user, int $id, bool $active): JsonResponse
    {
        $channel = $this->channel($id);
        $confirm = $this->message($request, $active ? 'admin.channel.confirm.activate' : 'admin.channel.confirm.deactivate', ['%title%' => $channel->getTitle()]);

        return $this->actions->run($request, $user, $id, $confirm, function () use ($request, $user, $channel, $active): array {
            if ($channel->isActive() === $active) {
                return ['error' => 'state_changed', 'message' => $this->message($request, 'admin.form.nothing_changed'), 'status' => Response::HTTP_CONFLICT];
            }
            $channel->setIsActive($active)->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();
            $this->auditLogger->record($active ? 'required_channel.activate' : 'required_channel.deactivate', 'RequiredChannel', $channel->getId(), ['title' => $channel->getTitle()], $user->admin);

            return ['message' => $this->message($request, $active ? 'admin.plan.activated' : 'admin.plan.deactivated')];
        });
    }

    /**
     * The entity's rules (the web form's), by field, in the request's language.
     *
     * @return array<string, list<string>>
     */
    private function violations(Request $request, RequiredChannel $channel): array
    {
        $this->localeSwitcher->setLocale(MiniAppLocale::fromRequest($request));
        $errors = [];
        foreach ($this->validator->validate($channel) as $violation) {
            $errors[$violation->getPropertyPath()][] = (string) $violation->getMessage();
        }

        return $errors;
    }

    private function channel(int $id): RequiredChannel
    {
        return $this->entityManager->find(RequiredChannel::class, $id) ?? throw new NotFoundHttpException();
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function message(Request $request, string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, 'miniapp', MiniAppLocale::fromRequest($request));
    }
}

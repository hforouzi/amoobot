<?php

declare(strict_types=1);

namespace App\Admin\UI;

use App\Admin\UI\Crud\BotMessageTemplateCrudController;
use App\Entity\BotMessageTemplate;
use App\MiniApp\Guide\GuidePreview;
use App\MiniApp\Guide\GuideTextRules;
use App\Security\AdminAuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Mini App guide's texts in Bot Content (docs/design/help-guide.md, sections 5 and 6), next to the EasyAdmin form
 * of BotMessageTemplateCrudController; the same permission, BOT_TEXTS_MANAGE (AdminCrudPermissions):
 *
 *  - preview: POST with a CSRF token, the text being edited rendered as the Mini App shows it (GuidePreview) and the
 *    problems saving it would meet; nothing is saved;
 *  - reset to default: a confirmation page, then POST with a CSRF token deletes the admin's row of a guide text, so the
 *    shipped default (translations/bot.*.yaml) shows again; audited as "bot_text.reset" (key and language only).
 */
final class GuideTextAdminController extends AbstractController
{
    use TranslatedFlashes;

    public const CSRF = 'guide_text';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GuidePreview $preview,
        private readonly AdminAuditLogger $auditLogger,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    #[AdminRoute(path: '/bot-texts/guide/preview', name: 'bot_texts_guide_preview', options: ['methods' => ['POST']])]
    public function preview(Request $request): JsonResponse
    {
        if (!$this->validToken($request)) {
            return new JsonResponse(['error' => 'csrf'], Response::HTTP_FORBIDDEN);
        }
        $key = $request->request->get('key');
        $locale = $request->request->get('locale');
        $text = $request->request->get('text');
        if (!is_string($key) || !GuideTextRules::isGuideKey($key) || !in_array($locale, ['fa', 'en'], true) || !is_string($text)) {
            return new JsonResponse(['error' => 'invalid'], Response::HTTP_BAD_REQUEST);
        }
        if (strlen($text) > GuidePreview::MAX_INPUT) {
            return new JsonResponse(['error' => 'too_long'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $response = new JsonResponse($this->preview->preview($key, $locale, $text));
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    #[AdminRoute(path: '/bot-texts/guide/{id}/reset', name: 'bot_texts_guide_reset', options: ['requirements' => ['id' => '\d+'], 'methods' => ['GET', 'POST']])]
    public function reset(Request $request, int $id): Response
    {
        $row = $this->entityManager->getRepository(BotMessageTemplate::class)->find($id);
        if (!$row instanceof BotMessageTemplate || !GuideTextRules::isGuideKey($row->getKey())) {
            throw new NotFoundHttpException();
        }
        if (!$request->isMethod('POST')) {
            return $this->render('admin/bot_text/guide_reset.html.twig', [
                'row' => $row,
                'csrf' => self::CSRF,
                'cancelUrl' => $this->editUrl($row),
            ]);
        }
        if (!$this->validToken($request)) {
            $this->flash('danger', 'flash.csrf_expired');

            return $this->redirect($this->editUrl($row));
        }

        $key = $row->getKey();
        $locale = $row->getLocale();
        $this->entityManager->remove($row);
        $this->entityManager->flush();
        $this->auditLogger->record('bot_text.reset', 'BotMessageTemplate', $id, ['key' => $key, 'locale' => $locale]);
        $this->flash('success', 'guide_text.reset.done', ['%key%' => $key, '%locale%' => $locale]);

        return $this->redirect(BotMessageTemplateCrudController::guideIndexUrl($this->adminUrlGenerator));
    }

    private function editUrl(BotMessageTemplate $row): string
    {
        return $this->adminUrlGenerator->unsetAll()->setController(BotMessageTemplateCrudController::class)->setAction('edit')->setEntityId($row->getId())->generateUrl();
    }

    private function validToken(Request $request): bool
    {
        $token = $request->request->get('_token');

        return is_string($token) && $this->isCsrfTokenValid(self::CSRF, $token);
    }
}

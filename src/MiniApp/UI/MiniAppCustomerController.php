<?php

declare(strict_types=1);

namespace App\MiniApp\UI;

use App\Bot\Application\BotTextResolver;
use App\MiniApp\Security\MiniAppUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The customer sections of the Mini App.
 */
#[IsGranted(MiniAppUser::ROLE)]
final class MiniAppCustomerController extends AbstractController
{
    public function __construct(
        private readonly MiniAppFragments $fragments,
        private readonly BotTextResolver $botTexts,
    ) {
    }

    #[Route('/app/view/home', name: 'miniapp_home', defaults: ['_miniapp_section' => 'home'], methods: ['GET'])]
    public function home(Request $request, #[CurrentUser] MiniAppUser $user): Response
    {
        return $this->fragments->render($request, 'miniapp/view/home.html.twig', ['firstName' => (string) $user->account->getFirstName()]);
    }

    #[Route('/app/view/services', name: 'miniapp_services', defaults: ['_miniapp_section' => 'services'], methods: ['GET'])]
    public function services(Request $request): Response
    {
        return $this->fragments->render($request, 'miniapp/view/services.html.twig');
    }

    #[Route('/app/view/apps', name: 'miniapp_apps', defaults: ['_miniapp_section' => 'apps'], methods: ['GET'])]
    public function apps(Request $request): Response
    {
        return $this->fragments->render($request, 'miniapp/view/apps.html.twig');
    }

    /** The bot's own support text (bot.support, editable in the admin's bot texts), so both say the same. */
    #[Route('/app/view/help', name: 'miniapp_help', defaults: ['_miniapp_section' => 'help'], methods: ['GET'])]
    public function help(Request $request): Response
    {
        return $this->fragments->render($request, 'miniapp/view/help.html.twig', [
            'supportText' => $this->botTexts->message('bot.support', [], MiniAppLocale::fromRequest($request)),
        ]);
    }
}

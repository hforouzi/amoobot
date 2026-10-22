<?php

declare(strict_types=1);

namespace App\MiniApp\UI;

use App\MiniApp\Navigation\MiniAppSections;
use App\MiniApp\Security\MiniAppUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The shell's own fragment: the bottom navigation, built per user from MiniAppSections. The admin entry is
 * MiniAppAdminHomeController.
 */
#[IsGranted(MiniAppUser::ROLE)]
final class MiniAppShellController extends AbstractController
{
    public function __construct(
        private readonly MiniAppSections $sections,
        private readonly MiniAppFragments $fragments,
    ) {
    }

    #[Route('/app/view/nav', name: 'miniapp_nav', methods: ['GET'])]
    public function nav(Request $request, #[CurrentUser] MiniAppUser $user): Response
    {
        return $this->fragments->render($request, 'miniapp/_nav.html.twig', ['sections' => $this->sections->navigation($user)]);
    }
}

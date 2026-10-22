<?php

declare(strict_types=1);

namespace App\MiniApp\UI\Admin;

use App\Admin\UI\Dashboard\DashboardOverview;
use App\MiniApp\Navigation\MiniAppSections;
use App\MiniApp\Security\MiniAppUser;
use App\MiniApp\UI\MiniAppFragments;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The admin home in the Mini App: the web dashboard's numbers (DashboardOverview, the same queries and the same
 * per-role sections: attention queue, today, services, panel health), each card opening the filtered Mini App list,
 * then the admin areas the user may open (MiniAppSections).
 */
#[IsGranted(MiniAppUser::ROLE)]
final class MiniAppAdminHomeController extends AbstractController
{
    public function __construct(
        private readonly DashboardOverview $overview,
        private readonly MiniAppSections $sections,
        private readonly MiniAppFragments $fragments,
    ) {
    }

    #[Route('/app/admin', name: 'miniapp_admin', defaults: ['_miniapp_section' => 'admin'], methods: ['GET'])]
    public function __invoke(Request $request, #[CurrentUser] MiniAppUser $user): Response
    {
        // MiniAppAdminGuard has checked that $user->admin is an active admin.
        $overview = null === $user->admin ? null : $this->overview->for($user->admin);

        return $this->fragments->render($request, 'miniapp/admin/home.html.twig', [
            'overview' => $overview,
            'areas' => $this->sections->children('admin', $user),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\MiniApp\UI;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * The page Telegram opens (menu button, web_app buttons). It holds no data: public/miniapp/app.js posts initData to
 * /app/auth and loads the sections with the session token. Outside Telegram (no initData) it only says to open the
 * app from the bot.
 */
final class MiniAppEntryController extends AbstractController
{
    public function __construct(
        private readonly LocaleSwitcher $localeSwitcher,
        #[Autowire('%kernel.project_dir%/public/miniapp')]
        private readonly string $assetDir,
    ) {
    }

    #[Route('/app', name: 'miniapp_entry', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $locale = MiniAppLocale::fromRequest($request);
        $this->localeSwitcher->setLocale($locale);
        $request->setLocale($locale);

        return MiniAppHeaders::apply($this->render('miniapp/entry.html.twig', [
            'lang' => $locale,
            'assetVersion' => $this->assetVersion(),
        ]));
    }

    /** Changes when app.js or app.css changes, so a WebView never keeps an old copy. */
    private function assetVersion(): string
    {
        $mtimes = '';
        foreach (['app.js', 'app.css'] as $file) {
            $mtimes .= (string) @filemtime($this->assetDir.'/'.$file);
        }

        return substr(hash('sha256', $mtimes), 0, 12);
    }
}

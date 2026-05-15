<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    // Route définie dans config/routes.yaml (pas de préfixe /{_locale})
    public function index(Request $request): RedirectResponse
    {
        $locale = $request->getPreferredLanguage(['fr', 'en']) ?? 'fr';

        return $this->redirectToRoute('app_home', ['_locale' => $locale]);
    }

    #[Route('/', name: 'app_home')]
    public function home(Request $request): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard', ['_locale' => $request->getLocale()]);
        }

        return $this->render('home/index.html.twig');
    }
}

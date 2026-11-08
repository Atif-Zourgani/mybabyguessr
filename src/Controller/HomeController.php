<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    // Route définie dans config/routes.yaml (pas de préfixe /{_locale})
    public function index(Request $request, #[Autowire('%kernel.enabled_locales%')] array $enabledLocales): RedirectResponse
    {
        // Langue du navigateur parmi toutes celles du site (fr, en, es, pt, de, it), français par défaut
        $locale = $request->getPreferredLanguage($enabledLocales) ?? 'fr';

        $response = $this->redirectToRoute('app_home', ['_locale' => $locale]);
        // La destination dépend de la langue du navigateur : un cache ne doit pas servir la même redirection à tous
        $response->setVary(['Accept-Language']);

        return $response;
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

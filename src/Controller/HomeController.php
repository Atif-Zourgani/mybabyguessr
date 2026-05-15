<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class HomeController extends AbstractController
{
    public function index(Request $request): RedirectResponse
    {
        $preferred = $request->getPreferredLanguage(['fr', 'en']) ?? 'fr';

        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard', ['_locale' => $preferred]);
        }

        return $this->redirectToRoute('app_login', ['_locale' => $preferred]);
    }
}

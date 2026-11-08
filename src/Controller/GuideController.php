<?php

namespace App\Controller;

use App\Guide\GuideCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class GuideController extends AbstractController
{
    #[Route('/guide/{slug}', name: 'app_guide', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function show(string $slug, Request $request, GuideCatalog $catalog): Response
    {
        $page = $catalog->find($request->getLocale(), $slug);
        if ($page === null) {
            throw $this->createNotFoundException();
        }

        return $this->render($page->template(), [
            'page'    => $page,
            'related' => array_values(array_filter($catalog->forLocale($page->locale), fn ($other) => $other->id !== $page->id)),
        ]);
    }
}

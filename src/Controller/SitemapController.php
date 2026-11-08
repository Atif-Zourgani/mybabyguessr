<?php

namespace App\Controller;

use App\Guide\GuideCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Sitemap généré : les pages d'accueil et tous les guides, avec leurs alternatives de langue et leur date de modification. */
class SitemapController extends AbstractController
{
    public function __construct(
        private readonly GuideCatalog $guides,
        #[Autowire('%kernel.enabled_locales%')] private readonly array $locales,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    public function index(): Response
    {
        $entries = [];

        $homeUrls = [];
        foreach ($this->locales as $locale) {
            $homeUrls[$locale] = $this->generateUrl('app_home', ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL);
        }
        foreach ($this->locales as $locale) {
            $entries[] = [
                'loc'        => $homeUrls[$locale],
                'lastmod'    => $this->homeLastModified($locale),
                'alternates' => $homeUrls,
            ];
        }

        foreach ($this->guides->all() as $page) {
            $alternates = [];
            foreach ($this->guides->alternates($page) as $locale => $translation) {
                $alternates[$locale] = $this->generateUrl('app_guide', ['_locale' => $locale, 'slug' => $translation->slug], UrlGeneratorInterface::ABSOLUTE_URL);
            }
            $entries[] = [
                'loc'        => $alternates[$page->locale],
                'lastmod'    => $page->modified->format('Y-m-d'),
                'alternates' => $alternates,
            ];
        }

        $response = $this->render('sitemap/sitemap.xml.twig', ['entries' => $entries]);
        $response->headers->set('Content-Type', 'application/xml; charset=UTF-8');
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }

    /** Date du dernier changement du contenu de l'accueil dans cette langue (gabarit ou textes traduits). */
    private function homeLastModified(string $locale): string
    {
        $times = array_filter([
            @filemtime($this->projectDir . '/templates/home/index.html.twig'),
            @filemtime($this->projectDir . "/translations/messages.$locale.yaml"),
        ]);

        return (new \DateTimeImmutable('@' . ($times ? max($times) : time())))->format('Y-m-d');
    }
}

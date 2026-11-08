<?php

namespace App\Twig;

use App\Guide\GuideCatalog;
use App\Guide\GuidePage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Adresses de la page courante dans chaque langue : balises hreflang et sélecteur de langue.
 * Les guides ont une adresse différente dans chaque langue, et n'existent pas dans toutes.
 */
class LanguageExtension extends AbstractExtension
{
    public function __construct(
        private readonly RequestStack $requests,
        private readonly UrlGeneratorInterface $urls,
        private readonly GuideCatalog $guides,
        #[Autowire('%kernel.enabled_locales%')] private readonly array $locales,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('language_alternates', $this->alternates(...)),
            new TwigFunction('guides_for_locale', $this->guidesForLocale(...)),
        ];
    }

    /**
     * @return list<array{locale: string, url: string, exact: bool, default: bool}>
     *         « exact » : la page existe dans cette langue ; sinon l'adresse est celle de l'accueil de cette langue
     *         (utile au sélecteur de langue, jamais publiée en hreflang). « default » : adresse x-default.
     */
    public function alternates(): array
    {
        $request = $this->requests->getCurrentRequest();
        $route   = $request?->attributes->get('_route');
        if (!is_string($route) || $route === '') {
            return [];
        }

        $params = $request->attributes->get('_route_params', []);
        $out    = [];

        if ($route === 'app_guide') {
            $page         = $this->guides->find($request->getLocale(), (string) ($params['slug'] ?? ''));
            $translations = $page ? $this->guides->alternates($page) : [];
            foreach ($this->locales as $locale) {
                $out[] = isset($translations[$locale])
                    ? ['locale' => $locale, 'url' => $this->guideUrl($translations[$locale]), 'exact' => true, 'default' => false]
                    : ['locale' => $locale, 'url' => $this->urls->generate('app_home', ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL), 'exact' => false, 'default' => false];
            }
        } else {
            foreach ($this->locales as $locale) {
                try {
                    $url = $this->urls->generate($route, ['_locale' => $locale] + $params, UrlGeneratorInterface::ABSOLUTE_URL);
                } catch (RoutingException) {
                    continue;
                }
                $out[] = ['locale' => $locale, 'url' => $url, 'exact' => true, 'default' => false];
            }
        }

        // x-default : le français s'il existe pour cette page, sinon la première langue disponible
        $exact = array_keys(array_filter($out, fn (array $a) => $a['exact']));
        $default = null;
        foreach ($exact as $i) {
            if ($out[$i]['locale'] === 'fr') {
                $default = $i;
            }
        }
        if ($default === null && $exact) {
            $default = $exact[0];
        }
        if ($default !== null) {
            $out[$default]['default'] = true;
        }

        return $out;
    }

    /** @return list<array{title: string, description: string, url: string}> */
    public function guidesForLocale(string $locale): array
    {
        return array_map(fn (GuidePage $p) => [
            'title'       => $p->title,
            'description' => $p->description,
            'url'         => $this->urls->generate('app_guide', ['_locale' => $p->locale, 'slug' => $p->slug]),
        ], $this->guides->forLocale($locale));
    }

    private function guideUrl(GuidePage $page): string
    {
        return $this->urls->generate('app_guide', ['_locale' => $page->locale, 'slug' => $page->slug], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}

<?php

namespace App\Guide;

/**
 * Catalogue des guides éditoriaux (pages de contenu indexables).
 *
 * Chaque guide a une adresse propre à chaque langue (mots-clés de la langue dans l'adresse) ; une langue sans
 * entrée n'a simplement pas ce guide, et les balises hreflang ainsi que le sitemap n'en parlent pas.
 * Pour ajouter une langue : une entrée ci-dessous et le gabarit « templates/guide/<id>.<langue>.html.twig ».
 */
final class GuideCatalog
{
    private const GUIDES = [
        'baby-shower-game' => [
            'published' => '2026-11-08',
            'modified'  => '2026-11-08',
            'locales'   => [
                'fr' => [
                    'slug'        => 'jeu-baby-shower-pronostics',
                    'title'       => 'Jeu de baby shower : le pronostic de naissance en ligne',
                    'description' => 'Animez votre baby shower avec un jeu de pronostics : prénom, date, poids, sexe. Gratuit, sans compte pour les invités, prêt en 2 minutes.',
                    'heading'     => 'Jeu de baby shower : faites parier vos invités sur l\'arrivée de bébé',
                ],
                'en' => [
                    'slug'        => 'baby-shower-guessing-game',
                    'title'       => 'Baby Shower Guessing Game: Run a Baby Prediction Online',
                    'description' => 'Add a baby guessing game to your baby shower: name, due date, weight and gender. Free, no account for guests, ready in 2 minutes.',
                    'heading'     => 'Baby shower guessing game: let your guests predict baby\'s arrival',
                ],
            ],
        ],
        'baby-prediction-game' => [
            'published' => '2026-11-08',
            'modified'  => '2026-11-08',
            'locales'   => [
                'fr' => [
                    'slug'        => 'pronostic-naissance',
                    'title'       => 'Pronostic naissance : questions, règles et idées de lots',
                    'description' => 'Comment organiser un pronostic de naissance : les bonnes questions, des règles pour départager et des idées de lots. Version papier ou en ligne.',
                    'heading'     => 'Pronostic naissance : comment l\'organiser (questions, règles, lots)',
                ],
                'en' => [
                    'slug'        => 'baby-prediction-game',
                    'title'       => 'Baby Prediction Game: Questions, Rules and Prize Ideas',
                    'description' => 'How to run a baby prediction game: the best questions, fair rules to pick winners and prize ideas. Paper or online, in minutes.',
                    'heading'     => 'Baby prediction game: questions, rules and prize ideas',
                ],
            ],
        ],
        'gender-reveal-game' => [
            'published' => '2026-11-08',
            'modified'  => '2026-11-08',
            'locales'   => [
                'fr' => [
                    'slug'        => 'jeu-gender-reveal',
                    'title'       => 'Gender reveal : faire deviner le sexe de bébé en ligne',
                    'description' => 'Idées pour un gender reveal participatif : vote des proches, révélation différée et partage à distance. Créez le jeu gratuitement en 2 minutes.',
                    'heading'     => 'Gender reveal : faites deviner le sexe du bébé à vos proches',
                ],
                'en' => [
                    'slug'        => 'gender-reveal-guessing-game',
                    'title'       => 'Gender Reveal Game: Let Friends Guess the Baby\'s Gender',
                    'description' => 'Ideas for an interactive gender reveal: let loved ones vote first, reveal together and include relatives who live far away. Free in minutes.',
                    'heading'     => 'Gender reveal game: let your loved ones guess the baby\'s gender',
                ],
            ],
        ],
    ];

    /** Un guide par langue et par adresse ; null si l'adresse n'existe pas dans cette langue. */
    public function find(string $locale, string $slug): ?GuidePage
    {
        foreach ($this->forLocale($locale) as $page) {
            if ($page->slug === $slug) {
                return $page;
            }
        }

        return null;
    }

    /** @return list<GuidePage> les guides disponibles dans la langue, dans l'ordre du catalogue */
    public function forLocale(string $locale): array
    {
        $pages = [];
        foreach (self::GUIDES as $id => $guide) {
            if (isset($guide['locales'][$locale])) {
                $pages[] = $this->build($id, $locale);
            }
        }

        return $pages;
    }

    /** @return array<string, GuidePage> traductions du même guide, indexées par langue (la page elle-même comprise) */
    public function alternates(GuidePage $page): array
    {
        $alternates = [];
        foreach (array_keys(self::GUIDES[$page->id]['locales']) as $locale) {
            $alternates[$locale] = $this->build($page->id, $locale);
        }

        return $alternates;
    }

    /** @return list<GuidePage> toutes les pages, toutes langues confondues */
    public function all(): array
    {
        $pages = [];
        foreach (self::GUIDES as $id => $guide) {
            foreach (array_keys($guide['locales']) as $locale) {
                $pages[] = $this->build($id, $locale);
            }
        }

        return $pages;
    }

    private function build(string $id, string $locale): GuidePage
    {
        $guide = self::GUIDES[$id];
        $meta  = $guide['locales'][$locale];

        return new GuidePage(
            $id,
            $locale,
            $meta['slug'],
            $meta['title'],
            $meta['description'],
            $meta['heading'],
            new \DateTimeImmutable($guide['published']),
            new \DateTimeImmutable($guide['modified']),
        );
    }
}

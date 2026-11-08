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
                'es' => [
                    'slug'        => 'juego-baby-shower-pronosticos',
                    'title'       => 'Juego de baby shower: pronóstico de nacimiento online',
                    'description' => 'Anima tu baby shower con un juego de pronósticos: nombre, fecha, peso y sexo. Gratis, sin cuenta para los invitados y listo en 2 minutos.',
                    'heading'     => 'Juego de baby shower: haz que tus invitados apuesten por la llegada del bebé',
                ],
                'pt' => [
                    'slug'        => 'jogo-cha-de-bebe-palpites',
                    'title'       => 'Jogo de chá de bebé: palpites para o nascimento online',
                    'description' => 'Anime o seu chá de bebé com um jogo de palpites: nome, data, peso e sexo. Grátis, sem conta para os convidados e pronto em 2 minutos.',
                    'heading'     => 'Jogo de chá de bebé: faça os seus convidados apostar na chegada do bebé',
                ],
                'de' => [
                    'slug'        => 'babyparty-tippspiel',
                    'title'       => 'Babyparty-Spiel: das Geburts-Tippspiel online',
                    'description' => 'Bring Schwung in deine Babyparty mit einem Tippspiel: Name, Datum, Gewicht und Geschlecht. Kostenlos, ohne Konto für Gäste, in 2 Minuten startklar.',
                    'heading'     => 'Babyparty-Spiel: lass deine Gäste auf die Ankunft des Babys wetten',
                ],
                'it' => [
                    'slug'        => 'gioco-baby-shower-pronostici',
                    'title'       => 'Gioco per baby shower: il pronostico della nascita online',
                    'description' => 'Anima il tuo baby shower con un gioco di pronostici: nome, data, peso e sesso. Gratis, senza account per gli ospiti e pronto in 2 minuti.',
                    'heading'     => 'Gioco per baby shower: fai scommettere gli ospiti sull\'arrivo del bebè',
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
                'es' => [
                    'slug'        => 'pronostico-nacimiento',
                    'title'       => 'Pronóstico de nacimiento: preguntas, reglas y premios',
                    'description' => 'Cómo organizar un pronóstico de nacimiento: las mejores preguntas, reglas para desempatar e ideas de premios. En papel o online.',
                    'heading'     => 'Pronóstico de nacimiento: cómo organizarlo (preguntas, reglas, premios)',
                ],
                'pt' => [
                    'slug'        => 'palpites-nascimento-bebe',
                    'title'       => 'Palpites de nascimento: perguntas, regras e prémios',
                    'description' => 'Como organizar palpites para o nascimento do bebé: as melhores perguntas, regras para desempatar e ideias de prémios. Em papel ou online.',
                    'heading'     => 'Palpites para o nascimento do bebé: como organizar (perguntas, regras, prémios)',
                ],
                'de' => [
                    'slug'        => 'geburt-tippspiel',
                    'title'       => 'Geburts-Tippspiel: Fragen, Regeln und Preisideen',
                    'description' => 'So organisierst du ein Geburts-Tippspiel: die besten Fragen, faire Regeln für den Gleichstand und Preisideen. Auf Papier oder online.',
                    'heading'     => 'Geburts-Tippspiel: so organisierst du es (Fragen, Regeln, Preise)',
                ],
                'it' => [
                    'slug'        => 'pronostico-nascita',
                    'title'       => 'Pronostico della nascita: domande, regole e idee per i premi',
                    'description' => 'Come organizzare un pronostico della nascita: le domande giuste, regole per i pari merito e idee per i premi. Su carta o online.',
                    'heading'     => 'Pronostico della nascita: come organizzarlo (domande, regole, premi)',
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
                'es' => [
                    'slug'        => 'juego-revelacion-de-genero',
                    'title'       => 'Revelación de género: adivinar el sexo del bebé online',
                    'description' => 'Ideas para una revelación de género participativa: votación de los seres queridos, revelación a la vez y también a distancia. Gratis en 2 minutos.',
                    'heading'     => 'Revelación de género: haz que tus seres queridos adivinen el sexo del bebé',
                ],
                'pt' => [
                    'slug'        => 'jogo-revelacao-de-sexo',
                    'title'       => 'Revelação de sexo: adivinhar o sexo do bebé online',
                    'description' => 'Ideias para uma revelação de sexo participativa: votação dos mais próximos, revelação em simultâneo e também à distância. Grátis em 2 minutos.',
                    'heading'     => 'Revelação de sexo: faça os seus próximos adivinhar o sexo do bebé',
                ],
                'de' => [
                    'slug'        => 'gender-reveal-spiel',
                    'title'       => 'Gender-Reveal-Spiel: das Geschlecht des Babys online erraten',
                    'description' => 'Ideen für eine Gender-Reveal-Party zum Mitmachen: Abstimmung der Liebsten, gemeinsame Enthüllung und auch aus der Ferne. In 2 Minuten kostenlos.',
                    'heading'     => 'Gender-Reveal-Spiel: lass deine Liebsten das Geschlecht des Babys erraten',
                ],
                'it' => [
                    'slug'        => 'gioco-gender-reveal',
                    'title'       => 'Gender reveal: far indovinare il sesso del bebè online',
                    'description' => 'Idee per un gender reveal partecipativo: voto dei propri cari, rivelazione insieme e anche a distanza. Gratis in 2 minuti.',
                    'heading'     => 'Gender reveal: fai indovinare il sesso del bebè ai tuoi cari',
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

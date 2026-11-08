<?php

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Une seule adresse pour le site : https://mybabyguessr.com (sans www, en https).
 *
 * Sans cela, les 4 variantes (http/https, avec/sans www) répondent toutes 200 avec leur propre balise canonique
 * et les moteurs de recherche indexent des doublons. Une redirection permanente (301) vers l'adresse officielle
 * règle le problème à la source, et les balises canoniques, hreflang et og:url (construites à partir de la requête)
 * deviennent correctes d'elles-mêmes.
 *
 * Seules les requêtes GET et HEAD sont redirigées (jamais un envoi de formulaire).
 */
class CanonicalHostSubscriber implements EventSubscriberInterface
{
    private readonly string $canonicalUrl;

    public function __construct(#[Autowire('%app.canonical_url%')] string $canonicalUrl)
    {
        $this->canonicalUrl = rtrim($canonicalUrl, '/');
    }

    public static function getSubscribedEvents(): array
    {
        // Avant le routeur et le pare-feu : inutile de traiter une requête qui sera redirigée
        return [KernelEvents::REQUEST => ['onKernelRequest', 128]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if ($this->canonicalUrl === '' || !$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return;
        }

        if ($this->isCanonical($request)) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->canonicalUrl . $request->getRequestUri(), 301));
    }

    private function isCanonical(Request $request): bool
    {
        // Derrière un relais de chiffrement non déclaré comme fiable, Symfony peut voir « http » alors que
        // le visiteur est en https : on se fie aussi à l'en-tête du relais pour ne jamais créer de boucle.
        $viaHttps = $request->isSecure() || strtolower((string) $request->headers->get('X-Forwarded-Proto')) === 'https';
        $current  = ($viaHttps ? 'https' : 'http') . '://' . $request->getHttpHost();

        // getHttpHost() contient déjà le port s'il n'est pas celui par défaut ; on compare sans lui pour https/http usuels
        return strtolower($current) === strtolower($this->canonicalUrl);
    }
}

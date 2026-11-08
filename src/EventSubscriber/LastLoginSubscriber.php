<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Enregistre la dernière connexion d'un organisateur (modération, comptes inactifs de l'article 4.4 des CGU).
 *
 * Conçu pour ne jamais gêner une connexion : écriture ciblée d'une seule colonne (sans flush de l'ORM,
 * donc sans effet de bord sur d'autres modifications en attente), au plus une fois toutes les 10 minutes
 * par compte, et toute erreur est journalisée puis ignorée.
 */
class LastLoginSubscriber implements EventSubscriberInterface
{
    private const MIN_INTERVAL = '-10 minutes';

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [LoginSuccessEvent::class => 'onLoginSuccess'];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User || $user->getId() === null) {
            return;
        }

        $now  = new \DateTimeImmutable();
        $last = $user->getLastLoginAt();
        if ($last !== null && $last > $now->modify(self::MIN_INTERVAL)) {
            return;
        }

        try {
            $this->connection->update('user', ['last_login_at' => $now->format('Y-m-d H:i:s')], ['id' => $user->getId()]);
        } catch (\Throwable $e) {
            $this->logger->warning('Last login date could not be saved', ['user' => $user->getId(), 'error' => $e->getMessage()]);
        }
    }
}

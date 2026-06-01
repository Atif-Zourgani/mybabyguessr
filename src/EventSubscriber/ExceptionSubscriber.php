<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class ExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly TokenStorageInterface $tokenStorage,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onKernelException', 0]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $exception  = $event->getThrowable();
        $statusCode = $exception instanceof HttpExceptionInterface
            ? $exception->getStatusCode()
            : 500;

        // On ne logue que les 5xx et les 403 (accès refusé non autorisé)
        // Les 404 sont trop bruyants (bots, scans)
        if ($statusCode < 500 && $statusCode !== 403) {
            return;
        }

        $request = $event->getRequest();
        $url     = mb_substr($request->getUri(), 0, 500);
        $message = mb_substr($exception->getMessage(), 0, 500) ?: null;

        $userId    = null;
        $userEmail = null;
        $token     = $this->tokenStorage->getToken();
        if ($token !== null) {
            $user = $token->getUser();
            if ($user instanceof User) {
                $userId    = $user->getId();
                $userEmail = $user->getEmail();
            }
        }

        try {
            $this->connection->insert('app_error_log', [
                'status_code' => $statusCode,
                'url'         => $url,
                'message'     => $message,
                'user_id'     => $userId,
                'user_email'  => $userEmail,
                'created_at'  => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            // Ne jamais laisser le logging casser l'app (ex: DB injoignable)
        }
    }
}

<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\EmailVerificationSender;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

class VerifyEmailController extends AbstractController
{
    public function __construct(
        private VerifyEmailHelperInterface $verifyEmailHelper,
        private EntityManagerInterface $entityManager,
    ) {}

    #[Route('/verify/email', name: 'app_verify_email')]
    public function verifyUserEmail(Request $request, UserRepository $userRepository): Response
    {
        $id   = $request->query->getInt('id');
        $user = $id > 0 ? $userRepository->find($id) : null;
        $locale = $request->getLocale();
        $isCurrentUser = $user instanceof User && $user->getId() === $this->getUser()?->getId();

        // Lien inconnu : même message que pour un lien expiré, sans révéler si le compte existe
        if (!$user instanceof User) {
            $this->addFlash('error', 'flash.verify_link_invalid');

            return $this->redirectToRoute('app_login', ['_locale' => $locale]);
        }

        // Lien déjà utilisé : rien à refaire
        if ($user->isVerified()) {
            $this->addFlash('success', 'flash.email_verified');

            return $this->redirectToRoute($isCurrentUser ? 'app_dashboard' : 'app_login', ['_locale' => $locale]);
        }

        try {
            $this->verifyEmailHelper->validateEmailConfirmationFromRequest($request, (string) $user->getId(), (string) $user->getEmail());
        } catch (VerifyEmailExceptionInterface) {
            $this->addFlash('error', 'flash.verify_link_invalid');

            // Connecté : on propose directement le renvoi. Sinon : se connecter donne accès au renvoi.
            return $this->redirectToRoute($isCurrentUser ? 'app_verify_email_pending' : 'app_login', ['_locale' => $locale]);
        }

        $user->setIsVerified(true);
        $this->entityManager->flush();

        $this->addFlash('success', 'flash.email_verified');

        return $this->redirectToRoute($isCurrentUser ? 'app_dashboard' : 'app_login', ['_locale' => $locale]);
    }

    /** Page affichée à un compte connecté dont l'adresse n'est pas encore confirmée. */
    #[IsGranted('ROLE_USER')]
    #[Route('/verify/pending', name: 'app_verify_email_pending', methods: ['GET'])]
    public function pending(Request $request): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        if ($user->isVerified()) {
            return $this->redirectToRoute('app_dashboard', ['_locale' => $request->getLocale()]);
        }

        return $this->render('registration/verify_pending.html.twig', ['email' => $user->getEmail()]);
    }

    /** Renvoie l'email de confirmation (3 fois par heure pour un compte donné depuis une même adresse IP). */
    #[IsGranted('ROLE_USER')]
    #[Route('/verify/resend', name: 'app_verify_email_resend', methods: ['POST'])]
    public function resend(Request $request, EmailVerificationSender $sender, RateLimiterFactory $verifyResendLimiter, LoggerInterface $logger): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);
        $locale = $request->getLocale();

        if (!$this->isCsrfTokenValid('verify_resend', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        if ($user->isVerified()) {
            return $this->redirectToRoute('app_dashboard', ['_locale' => $locale]);
        }

        if (!$verifyResendLimiter->create('user_' . $user->getId() . '_' . $request->getClientIp())->consume()->isAccepted()) {
            $this->addFlash('error', 'flash.verify_resend_limited');

            return $this->redirectToRoute('app_verify_email_pending', ['_locale' => $locale]);
        }

        try {
            $sender->send($user, $locale);
            $this->addFlash('success', 'flash.verify_resent');
        } catch (TransportExceptionInterface $e) {
            $logger->error('Verification email could not be resent', ['user' => $user->getId(), 'error' => $e->getMessage()]);
            $this->addFlash('error', 'flash.verify_resend_failed');
        }

        return $this->redirectToRoute('app_verify_email_pending', ['_locale' => $locale]);
    }
}

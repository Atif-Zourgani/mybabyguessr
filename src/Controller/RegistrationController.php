<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Service\EmailVerificationSender;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $userPasswordHasher,
        EntityManagerInterface $entityManager,
        RateLimiterFactory $registerLimiter,
        EmailVerificationSender $verificationSender,
        LoggerInterface $logger,
    ): Response {
        // On ne compte que les soumissions : afficher la page ne doit pas consommer le quota
        if ($request->isMethod('POST') && !$registerLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException();
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword($userPasswordHasher->hashPassword($user, $form->get('plainPassword')->getData()));

            $entityManager->persist($user);
            $entityManager->flush();

            // Si l'envoi échoue, le compte existe : l'utilisateur pourra demander un nouvel email après connexion
            try {
                $verificationSender->send($user, $request->getLocale());
            } catch (TransportExceptionInterface $e) {
                $logger->error('Verification email could not be sent', ['user' => $user->getId(), 'error' => $e->getMessage()]);
            }

            $request->getSession()->set('registration_email', $user->getEmail());

            return $this->redirectToRoute('app_register_check_email', ['_locale' => $request->getLocale()]);
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form,
        ], new Response('', $form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/check-email', name: 'app_register_check_email')]
    public function checkEmail(Request $request): Response
    {
        $email = $request->getSession()->get('registration_email');
        $request->getSession()->remove('registration_email');

        return $this->render('registration/check_email.html.twig', [
            'email' => $email,
        ]);
    }

}

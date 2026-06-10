<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class RegistrationController extends AbstractController
{
    public function __construct(
        private VerifyEmailHelperInterface $verifyEmailHelper,
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
    ) {}

    #[Route('/register', name: 'app_register')]
    public function register(Request $request, UserPasswordHasherInterface $userPasswordHasher, EntityManagerInterface $entityManager, RateLimiterFactory $registerLimiter): Response
    {
        if (!$registerLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException();
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword($userPasswordHasher->hashPassword($user, $form->get('plainPassword')->getData()));

            $entityManager->persist($user);
            $entityManager->flush();

            $signatureComponents = $this->verifyEmailHelper->generateSignature(
                'app_verify_email',
                $user->getId(),
                $user->getEmail(),
                ['id' => $user->getId()]
            );

            $email = (new TemplatedEmail())
                ->from('noreply@mybabyguessr.com')
                ->to($user->getEmail())
                ->subject($this->translator->trans('email.verify_subject', locale: $request->getLocale()))
                ->htmlTemplate('emails/confirmation.html.twig')
                ->context([
                    'user' => $user,
                    'signedUrl' => $signatureComponents->getSignedUrl(),
                    'expiresAt' => $signatureComponents->getExpiresAt(),
                    'locale' => $request->getLocale(),
                ]);

            $this->mailer->send($email);

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

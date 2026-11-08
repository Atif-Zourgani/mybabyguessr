<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use App\Repository\UserRepository;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
class ProfileController extends AbstractController
{
    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
    ) {}

    #[Route('/profile', name: 'app_profile', methods: ['GET'])]
    public function edit(): Response
    {
        return $this->render('profile/edit.html.twig');
    }

    #[Route('/profile/info', name: 'app_profile_info', methods: ['POST'])]
    public function updateInfo(Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('profile_info', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $user      = $this->getUser();
        $firstName = trim($request->request->get('firstName', ''));
        $lastName  = trim($request->request->get('lastName', ''));
        if (mb_strlen($firstName) < 2 || mb_strlen($firstName) > 100 || mb_strlen($lastName) < 2 || mb_strlen($lastName) > 100) {
            $this->addFlash('error', 'profile.info_invalid');
            return $this->redirectToRoute('app_profile', ['_locale' => $request->getLocale()]);
        }
        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $em->flush();
        $this->addFlash('success', 'profile.info_saved');
        return $this->redirectToRoute('app_profile', ['_locale' => $request->getLocale()]);
    }

    #[Route('/profile/email', name: 'app_profile_email', methods: ['POST'])]
    public function requestEmailChange(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $hasher, UserRepository $userRepository): Response
    {
        if (!$this->isCsrfTokenValid('profile_email', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $user     = $this->getUser();
        $password = $request->request->get('current_password', '');
        $newEmail = mb_strtolower(trim($request->request->get('new_email', '')));
        if (!$hasher->isPasswordValid($user, $password)) {
            $this->addFlash('error', 'profile.email_wrong_password');
            return $this->redirectToRoute('app_profile', ['_locale' => $request->getLocale()]);
        }
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL) || mb_strlen($newEmail) > 180) {
            $this->addFlash('error', 'profile.email_invalid');
            return $this->redirectToRoute('app_profile', ['_locale' => $request->getLocale()]);
        }
        if ($newEmail === $user->getEmail()) {
            $this->addFlash('error', 'profile.email_same');
            return $this->redirectToRoute('app_profile', ['_locale' => $request->getLocale()]);
        }
        if ($userRepository->findOneBy(['email' => $newEmail]) !== null) {
            $this->addFlash('error', 'profile.email_taken');
            return $this->redirectToRoute('app_profile', ['_locale' => $request->getLocale()]);
        }
        $user->setPendingEmail($newEmail);
        $em->flush();
        $token     = $this->generateEmailChangeToken($user->getId(), $newEmail);
        $verifyUrl = $this->generateUrl('app_profile_verify_email', ['_locale' => $request->getLocale(), 'token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);
        $mail = (new TemplatedEmail())
            ->from(new Address('noreply@mybabyguessr.com', 'MyBabyGuessr'))
            ->to($newEmail)
            ->subject($this->translator->trans('email.change_subject', locale: $request->getLocale()))
            ->htmlTemplate('emails/email_change.html.twig')
            ->context(['user' => $user, 'verifyUrl' => $verifyUrl, 'newEmail' => $newEmail, 'locale' => $request->getLocale()]);
        $this->mailer->send($mail);
        $this->addFlash('info', 'profile.email_pending');
        return $this->redirectToRoute('app_profile', ['_locale' => $request->getLocale()]);
    }

    #[Route('/profile/verify-email', name: 'app_profile_verify_email', methods: ['GET'])]
    public function verifyNewEmail(Request $request, EntityManagerInterface $em): Response
    {
        $user    = $this->getUser();
        $payload = $this->parseEmailChangeToken($request->query->get('token', ''));
        if (!$payload || $payload['uid'] !== $user->getId() || $payload['email'] !== $user->getPendingEmail()) {
            $this->addFlash('error', 'profile.email_token_invalid');
            return $this->redirectToRoute('app_profile', ['_locale' => $request->getLocale()]);
        }
        $user->setEmail($payload['email']);
        $user->setPendingEmail(null);
        $em->flush();
        $this->addFlash('success', 'profile.email_saved');
        return $this->redirectToRoute('app_profile', ['_locale' => $request->getLocale()]);
    }

    #[Route('/profile/cancel-email', name: 'app_profile_cancel_email', methods: ['POST'])]
    public function cancelEmailChange(Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('profile_cancel_email', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $this->getUser()->setPendingEmail(null);
        $em->flush();
        $this->addFlash('success', 'profile.email_cancelled');
        return $this->redirectToRoute('app_profile', ['_locale' => $request->getLocale()]);
    }

    #[Route('/profile/password', name: 'app_profile_password', methods: ['POST'])]
    public function updatePassword(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $hasher): Response
    {
        if (!$this->isCsrfTokenValid('profile_password', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $user       = $this->getUser();
        $currentPwd = $request->request->get('current_password', '');
        $newPwd     = $request->request->get('new_password', '');
        $confirmPwd = $request->request->get('confirm_password', '');
        if (!$hasher->isPasswordValid($user, $currentPwd)) {
            $this->addFlash('error', 'profile.password_wrong_current');
            return $this->redirectToRoute('app_profile', ['_locale' => $request->getLocale()]);
        }
        if ($newPwd !== $confirmPwd) {
            $this->addFlash('error', 'profile.password_mismatch');
            return $this->redirectToRoute('app_profile', ['_locale' => $request->getLocale()]);
        }
        if (mb_strlen($newPwd) < 8 || mb_strlen($newPwd) > 128 || !preg_match('/[A-Z]/', $newPwd) || !preg_match('/[\W_]/', $newPwd)) {
            $this->addFlash('error', 'profile.password_invalid');
            return $this->redirectToRoute('app_profile', ['_locale' => $request->getLocale()]);
        }
        $user->setPassword($hasher->hashPassword($user, $newPwd));
        $em->flush();
        $this->addFlash('success', 'profile.password_saved');
        return $this->redirectToRoute('app_profile', ['_locale' => $request->getLocale()]);
    }

    #[Route('/profile/delete', name: 'app_profile_delete', methods: ['POST'])]
    public function delete(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $hasher, TokenStorageInterface $tokenStorage): Response
    {
        if (!$this->isCsrfTokenValid('profile_delete', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $user = $this->getUser();
        if (!$hasher->isPasswordValid($user, $request->request->get('current_password', ''))) {
            $this->addFlash('error', 'profile.delete_wrong_password');
            return $this->redirectToRoute('app_profile', ['_locale' => $request->getLocale()]);
        }
        $uploadsDir = $this->getParameter('kernel.project_dir') . '/public/uploads/games/';
        foreach ($user->getGames() as $game) {
            if ($game->getImage()) {
                $file = $uploadsDir . basename($game->getImage());
                if (file_exists($file)) { @unlink($file); }
            }
        }
        $tokenStorage->setToken(null);
        $request->getSession()->invalidate();
        $em->remove($user);
        $em->flush();
        return $this->redirectToRoute('app_home', ['_locale' => $request->getLocale()]);
    }

    private function generateEmailChangeToken(int $userId, string $newEmail): string
    {
        $payload = base64_encode(json_encode(['uid' => $userId, 'email' => $newEmail, 'exp' => time() + 3600]));
        $sig     = hash_hmac('sha256', $payload, $this->getParameter('kernel.secret'));
        return $payload . '.' . $sig;
    }

    private function parseEmailChangeToken(string $token): ?array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) { return null; }
        [$payload, $sig] = $parts;
        if (!hash_equals(hash_hmac('sha256', $payload, $this->getParameter('kernel.secret')), $sig)) { return null; }
        $data = json_decode(base64_decode($payload), true);
        if (!$data || $data['exp'] < time()) { return null; }
        return $data;
    }
}
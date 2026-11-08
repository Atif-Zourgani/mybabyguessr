<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

/**
 * Envoie l'email de confirmation d'adresse (inscription et renvoi à la demande).
 */
class EmailVerificationSender
{
    public function __construct(
        private readonly VerifyEmailHelperInterface $verifyEmailHelper,
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
        #[Autowire('%app.mail_from%')] private readonly string $mailFrom,
    ) {
    }

    /**
     * @throws \Symfony\Component\Mailer\Exception\TransportExceptionInterface si l'envoi échoue
     */
    public function send(User $user, string $locale): void
    {
        // Le lien garde la langue de l'utilisateur : toute la suite du parcours s'affiche dans cette langue
        $signature = $this->verifyEmailHelper->generateSignature(
            'app_verify_email',
            (string) $user->getId(),
            (string) $user->getEmail(),
            ['id' => $user->getId(), '_locale' => $locale],
        );

        $email = (new TemplatedEmail())
            ->from(new Address($this->mailFrom, 'MyBabyGuessr'))
            ->to((string) $user->getEmail())
            ->subject($this->translator->trans('email.verify_subject', locale: $locale))
            ->htmlTemplate('emails/confirmation.html.twig')
            ->context([
                'user'      => $user,
                'signedUrl' => $signature->getSignedUrl(),
                'expiresAt' => $signature->getExpiresAt(),
                'locale'    => $locale,
            ]);

        $this->mailer->send($email);
    }
}

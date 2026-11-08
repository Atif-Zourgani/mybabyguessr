<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Tests\Support\DatabaseWebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

class RegistrationFlowTest extends DatabaseWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Les limiteurs conservent leur état entre deux exécutions : une adresse IP aléatoire par test évite
        // qu'un test dépende des précédents (par exemple le renvoi d'email, limité à 3 par heure).
        $this->client->setServerParameter('REMOTE_ADDR', sprintf('10.%d.%d.%d', random_int(1, 254), random_int(1, 254), random_int(1, 254)));
    }

    private function reloadUser(string $email): User
    {
        $this->em->clear();

        return $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    private function signedLink(User $user, string $locale = 'fr'): string
    {
        $helper = static::getContainer()->get(VerifyEmailHelperInterface::class);

        return $helper->generateSignature('app_verify_email', (string) $user->getId(), (string) $user->getEmail(), ['id' => $user->getId(), '_locale' => $locale])->getSignedUrl();
    }

    private function resendToken(string $locale = 'fr'): string
    {
        $crawler = $this->client->request('GET', "/$locale/verify/pending");
        $this->assertResponseIsSuccessful();

        return $crawler->filter('form[action$="/verify/resend"] input[name="_token"]')->attr('value');
    }

    // ── Inscription ──────────────────────────────────────────────────────

    public function testRegisteringCreatesAnUnverifiedAccountAndSendsTheConfirmationEmail(): void
    {
        $this->postForm('/fr/register', 'registration_form', [
            'firstName'     => 'Camille',
            'lastName'      => 'Durand',
            'email'         => 'camille@example.test',
            'plainPassword' => 'Motdepasse-123',
            'agreeTerms'    => '1',
        ]);

        $this->assertResponseRedirects('/fr/check-email');
        $user = $this->reloadUser('camille@example.test');
        $this->assertFalse($user->isVerified());
        $this->assertNotNull($user->getCreatedAt());

        $this->assertEmailCount(1);
        $email = $this->getMailerMessage();
        $this->assertEmailAddressContains($email, 'to', 'camille@example.test');
        $this->assertEmailAddressContains($email, 'from', 'noreply@mybabyguessr.com');
        $this->assertStringContainsString('/fr/verify/email', $email->getHtmlBody());
    }

    // ── Compte non vérifié ───────────────────────────────────────────────

    public function testAnUnverifiedAccountLandsOnItsPendingPageNotOnPasswordReset(): void
    {
        $this->loginAs($this->createUser('pending@example.test', [], false));

        $this->client->request('GET', '/fr/dashboard');
        $this->assertResponseRedirects('/fr/verify/pending');

        $crawler = $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'pending@example.test');
        $this->assertCount(1, $crawler->filter('form[action$="/verify/resend"]'));
    }

    public function testAVerifiedAccountIsSentAwayFromThePendingPage(): void
    {
        $this->loginAs($this->createUser('ok@example.test'));

        $this->client->request('GET', '/fr/verify/pending');

        $this->assertResponseRedirects('/fr/dashboard');
    }

    public function testThePendingPageNeedsALogin(): void
    {
        $this->client->request('GET', '/fr/verify/pending');

        $this->assertResponseRedirects('/fr/login');
    }

    // ── Renvoi de l'email ────────────────────────────────────────────────

    public function testResendSendsANewEmailInTheUsersLanguage(): void
    {
        $this->loginAs($this->createUser('pending@example.test', [], false));
        $token = $this->resendToken('de');

        $this->client->request('POST', '/de/verify/resend', ['_token' => $token]);

        $this->assertResponseRedirects('/de/verify/pending');
        $this->assertEmailCount(1);
        $email = $this->getMailerMessage();
        $this->assertSame('Bestätige deine E-Mail-Adresse — MyBabyGuessr', $email->getSubject());
        $this->assertStringContainsString('/de/verify/email', $email->getHtmlBody());
    }

    public function testResendNeedsAValidCsrfToken(): void
    {
        $this->loginAs($this->createUser('pending@example.test', [], false));

        $this->client->request('POST', '/fr/verify/resend', ['_token' => 'forged']);

        $this->assertResponseStatusCodeSame(403);
        $this->assertEmailCount(0);
    }

    public function testResendIsLimitedToThreePerHour(): void
    {
        $this->loginAs($this->createUser('pending@example.test', [], false));
        $token = $this->resendToken();

        for ($i = 0; $i < 3; $i++) {
            $this->client->request('POST', '/fr/verify/resend', ['_token' => $token]);
            $this->assertEmailCount(1);
        }

        $this->client->request('POST', '/fr/verify/resend', ['_token' => $token]);
        $this->assertResponseRedirects('/fr/verify/pending');
        $this->assertEmailCount(0);
        $crawler = $this->client->followRedirect();
        $this->assertSelectorExists('.flash-error');
    }

    // ── Lien de confirmation ─────────────────────────────────────────────

    public function testAValidLinkConfirmsTheAccount(): void
    {
        $user = $this->createUser('pending@example.test', [], false);

        $this->client->request('GET', $this->signedLink($user));

        $this->assertResponseRedirects('/fr/login');
        $this->assertTrue($this->reloadUser('pending@example.test')->isVerified());
    }

    public function testAValidLinkTakesALoggedInUserToTheDashboard(): void
    {
        $user = $this->createUser('pending@example.test', [], false);
        $url  = $this->signedLink($user);
        $this->loginAs($user);

        $this->client->request('GET', $url);

        $this->assertResponseRedirects('/fr/dashboard');
        $this->assertTrue($this->reloadUser('pending@example.test')->isVerified());
    }

    public function testATamperedLinkSendsAnonymousVisitorsToTheLoginWithAnExplanation(): void
    {
        $user = $this->createUser('pending@example.test', [], false);
        $url  = preg_replace('/signature=[^&]+/', 'signature=abc', $this->signedLink($user));

        $this->client->request('GET', $url);

        $this->assertResponseRedirects('/fr/login');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-error', 'plus valide');
        $this->assertFalse($this->reloadUser('pending@example.test')->isVerified());
    }

    public function testATamperedLinkSendsALoggedInUserToTheResendPage(): void
    {
        $user = $this->createUser('pending@example.test', [], false);
        $url  = preg_replace('/signature=[^&]+/', 'signature=abc', $this->signedLink($user));
        $this->loginAs($user);

        $this->client->request('GET', $url);

        $this->assertResponseRedirects('/fr/verify/pending');
    }

    public function testAnUnknownAccountInTheLinkGivesTheSameMessage(): void
    {
        $this->client->request('GET', '/fr/verify/email?id=99999&expires=1&signature=x&token=y');

        $this->assertResponseRedirects('/fr/login');
    }

    public function testAnAlreadyUsedLinkIsHarmless(): void
    {
        $user = $this->createUser('ok@example.test', [], true);

        $this->client->request('GET', $this->signedLink($user));

        $this->assertResponseRedirects('/fr/login');
        $this->assertTrue($this->reloadUser('ok@example.test')->isVerified());
    }

    // ── Réinitialisation du mot de passe ─────────────────────────────────

    public function testResettingThePasswordAlsoConfirmsTheAccount(): void
    {
        $user  = $this->createUser('stuck@example.test', [], false);
        $token = static::getContainer()->get(ResetPasswordHelperInterface::class)->generateResetToken($user)->getToken();

        $this->client->request('GET', '/fr/reset-password/reset/' . $token);
        $this->assertResponseRedirects('/fr/reset-password/reset');
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();

        $this->postForm('/fr/reset-password/reset', 'change_password_form', [
            'plainPassword' => ['first' => 'Nouveau-Motdepasse-1', 'second' => 'Nouveau-Motdepasse-1'],
        ]);

        $this->assertResponseRedirects('/fr/login');
        $this->assertTrue($this->reloadUser('stuck@example.test')->isVerified());
    }

    public function testResetEmailSubjectFollowsTheLanguage(): void
    {
        $this->createUser('someone@example.test');

        $this->postForm('/it/reset-password', 'reset_password_request_form', ['email' => 'someone@example.test']);

        $this->assertResponseRedirects('/it/reset-password/check-email');
        $this->assertEmailCount(1);
        $this->assertSame('Reimposta la tua password — MyBabyGuessr', $this->getMailerMessage()->getSubject());
        $this->assertEmailAddressContains($this->getMailerMessage(), 'from', 'noreply@mybabyguessr.com');
    }

    // ── Langue automatique ───────────────────────────────────────────────

    public function testHomepageRedirectsToTheBrowserLanguage(): void
    {
        $cases = [
            'de-DE,de;q=0.9,en;q=0.5' => '/de/',
            'es-ES,es;q=0.9'          => '/es/',
            'pt-BR,pt;q=0.9'          => '/pt/',
            'it'                      => '/it/',
            'en-GB,en;q=0.8'          => '/en/',
            'ja-JP,ja;q=0.9'          => '/fr/',   // langue non proposée : français
            ''                        => '/fr/',   // aucun en-tête : français
        ];

        foreach ($cases as $header => $expected) {
            $this->client->setServerParameter('HTTP_ACCEPT_LANGUAGE', $header);
            $this->client->request('GET', '/');
            $this->assertResponseRedirects($expected, null, "Accept-Language: \"$header\"");
        }
    }
}

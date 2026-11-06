<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class RateLimitTest extends WebTestCase
{
    public function testRegisterPageViewsDoNotConsumeTheQuota(): void
    {
        $client = $this->clientWithFreshIp();

        for ($i = 0; $i < 8; $i++) {
            $client->request('GET', '/fr/register');
            $this->assertResponseIsSuccessful();
        }
    }

    public function testRegisterSubmissionsAreLimitedToFivePerHour(): void
    {
        $client = $this->clientWithFreshIp();

        for ($i = 0; $i < 5; $i++) {
            $client->request('POST', '/fr/register', ['registration_form' => ['firstName' => '']]);
            $this->assertResponseStatusCodeSame(422);
        }

        $client->request('POST', '/fr/register', ['registration_form' => ['firstName' => '']]);
        $this->assertResponseStatusCodeSame(429);

        // La simple consultation de la page reste possible
        $client->request('GET', '/fr/register');
        $this->assertResponseIsSuccessful();
    }

    public function testResetPasswordPageViewsDoNotConsumeTheQuota(): void
    {
        $client = $this->clientWithFreshIp();

        for ($i = 0; $i < 6; $i++) {
            $client->request('GET', '/fr/reset-password');
            $this->assertResponseIsSuccessful();
        }
    }

    public function testResetPasswordSubmissionsAreLimitedToThreePerHour(): void
    {
        $client = $this->clientWithFreshIp();

        for ($i = 0; $i < 3; $i++) {
            $client->request('POST', '/fr/reset-password', ['reset_password_request_form' => ['email' => '']]);
            $this->assertResponseStatusCodeSame(422);
        }

        $client->request('POST', '/fr/reset-password', ['reset_password_request_form' => ['email' => '']]);
        $this->assertResponseStatusCodeSame(429);
    }

    /**
     * Les formulaires Symfony ne se considèrent soumis que si des champs arrivent sous leur nom
     * (d'où les tableaux ci-dessus) ; sans jeton CSRF ils sont invalides, donc en 422.
     *
     * Le limiteur est indexé par IP et son état survit entre deux exécutions :
     * une IP aléatoire par test évite toute interférence.
     */
    private function clientWithFreshIp(): KernelBrowser
    {
        $ip = sprintf('10.%d.%d.%d', random_int(1, 254), random_int(1, 254), random_int(1, 254));

        return static::createClient([], ['REMOTE_ADDR' => $ip]);
    }
}

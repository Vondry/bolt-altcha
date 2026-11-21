<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Tests\E2E;

use Symfony\Component\HttpFoundation\Response;
use Tomvondracek\Altcha\Security\LoginAltchaSubscriber;
use Tomvondracek\Altcha\Tests\E2E\App\TestUserRepository;

/**
 * Bolt's admin login (`bolt.login`), through Symfony's firewall and Bolt's real
 * LoginFormAuthenticator, with CSRF and login throttling on.
 */
final class BoltLoginE2ETest extends E2ETestCase
{
    private const CONFIG = [
        'profiles' => [
            'login' => [],
        ],
        'bolt' => [
            'login' => true,
        ],
    ];

    public function testTheLoginPageShowsTheWidget(): void
    {
        $this->bootSite(self::CONFIG);

        $html = (string) $this->request('GET', '/bolt/login')->getContent();

        self::assertStringContainsString('<altcha-widget', $html);
        self::assertStringContainsString('challenge="/altcha/challenge/login"', $html);
        self::assertStringContainsString('name="altcha"', $html);
    }

    public function testALoginWithoutSolutionIsRejectedBeforeTheUserIsLookedUp(): void
    {
        $this->bootSite(self::CONFIG);

        $response = $this->logIn(TestUserRepository::PASSWORD, null);

        $this->assertLoginFailedWith(LoginAltchaSubscriber::MESSAGE_KEY, $response);
        self::assertSame(0, $this->userRepository()->lookups, 'no database lookup for a bot');
    }

    public function testALoginWithSolutionGoesThrough(): void
    {
        $this->bootSite(self::CONFIG);

        $response = $this->logIn(TestUserRepository::PASSWORD, $this->solution('login'));

        $this->assertLoginSucceeded($response);
    }

    public function testAWrongPasswordIsStillAWrongPassword(): void
    {
        $this->bootSite(self::CONFIG);

        $response = $this->logIn('wrong', $this->solution('login'));

        $this->assertLoginFailedWith('Invalid credentials.', $response);
    }

    public function testASolutionWorksOnce(): void
    {
        $this->bootSite(self::CONFIG);
        $solution = $this->solution('login');

        $this->logIn('wrong', $solution);
        $response = $this->logIn(TestUserRepository::PASSWORD, $solution);

        $this->assertLoginFailedWith(LoginAltchaSubscriber::MESSAGE_KEY, $response);
    }

    public function testASolutionForAnotherProfileIsRejected(): void
    {
        $this->bootSite(self::CONFIG);

        $response = $this->logIn(TestUserRepository::PASSWORD, $this->solution('default'));

        $this->assertLoginFailedWith(LoginAltchaSubscriber::MESSAGE_KEY, $response);
    }

    public function testATamperedSolutionIsRejected(): void
    {
        $this->bootSite(self::CONFIG);
        $payload = json_decode((string) base64_decode($this->solution('login'), true), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsArray($payload['challenge']);
        self::assertIsArray($payload['challenge']['parameters']);
        $payload['challenge']['parameters']['data'] = [
            'profile' => 'login',
            'forged' => true,
        ];

        $response = $this->logIn(TestUserRepository::PASSWORD, base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)));

        $this->assertLoginFailedWith(LoginAltchaSubscriber::MESSAGE_KEY, $response);
    }

    public function testAnArrayInsteadOfASolutionIsRejected(): void
    {
        $this->bootSite(self::CONFIG);

        $response = $this->logIn(TestUserRepository::PASSWORD, ['x']);

        $this->assertLoginFailedWith(LoginAltchaSubscriber::MESSAGE_KEY, $response);
    }

    /**
     * In debug mode Symfony wraps every authenticator in a TraceableAuthenticator.
     */
    public function testTheCheckAlsoRunsWithTheDebugKernel(): void
    {
        $this->bootSite(self::CONFIG, debug: true);

        $this->assertLoginFailedWith(LoginAltchaSubscriber::MESSAGE_KEY, $this->logIn(TestUserRepository::PASSWORD, null));
        $this->assertLoginSucceeded($this->logIn(TestUserRepository::PASSWORD, $this->solution('login')));
    }

    public function testNothingIsCheckedWhileTheLoginIsNotProtected(): void
    {
        $this->bootSite([]);

        self::assertStringNotContainsString('<altcha-widget', (string) $this->request('GET', '/bolt/login')->getContent());
        $this->assertLoginSucceeded($this->logIn(TestUserRepository::PASSWORD, null));
    }

    /**
     * Opens the login page (for the CSRF token) and posts the form as the browser would.
     *
     * @param string|list<string>|null $altcha
     */
    private function logIn(string $password, string|array|null $altcha): Response
    {
        $token = self::inputValue((string) $this->request('GET', '/bolt/login')->getContent(), 'login[_token]');
        $fields = [
            'login' => [
                'username' => TestUserRepository::USERNAME,
                'password' => $password,
                '_token' => $token,
            ],
        ];
        if ($altcha !== null) {
            $fields['altcha'] = $altcha;
        }

        return $this->request('POST', '/bolt/login', $fields);
    }

    private function assertLoginSucceeded(Response $response): void
    {
        self::assertTrue($response->isRedirect(), (string) $response->getContent());
        self::assertStringNotContainsString('/bolt/login', (string) $response->headers->get('Location'));
    }

    private function assertLoginFailedWith(string $messageKey, Response $response): void
    {
        self::assertTrue($response->isRedirect(), (string) $response->getContent());
        self::assertStringEndsWith('/bolt/login', (string) $response->headers->get('Location'));
        self::assertStringContainsString(
            '<p id="error">' . htmlspecialchars($messageKey, ENT_QUOTES) . '</p>',
            (string) $this->request('GET', '/bolt/login')->getContent()
        );
    }
}

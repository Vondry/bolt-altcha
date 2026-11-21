<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Tests\E2E;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Response;
use Tomvondracek\Altcha\Assets\AssetsStatus;
use Tomvondracek\Altcha\Tests\E2E\App\TestUserRepository;

/**
 * A replay store and lock that can't be created (var/data/bolt-altcha unwritable, Redis down)
 * must not take the site down: every request runs the route guard and every form the form
 * extension, both of which depend on the store.
 */
final class DurabilityE2ETest extends E2ETestCase
{
    private const CONFIG = [
        'profiles' => [
            'login' => [],
        ],
        'bolt' => [
            'login' => true,
            'reset_password' => true,
        ],
    ];

    public function testTheSiteWorksWhenTheReplayStoreCannotBeCreated(): void
    {
        // A file where the directory should be: neither the FilesystemAdapter nor the FlockStore
        // can create their directories, even when the tests run as root.
        $this->bootSite(self::CONFIG, prepare: static function (string $projectDir): void {
            (new Filesystem())->dumpFile($projectDir . '/var/data/bolt-altcha', 'not a directory');
        });

        self::assertSame('home', $this->request('GET', '/')->getContent());
        self::assertStringContainsString('<altcha-widget', (string) $this->request('GET', '/bolt/login')->getContent());
        self::assertStringContainsString('<altcha-widget', (string) $this->request('GET', '/bolt/reset-password')->getContent());

        $token = self::inputValue((string) $this->request('GET', '/bolt/login')->getContent(), 'login[_token]');
        $response = $this->request('POST', '/bolt/login', [
            'login' => [
                'username' => TestUserRepository::USERNAME,
                'password' => TestUserRepository::PASSWORD,
                '_token' => $token,
            ],
            'altcha' => $this->solution('login'),
        ]);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertStringNotContainsString('/bolt/login', (string) $response->headers->get('Location'));
        self::assertNotSame([], $this->logger()->messages('error'), 'the broken store is logged');
    }

    /**
     * A deploy that skipped `extensions:configure` has no widget files: nobody could solve a
     * challenge, so every automatic check stands down (logged once), the password reset forms as
     * well as the login, instead of locking people out.
     */
    public function testAnUnpublishedWidgetSwitchesTheProtectionOffInsteadOfLockingOut(): void
    {
        $this->bootSite(self::CONFIG, prepare: static function (string $projectDir): void {
            (new Filesystem())->remove(AssetsStatus::directory($projectDir, 'public'));
        });

        $html = (string) $this->request('GET', '/bolt/reset-password')->getContent();
        self::assertStringNotContainsString('<altcha-widget', $html);
        $response = $this->request('POST', '/bolt/reset-password', [
            'reset_password_request_form' => [
                'email' => 'admin@example.com',
                '_token' => self::inputValue($html, 'reset_password_request_form[_token]'),
            ],
        ]);
        self::assertSame('accepted', $response->getContent());

        $html = (string) $this->request('GET', '/bolt/login')->getContent();
        self::assertStringNotContainsString('<altcha-widget', $html);
        $response = $this->request('POST', '/bolt/login', [
            'login' => [
                'username' => TestUserRepository::USERNAME,
                'password' => TestUserRepository::PASSWORD,
                '_token' => self::inputValue($html, 'login[_token]'),
            ],
        ]);
        self::assertStringNotContainsString('/bolt/login', (string) $response->headers->get('Location'));

        self::assertSame(Response::HTTP_NOT_FOUND, $this->request('GET', '/altcha/challenge/default')->getStatusCode());
        self::assertCount(1, $this->logger()->messages('critical'), 'logged once, not per request');
    }

    public function testTheKillSwitchWorksWhenTheReplayStoreCannotBeCreated(): void
    {
        $this->bootSite([
            'enabled' => false,
        ] + self::CONFIG, prepare: static function (string $projectDir): void {
            (new Filesystem())->dumpFile($projectDir . '/var/data/bolt-altcha', 'not a directory');
        });

        self::assertSame('home', $this->request('GET', '/')->getContent());
        self::assertSame(Response::HTTP_OK, $this->request('GET', '/bolt/login')->getStatusCode());
    }
}

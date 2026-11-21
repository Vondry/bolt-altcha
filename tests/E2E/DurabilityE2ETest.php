<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Tests\E2E;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Response;
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

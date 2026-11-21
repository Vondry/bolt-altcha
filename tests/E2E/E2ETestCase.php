<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Tests\E2E;

use AltchaOrg\Altcha\Challenge;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Yaml\Yaml;
use Tomvondracek\Altcha\Assets\AssetsStatus;
use Tomvondracek\Altcha\Config\AltchaConfigLoader;
use Tomvondracek\Altcha\Tests\AltchaTestTrait;
use Tomvondracek\Altcha\Tests\E2E\App\E2EKernel;
use Tomvondracek\Altcha\Tests\E2E\App\TestLogger;
use Tomvondracek\Altcha\Tests\E2E\App\TestUserRepository;
use Tomvondracek\Altcha\Widget\WidgetRenderer;

/**
 * Boots E2EKernel on a fresh project directory per test, laid out like a Bolt site after
 * `composer require` and `extensions:configure`, and sends it requests with a cookie jar.
 */
abstract class E2ETestCase extends TestCase
{
    use AltchaTestTrait;

    protected string $projectDir;
    private ?E2EKernel $kernel = null;

    /** @var array<string, string> */
    private array $cookies = [];

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
        $this->cookies = [];

        // Unlinked before the temp dirs are removed, so nothing ever follows it into the repository.
        $link = ($this->projectDir ?? '') . '/vendor/tomvondracek/bolt-altcha';
        if (is_link($link)) {
            unlink($link);
        }
    }

    /**
     * Creates the project and boots the kernel. `$prepare` runs on the project directory first.
     *
     * @param array<array-key, mixed> $config tomvondracek-altcha.yaml, merged over FAST
     */
    protected function bootSite(array $config, bool $debug = false, ?callable $prepare = null): void
    {
        $this->projectDir = $this->tempDir();
        $filesystem = new Filesystem();

        $filesystem->mkdir($this->projectDir . '/vendor/tomvondracek');
        $filesystem->symlink(E2EKernel::repositoryDir(), $this->projectDir . '/vendor/tomvondracek/bolt-altcha');
        $filesystem->dumpFile(AssetsStatus::directory($this->projectDir, 'public') . '/' . AssetsStatus::VERSION_FILE, WidgetRenderer::ASSETS_VERSION . "\n");
        $filesystem->mkdir($this->projectDir . '/var/data');
        $filesystem->dumpFile(
            $this->projectDir . '/config/extensions/' . AltchaConfigLoader::CONFIG_BASENAME . '.yaml',
            Yaml::dump(array_replace_recursive(self::FAST, $config), 8)
        );

        if ($prepare !== null) {
            $prepare($this->projectDir);
        }

        $this->kernel = new E2EKernel($this->projectDir, $debug);
        $this->kernel->boot();
    }

    /**
     * @param array<string, mixed>  $parameters POST fields (or query for GET)
     * @param array<string, string> $server
     */
    protected function request(string $method, string $uri, array $parameters = [], array $server = []): Response
    {
        $kernel = $this->kernel ?? throw new RuntimeException('Call bootSite() first.');
        $request = Request::create($uri, $method, $parameters, $this->cookies, [], $server);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        foreach ($response->headers->getCookies() as $cookie) {
            $this->cookies[$cookie->getName()] = (string) $cookie->getValue();
        }

        return $response;
    }

    /**
     * Fetches a challenge from the site's endpoint, as the widget does, and solves it.
     *
     * @param array<string, string> $server
     */
    protected function solution(string $profile, array $server = []): string
    {
        $response = $this->request('GET', '/altcha/challenge/' . $profile, [], $server);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), 'challenge endpoint: ' . $response->getContent());

        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return self::solve(Challenge::fromArray($data));
    }

    /**
     * The value of the hidden input `$name` in `$html`.
     */
    protected static function inputValue(string $html, string $name): string
    {
        $pattern = '/name="' . preg_quote(htmlspecialchars($name, ENT_QUOTES), '/') . '"[^>]*value="([^"]*)"|value="([^"]*)"[^>]*name="' . preg_quote(htmlspecialchars($name, ENT_QUOTES), '/') . '"/';
        if (preg_match($pattern, $html, $match) !== 1) {
            throw new RuntimeException(sprintf('No input "%s" in: %s', $name, $html));
        }

        return html_entity_decode($match[1] !== '' ? $match[1] : ($match[2] ?? ''), ENT_QUOTES);
    }

    protected function logger(): TestLogger
    {
        $logger = $this->kernel?->getContainer()->get('logger');
        self::assertInstanceOf(TestLogger::class, $logger);

        return $logger;
    }

    protected function userRepository(): TestUserRepository
    {
        $repository = $this->kernel?->getContainer()->get(TestUserRepository::class);
        self::assertInstanceOf(TestUserRepository::class, $repository);

        return $repository;
    }
}

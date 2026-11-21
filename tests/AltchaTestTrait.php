<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Tests;

use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Challenge;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\SolveChallengeOptions;
use Bolt\Configuration\Config;
use PHPUnit\Framework\Attributes\After;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Yaml\Yaml;
use Tomvondracek\Altcha\Assets\AssetsStatus;
use Tomvondracek\Altcha\Challenge\Algorithms;
use Tomvondracek\Altcha\Challenge\ChallengeFactory;
use Tomvondracek\Altcha\Challenge\HmacKeyProvider;
use Tomvondracek\Altcha\Challenge\ReplayStore;
use Tomvondracek\Altcha\Config\AltchaConfigLoader;
use Tomvondracek\Altcha\Config\Protection;
use Tomvondracek\Altcha\Verification\Verifier;
use Tomvondracek\Altcha\Widget\WidgetRenderer;
use Tomvondracek\Altcha\Widget\WidgetTexts;

/**
 * Real services wired by hand: a config loader reading a temporary
 * tomvondracek-altcha.yaml, cheap challenges, an in-memory replay store.
 */
trait AltchaTestTrait
{
    /**
     * Challenges that solve in a few milliseconds.
     */
    private const FAST = [
        'challenge' => [
            'cost' => 1,
            'counter_min' => 1,
            'counter_max' => 20,
        ],
    ];

    /**
     * A strong APP_SECRET (HmacKeyProvider::MIN_KEY_LENGTH).
     */
    private const APP_SECRET = 'test-app-secret-0123456789abcdef0123456789';

    /** @var list<string> */
    private array $tempDirs = [];

    #[After]
    public function removeTempDirs(): void
    {
        (new Filesystem())->remove($this->tempDirs);
        $this->tempDirs = [];
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/bolt-altcha-test-' . bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($dir);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    /**
     * @param array<array-key, mixed> $raw the project's tomvondracek-altcha.yaml, merged over FAST
     */
    private function configLoader(array $raw = []): AltchaConfigLoader
    {
        $dir = $this->tempDir();
        file_put_contents(
            $dir . '/' . AltchaConfigLoader::CONFIG_BASENAME . '.yaml',
            Yaml::dump(array_replace_recursive(self::FAST, $raw), 8)
        );

        $config = self::createStub(Config::class);
        $config->method('getPath')
            ->willReturn($dir);

        return new AltchaConfigLoader($config);
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private function services(array $raw = [], ?MockClock $clock = null, string $appSecret = self::APP_SECRET): AltchaServices
    {
        $clock ??= new MockClock();
        $loader = $this->configLoader($raw);
        $keys = new HmacKeyProvider($appSecret);
        $factory = new ChallengeFactory($keys, $clock);
        $replayStore = new ReplayStore(new ArrayAdapter(), $clock);

        return new AltchaServices($loader, $keys, $factory, $replayStore, new Verifier($loader, $factory, $replayStore), $clock, new Protection($loader, $keys));
    }

    /**
     * A project directory whose public/assets/altcha/VERSION says the widget is published (or not).
     */
    private function assets(bool $published = true, string $version = WidgetRenderer::ASSETS_VERSION): AssetsStatus
    {
        $projectDir = $this->tempDir();
        if ($published) {
            (new Filesystem())->dumpFile(AssetsStatus::directory($projectDir, 'public') . '/' . AssetsStatus::VERSION_FILE, $version . "\n");
        }

        return new AssetsStatus($projectDir, 'public');
    }

    /**
     * What the widget submits: the challenge and its solution, base64 JSON.
     */
    private static function solve(Challenge $challenge): string
    {
        $solution = (new Altcha())->solveChallenge(new SolveChallengeOptions(
            algorithm: Algorithms::create($challenge->parameters->algorithm),
            challenge: $challenge,
        ));

        if ($solution === null) {
            throw new RuntimeException('The test challenge could not be solved.');
        }

        return (new Payload($challenge, $solution))->toBase64();
    }

    private function solvedPayload(AltchaServices $services, string $profile = 'default'): string
    {
        $settings = $services->loader->load()->profile($profile) ?? throw new RuntimeException('No profile ' . $profile);

        return self::solve($services->factory->create($settings));
    }

    /**
     * A translator with the extension's catalogs, as Symfony loads them in a project.
     */
    private static function translator(string $locale = 'en'): Translator
    {
        $translator = new Translator($locale);
        $translator->setFallbackLocales(['en']);
        $translator->addLoader('yaml', new YamlFileLoader());

        $files = glob(dirname(__DIR__) . '/translations/*.yaml');
        foreach ($files === false ? [] : $files as $file) {
            [$domain, $fileLocale] = explode('.', basename($file));
            $translator->addResource('yaml', $file, $fileLocale, $domain);
        }

        return $translator;
    }

    /**
     * The renderer with the challenge route as `/altcha/challenge/<profile>`.
     */
    private function widgetRenderer(AltchaServices $services, ?Request $request = new Request(), bool $published = true, ?LoggerInterface $logger = null): WidgetRenderer
    {
        $urlGenerator = self::createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')
            ->willReturnCallback(static function (string $route, array $parameters): string {
                $profile = $parameters['profile'] ?? '';

                return '/altcha/challenge/' . (is_string($profile) ? $profile : '');
            });

        $requestStack = new RequestStack();
        if ($request instanceof Request) {
            $requestStack->push($request);
        }

        return new WidgetRenderer($services->loader, $urlGenerator, $requestStack, new WidgetTexts(self::translator()), $services->protection, $this->assets($published), $logger ?? new NullLogger());
    }
}

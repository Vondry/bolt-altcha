<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Tests;

use ArrayObject;
use Bolt\Configuration\Config;
use Bolt\Widget\Injector\AdditionalTarget;
use Bolt\Widget\WidgetInterface;
use Bolt\Widgets;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;
use Tomvondracek\Altcha\Assets\AssetsNoticeWidget;
use Tomvondracek\Altcha\Assets\AssetsStatus;
use Tomvondracek\Altcha\Config\AltchaConfigLoader;
use Tomvondracek\Altcha\Extension;
use Tomvondracek\Altcha\Widget\LoginWidget;
use Tomvondracek\Altcha\Widget\WidgetRenderer;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class ExtensionTest extends TestCase
{
    use AltchaTestTrait;

    public function testName(): void
    {
        self::assertSame('ALTCHA', (new Extension())->getName());
        self::assertSame('altcha', (new Extension())->getSlug());
    }

    /**
     * Bolt's ConfigTrait (used by `extensions:configure --with-config`) and AltchaConfigLoader
     * must agree on the config filename, or the project's config file is never read.
     */
    public function testConfigFilenameMatchesTheLoader(): void
    {
        $config = self::createStub(Config::class);
        $config->method('getPath')
            ->willReturn('/project/config/extensions');

        $container = new Container();
        $container->set(Config::class, $config);

        $filenames = $this->extension($container)
            ->getConfigFilenames();

        self::assertIsString($filenames['main'] ?? null);
        self::assertIsString($filenames['local'] ?? null);
        self::assertSame(AltchaConfigLoader::CONFIG_BASENAME . '.yaml', basename($filenames['main']));
        self::assertSame(AltchaConfigLoader::CONFIG_BASENAME . '_local.yaml', basename($filenames['local']));
    }

    public function testInitializeRegistersTwigNamespace(): void
    {
        $loader = new FilesystemLoader();
        [$container] = $this->container([], true);
        $container->set('twig', new Environment($loader));

        $this->extension($container)
            ->initialize();

        self::assertContains(dirname(__DIR__) . '/templates', $loader->getPaths('bolt-altcha'));
    }

    /**
     * Bolt wraps every widget in a <div>: nothing is registered while there is nothing to show.
     */
    public function testNoWidgetWhileTheLoginIsOffAndTheWidgetIsPublished(): void
    {
        [$container, $registered] = $this->container([], true);

        $this->extension($container)
            ->initialize();

        self::assertSame([], $registered->getArrayCopy());
    }

    public function testLoginWidgetWhileTheLoginIsProtected(): void
    {
        [$container, $registered] = $this->container([
            'bolt' => [
                'login' => true,
            ],
        ], true);

        $this->extension($container)
            ->initialize();

        self::assertSame([LoginWidget::class], $registered->getArrayCopy());
    }

    public function testNoLoginWidgetWhileTheConfigIsBroken(): void
    {
        [$container, $registered] = $this->container([
            'bolt' => [
                'login' => 'missing-profile',
            ],
        ], true);

        $this->extension($container)
            ->initialize();

        self::assertSame([], $registered->getArrayCopy());
    }

    public function testDashboardNoticeWhileTheWidgetIsMissingOrOutdated(): void
    {
        [$missing, $registered] = $this->container([], false);
        $this->extension($missing)
            ->initialize();
        self::assertSame([AssetsNoticeWidget::class], $registered->getArrayCopy());

        [$outdated, $registered] = $this->container([], true, '0.0.1');
        $this->extension($outdated)
            ->initialize();
        self::assertSame([AssetsNoticeWidget::class], $registered->getArrayCopy());
    }

    public function testWidgetsSitInTheirSlots(): void
    {
        $login = new LoginWidget();
        self::assertSame([AdditionalTarget::WIDGET_BACK_LOGIN_MIDDLE], $login->getTargets());
        self::assertSame('@bolt-altcha/login_widget.html.twig', $login->getTemplate());

        $notice = new AssetsNoticeWidget();
        self::assertSame([AdditionalTarget::WIDGET_BACK_DASHBOARD_ASIDE_TOP], $notice->getTargets());
        self::assertFileExists(dirname(__DIR__) . '/templates/assets_notice.html.twig');
    }

    public function testInstallPublishesTheWidgetWithItsVersion(): void
    {
        $projectDir = $this->tempDir();
        $stale = $projectDir . '/public/assets/altcha/workers/removed-in-this-version.js';
        (new Filesystem())->dumpFile($stale, '');

        $container = new Container();
        $container->setParameter('kernel.project_dir', $projectDir);
        $container->setParameter('bolt.public_folder', 'public');

        $this->extension($container)
            ->install();

        self::assertFileEquals(dirname(__DIR__) . '/assets/altcha/bolt-altcha.js', $projectDir . '/public/assets/altcha/bolt-altcha.js');
        self::assertFileExists($projectDir . '/public/assets/altcha/workers/pbkdf2.js');
        self::assertFileExists($projectDir . '/public/assets/altcha/altcha.css');
        self::assertFileDoesNotExist($stale, 'files of an older widget are removed');

        $status = new AssetsStatus($projectDir, 'public');
        self::assertSame(WidgetRenderer::ASSETS_VERSION, $status->publishedVersion());
        self::assertTrue($status->isCurrent());
    }

    /**
     * @param array<array-key, mixed> $config the project's tomvondracek-altcha.yaml
     *
     * @return array{Container, ArrayObject<int, class-string>}
     */
    private function container(array $config, bool $published, string $version = WidgetRenderer::ASSETS_VERSION): array
    {
        $configDir = $this->tempDir();
        file_put_contents($configDir . '/' . AltchaConfigLoader::CONFIG_BASENAME . '.yaml', Yaml::dump($config, 4));
        $boltConfig = self::createStub(Config::class);
        $boltConfig->method('getPath')
            ->willReturn($configDir);

        $projectDir = $this->tempDir();
        if ($published) {
            (new Filesystem())->dumpFile(AssetsStatus::directory($projectDir, 'public') . '/' . AssetsStatus::VERSION_FILE, $version);
        }

        /** @var ArrayObject<int, class-string> $registered */
        $registered = new ArrayObject();
        $widgets = self::createStub(Widgets::class);
        $widgets->method('registerWidget')
            ->willReturnCallback(static function (WidgetInterface $widget) use ($registered): void {
                $registered->append($widget::class);
            });

        $container = new Container();
        $container->set(Config::class, $boltConfig);
        $container->set(Widgets::class, $widgets);
        $container->set('twig', new Environment(new FilesystemLoader()));
        $container->setParameter('kernel.project_dir', $projectDir);
        $container->setParameter('bolt.public_folder', 'public');

        return [$container, $registered];
    }

    private function extension(Container $container): Extension
    {
        $extension = new Extension();
        $extension->injectObjects([
            'manager' => null,
            'query' => null,
            'container' => $container,
        ]);

        return $extension;
    }
}

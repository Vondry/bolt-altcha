<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Tests;

use Bolt\Configuration\Config;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Form\FormTypeExtensionInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Service\ResetInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tomvondracek\Altcha\Challenge\ReplayStore;
use Tomvondracek\Altcha\Config\AltchaConfigLoader;
use Tomvondracek\Altcha\Config\ConfigValidationWarmer;
use Tomvondracek\Altcha\Config\Protection;
use Tomvondracek\Altcha\Controller\ChallengeController;
use Tomvondracek\Altcha\Form\AltchaType;
use Tomvondracek\Altcha\Form\AltchaTypeExtension;
use Tomvondracek\Altcha\Guard\RequireAltchaSubscriber;
use Tomvondracek\Altcha\Guard\RouteGuardSubscriber;
use Tomvondracek\Altcha\Security\LoginAltchaSubscriber;
use Tomvondracek\Altcha\Twig\AltchaTwigExtension;
use Tomvondracek\Altcha\Verification\Verifier;
use Tomvondracek\Altcha\Widget\WidgetRenderer;
use Tomvondracek\Altcha\Widget\WidgetTexts;
use Twig\Extension\ExtensionInterface;

/**
 * Guards the registrations: what Bolt's generated config/services_bolt.yaml does with the
 * extension's classes, and config/services.yaml and config/routes.yaml, which
 * `extensions:configure` copies into the project.
 */
final class ConfigServicesTest extends TestCase
{
    private const VENDOR_PATH = '%kernel.project_dir%/vendor/tomvondracek/bolt-altcha';
    private const SERVICES = [
        ChallengeController::class,
        AltchaType::class,
        AltchaTypeExtension::class,
        LoginAltchaSubscriber::class,
        RouteGuardSubscriber::class,
        RequireAltchaSubscriber::class,
        AltchaTwigExtension::class,
        Verifier::class,
        WidgetRenderer::class,
        ConfigValidationWarmer::class,
        ReplayStore::class,
        Protection::class,
        AltchaConfigLoader::class,
    ];

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/bolt-altcha-services-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testServicesFileRegistersTemplatesFormThemeAndTranslations(): void
    {
        $config = Yaml::parseFile(dirname(__DIR__) . '/config/services.yaml', Yaml::PARSE_CUSTOM_TAGS);
        self::assertIsArray($config);

        self::assertSame([
            'translator' => [
                'paths' => [self::VENDOR_PATH . '/translations'],
            ],
        ], $config['framework'] ?? null);
        self::assertSame([
            'paths' => [
                self::VENDOR_PATH . '/templates' => 'bolt-altcha',
            ],
            'form_themes' => ['@bolt-altcha/form/altcha.html.twig'],
        ], $config['twig'] ?? null);

        self::assertFileExists(dirname(__DIR__) . '/templates/form/altcha.html.twig');
        self::assertFileExists(dirname(__DIR__) . '/templates/login_widget.html.twig');
    }

    public function testRoutesLoadTheControllerDirectory(): void
    {
        $routes = Yaml::parseFile(dirname(__DIR__) . '/config/routes.yaml');
        self::assertIsArray($routes);

        self::assertSame(
            [
                'resource' => '../../vendor/tomvondracek/bolt-altcha/src/Controller/',
                'type' => 'attribute',
            ],
            $routes['tomvondracek_altcha'] ?? null
        );
    }

    /**
     * Right after `composer require`, Bolt has added the extension to config/services_bolt.yaml,
     * but `extensions:configure` (which needs a working container itself) has not copied
     * config/services.yaml yet. The site must keep working.
     */
    public function testContainerCompilesBeforeExtensionsConfigure(): void
    {
        $this->assertServicesAreWired($this->compile(false));
    }

    public function testContainerCompilesAfterExtensionsConfigure(): void
    {
        $this->assertServicesAreWired($this->compile(true));
    }

    /**
     * Before `extensions:configure`: the application cache, and a lock only when the project
     * configured symfony/lock itself.
     */
    public function testReplayStoreUsesTheApplicationCacheBeforeExtensionsConfigure(): void
    {
        $arguments = $this->compile(false)
            ->getDefinition(ReplayStore::class)
            ->getArguments();

        self::assertInstanceOf(Reference::class, $arguments[0] ?? null);
        self::assertSame('cache.app', (string) $arguments[0]);
    }

    /**
     * After it: its own pool in var/data, which `cache:clear` doesn't empty, and a lock.
     */
    public function testReplayStoreUsesItsOwnPoolAndLockAfterExtensionsConfigure(): void
    {
        $container = $this->compile(true);
        $arguments = $container->getDefinition(ReplayStore::class)->getArguments();

        // Passed as closures, so a store that can't be created doesn't fail the services that
        // depend on it (the route guard runs on every request).
        $pool = self::closureTarget($container, $arguments[0] ?? null);
        self::assertSame(FilesystemAdapter::class, $pool->getClass());
        self::assertSame(dirname(__DIR__) . '/var/data/bolt-altcha', $container->getParameterBag()->resolveValue($pool->getArgument(2)));
        self::assertFalse($pool->hasTag('cache.pool'), 'not a pool cache:clear empties');

        $lockFactory = self::closureTarget($container, $arguments[2] ?? null);
        self::assertSame(LockFactory::class, $lockFactory->getClass());
        $lockStore = $lockFactory->getArgument(0);
        self::assertInstanceOf(Definition::class, $lockStore);
        self::assertSame(FlockStore::class, $lockStore->getClass());
    }

    private static function closureTarget(ContainerBuilder $container, mixed $argument): Definition
    {
        self::assertInstanceOf(ServiceClosureArgument::class, $argument);
        $target = $argument->getValues()[0] ?? null;
        self::assertInstanceOf(Reference::class, $target);

        return $container->findDefinition((string) $target);
    }

    private function compile(bool $configured): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', dirname(__DIR__));
        $container->setParameter('kernel.secret', 'secret');
        $container->setParameter('bolt.public_folder', 'public');

        // Services of FrameworkBundle, TwigBundle and Bolt.
        foreach ([Config::class, RequestStack::class, UrlGeneratorInterface::class, TranslatorInterface::class, LoggerInterface::class, ClockInterface::class, 'cache.app'] as $service) {
            $container->register($service)
                ->setSynthetic(true);
        }

        // FrameworkBundle's and TwigBundle's autoconfiguration.
        $container->registerForAutoconfiguration(EventSubscriberInterface::class)
            ->addTag('kernel.event_subscriber');
        $container->registerForAutoconfiguration(FormTypeInterface::class)
            ->addTag('form.type');
        $container->registerForAutoconfiguration(FormTypeExtensionInterface::class)
            ->addTag('form.type_extension');
        $container->registerForAutoconfiguration(ExtensionInterface::class)
            ->addTag('twig.extension');
        $container->registerForAutoconfiguration(CacheWarmerInterface::class)
            ->addTag('kernel.cache_warmer');
        $container->registerForAutoconfiguration(ResetInterface::class)
            ->addTag('kernel.reset', [
                'method' => 'reset',
            ]);

        // What Bolt's ExtensionCompilerPass writes to config/services_bolt.yaml.
        $src = dirname(__DIR__) . '/src';
        file_put_contents($this->dir . '/services_bolt.yaml', Yaml::dump([
            'services' => [
                '_defaults' => [
                    'autowire' => true,
                    'autoconfigure' => true,
                    'bind' => [],
                ],
                'Tomvondracek\Altcha\\' => [
                    'resource' => $src . '/*',
                    'exclude' => $src . '/{Entity,Exception}',
                ],
            ],
        ], 4, 2));
        (new YamlFileLoader($container, new FileLocator($this->dir)))->load('services_bolt.yaml');

        if ($configured) {
            // config/services.yaml configures FrameworkBundle and Twig, which aren't part of this container.
            foreach (['framework', 'twig'] as $alias) {
                $container->registerExtension(new class($alias) extends Extension {
                    public function __construct(
                        private readonly string $alias,
                    ) {
                    }

                    public function load(array $configs, ContainerBuilder $container): void
                    {
                    }

                    public function getAlias(): string
                    {
                        return $this->alias;
                    }
                });
            }

            (new YamlFileLoader($container, new FileLocator(dirname(__DIR__) . '/config')))->load('services.yaml');
        }

        // In a project these are used (controllers, listeners, form types), so errors in them
        // surface when compiling.
        foreach (self::SERVICES as $public) {
            $container->getDefinition($public)
                ->setPublic(true);
        }

        $container->compile(true);

        return $container;
    }

    private function assertServicesAreWired(ContainerBuilder $container): void
    {
        foreach (self::SERVICES as $service) {
            self::assertTrue($container->hasDefinition($service), $service);
        }

        self::assertTrue($container->getDefinition(LoginAltchaSubscriber::class)->hasTag('kernel.event_subscriber'));
        self::assertTrue($container->getDefinition(RouteGuardSubscriber::class)->hasTag('kernel.event_subscriber'));
        self::assertTrue($container->getDefinition(RequireAltchaSubscriber::class)->hasTag('kernel.event_subscriber'));
        self::assertTrue($container->getDefinition(AltchaType::class)->hasTag('form.type'));
        self::assertTrue($container->getDefinition(AltchaTypeExtension::class)->hasTag('form.type_extension'));
        self::assertTrue($container->getDefinition(AltchaTwigExtension::class)->hasTag('twig.extension'));
        self::assertTrue($container->getDefinition(ConfigValidationWarmer::class)->hasTag('kernel.cache_warmer'));
        self::assertTrue($container->getDefinition(AltchaConfigLoader::class)->hasTag('kernel.reset'), 'reset between the requests of a worker');
        self::assertTrue($container->getDefinition(Protection::class)->hasTag('kernel.reset'));

        $texts = $container->getDefinition(WidgetRenderer::class)->getArguments()[3] ?? null;
        self::assertTrue(
            ($texts instanceof Reference && (string) $texts === WidgetTexts::class)
            || ($texts instanceof Definition && $texts->getClass() === WidgetTexts::class),
            'the renderer gets its texts from WidgetTexts'
        );
    }
}

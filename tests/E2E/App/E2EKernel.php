<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Tests\E2E\App;

use Bolt\Configuration\Config;
use Bolt\Entity\User;
use Bolt\Form\ChangePasswordFormType;
use Bolt\Form\ResetPasswordRequestFormType;
use Bolt\Repository\UserRepository;
use Bolt\Security\LoginFormAuthenticator;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * A Symfony application wired like a Bolt 6 site, for end-to-end tests:
 *
 *   - the extension's services registered the way Bolt's services_bolt.yaml does, plus the
 *     package's own config/services.yaml and config/routes.yaml (via the project's
 *     vendor/tomvondracek/bolt-altcha, a symlink to this repository);
 *   - Bolt's real LoginFormAuthenticator on a `main` firewall with `login_throttling`, as in
 *     Bolt's security.yaml, with a user repository that needs no database, and a second login
 *     (`form_login`, a members' area) for the route guard;
 *   - Bolt's real ResetPasswordRequestFormType and ChangePasswordFormType, handled by a controller
 *     that does what Bolt's ResetPasswordController does with them.
 */
final class E2EKernel extends Kernel
{
    use MicroKernelTrait;

    public const APP_SECRET = 'e2e-app-secret-0123456789abcdef0123456789abcdef';

    public function __construct(
        private readonly string $projectDir,
        bool $debug = false,
    ) {
        parent::__construct('test', $debug);
    }

    public static function repositoryDir(): string
    {
        return dirname(__DIR__, 3);
    }

    public function getProjectDir(): string
    {
        return $this->projectDir;
    }

    public function getCacheDir(): string
    {
        return $this->projectDir . '/var/cache/' . $this->getEnvironment();
    }

    public function getLogDir(): string
    {
        return $this->projectDir . '/var/log';
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        yield new TwigBundle();
    }

    /**
     * One container class per project, so several kernels can boot in one test process.
     */
    protected function getContainerClass(): string
    {
        return 'E2EKernel' . ($this->isDebug() ? 'Debug' : '') . md5($this->projectDir) . 'Container';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->parameters()
            ->set('bolt.public_folder', 'public');

        $container->extension('framework', [
            'secret' => self::APP_SECRET,
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => [
                'log' => true,
            ],
            'session' => [
                'storage_factory_id' => 'session.storage.factory.mock_file',
            ],
            'csrf_protection' => true,
            'form' => true,
            'translator' => [
                'default_path' => '%kernel.project_dir%/translations',
                'fallbacks' => ['en'],
            ],
            'router' => [
                'utf8' => true,
            ],
        ]);

        $container->extension('twig', [
            'default_path' => '%kernel.project_dir%/templates',
        ]);

        $container->extension('security', [
            'password_hashers' => [
                User::class => 'plaintext',
            ],
            'providers' => [
                'bolt_users' => [
                    'id' => TestUserProvider::class,
                ],
            ],
            'firewalls' => [
                'main' => [
                    'pattern' => '^/',
                    'provider' => 'bolt_users',
                    'custom_authenticators' => [LoginFormAuthenticator::class],
                    // A second login, handled inside the firewall like any authenticator's: what
                    // a site would protect under `routes:`.
                    'form_login' => [
                        'login_path' => 'member_login',
                        'check_path' => 'member_login_check',
                    ],
                    'login_throttling' => [
                        'max_attempts' => 50,
                    ],
                ],
            ],
        ]);

        // What Bolt's ExtensionCompilerPass writes to config/services_bolt.yaml.
        $src = self::repositoryDir() . '/src';
        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();
        $services->load('Tomvondracek\\Altcha\\', $src . '/*')
            ->exclude($src . '/{Entity,Exception}');

        // `extensions:configure` copies it to config/packages/extension_bolt-altcha.yaml.
        $container->import($this->projectDir . '/vendor/tomvondracek/bolt-altcha/config/services.yaml');

        // Bolt's services the extension and the login need, without a database.
        $services->set(Config::class, TestBoltConfig::class)
            ->arg('$extensionsConfigDir', '%kernel.project_dir%/config/extensions');
        $services->set(TestUserRepository::class)
            ->public();
        $services->alias(UserRepository::class, TestUserRepository::class);
        $services->set(TestUserProvider::class);
        $services->set(LoginFormAuthenticator::class)
            ->args([service('security.helper'), service(TestUserRepository::class), service('router'), service('security.http_utils')]);
        $services->set(ResetPasswordRequestFormType::class);
        $services->set(ChangePasswordFormType::class);
        $services->set(TestController::class)
            ->public()
            ->tag('controller.service_arguments');

        // Collects what the extension logs, so tests can assert on it.
        $services->set('logger', TestLogger::class)
            ->public();
        $services->alias(LoggerInterface::class, 'logger');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        // `extensions:configure` copies it to config/routes/extension_bolt-altcha.yaml.
        $routes->import($this->projectDir . '/vendor/tomvondracek/bolt-altcha/src/Controller/', 'attribute');

        $routes->add('homepage', '/')
            ->controller([TestController::class, 'homepage']);
        $routes->add('bolt_dashboard', '/bolt')
            ->controller([TestController::class, 'homepage']);
        $routes->add('bolt_login', '/bolt/login')
            ->controller([TestController::class, 'login']);
        $routes->add('bolt_forgot_password_request', '/bolt/reset-password')
            ->controller([TestController::class, 'resetPasswordRequest']);
        $routes->add('bolt_reset_password', '/bolt/reset-password/reset')
            ->controller([TestController::class, 'changePassword']);
        $routes->add('member_login', '/member/login')
            ->controller([TestController::class, 'homepage']);
        $routes->add('member_login_check', '/member/login-check')
            ->methods(['POST']);
        $routes->add('newsletter_subscribe', '/newsletter')
            ->controller([TestController::class, 'newsletter'])
            ->methods(['POST']);
    }
}

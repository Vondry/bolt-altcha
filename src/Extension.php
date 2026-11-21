<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha;

use Bolt\Extension\BaseExtension;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Tomvondracek\Altcha\Assets\AssetsNoticeWidget;
use Tomvondracek\Altcha\Assets\AssetsStatus;
use Tomvondracek\Altcha\Assets\ServicesNoticeWidget;
use Tomvondracek\Altcha\Assets\ServicesStatus;
use Tomvondracek\Altcha\Config\AltchaConfigLoader;
use Tomvondracek\Altcha\Widget\LoginWidget;
use Tomvondracek\Altcha\Widget\WidgetRenderer;

final class Extension extends BaseExtension
{
    /**
     * Translation domain of the extension's texts (translations/bolt_altcha.<locale>.yaml): the
     * widget's texts, the form error and the dashboard notice. A project's
     * translations/bolt_altcha.<locale>.yaml overrides them. Two messages live in Bolt's domains,
     * because Bolt's templates translate them there: the login error (`security`) and the flash
     * message (`messages`).
     */
    public const TRANSLATION_DOMAIN = 'bolt_altcha';

    public function getName(): string
    {
        return 'ALTCHA';
    }

    /**
     * Runs on every browser request: cheap checks only.
     */
    public function initialize(): void
    {
        // The @bolt-altcha namespace is registered at container level, in config/services.yaml
        // (copied to config/packages/extension_bolt-altcha.yaml by `extensions:configure`), so
        // it also resolves on the CLI. This runtime registration is a fallback for projects that
        // have not run `extensions:configure` yet.
        $this->addTwigNamespace('bolt-altcha');

        $container = $this->getContainer();
        $projectDir = $container->getParameter('kernel.project_dir');
        $public = $container->getParameter('bolt.public_folder');
        if (! is_string($projectDir) || ! is_string($public)) {
            return;
        }

        // Bolt wraps every widget in a <div>, even an empty one: register the login widget only
        // while the login is protected.
        $config = (new AltchaConfigLoader($this->getBoltConfig()))->tryLoad();
        if ($config !== null && $config->enabled && $config->loginProfile !== null) {
            $this->addWidget(new LoginWidget());
        }

        if (! (new AssetsStatus($projectDir, $public))->isCurrent()) {
            $this->addWidget(new AssetsNoticeWidget());
        }

        if (ServicesStatus::isOutdated($projectDir)) {
            $this->addWidget(new ServicesNoticeWidget());
        }
    }

    /**
     * Run by `extensions:configure`: publishes the widget (assets/altcha/) to
     * public/assets/altcha/, with a VERSION file AssetsStatus reads.
     */
    public function install(): void
    {
        $container = $this->getContainer();
        $projectDir = $container->getParameter('kernel.project_dir');
        $public = $container->getParameter('bolt.public_folder');

        if (! is_string($projectDir) || ! is_string($public)) {
            throw new RuntimeException('The "kernel.project_dir" and "bolt.public_folder" container parameters must be strings.');
        }

        $target = AssetsStatus::directory($projectDir, $public);
        $filesystem = new Filesystem();
        $filesystem->mirror(dirname(__DIR__) . '/assets/altcha', $target, null, [
            'delete' => true,
            'override' => true,
        ]);
        $filesystem->dumpFile($target . '/' . AssetsStatus::VERSION_FILE, WidgetRenderer::ASSETS_VERSION . "\n");
    }
}

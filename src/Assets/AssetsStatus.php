<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Assets;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Tomvondracek\Altcha\Widget\WidgetRenderer;

/**
 * Whether the widget is published in public/assets/altcha/, and in the version this code expects.
 * `extensions:configure` (Extension::install()) publishes it and writes VERSION_FILE.
 *
 * A deploy that skipped `extensions:configure` leaves the widget missing or outdated: the login
 * check then stands down instead of locking everybody out (LoginAltchaSubscriber), and the
 * dashboard shows a notice (AssetsNoticeWidget).
 */
final readonly class AssetsStatus
{
    public const VERSION_FILE = 'VERSION';

    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
        #[Autowire(param: 'bolt.public_folder')]
        private string $publicFolder,
    ) {
    }

    public static function directory(string $projectDir, string $publicFolder): string
    {
        return $projectDir . '/' . trim($publicFolder, '/') . WidgetRenderer::ASSETS_PATH;
    }

    public function publishedVersion(): ?string
    {
        $file = self::directory($this->projectDir, $this->publicFolder) . '/' . self::VERSION_FILE;
        if (! is_file($file)) {
            return null;
        }

        $version = trim((string) file_get_contents($file));

        return $version === '' ? null : $version;
    }

    public function isPublished(): bool
    {
        return $this->publishedVersion() !== null;
    }

    public function isCurrent(): bool
    {
        return $this->publishedVersion() === WidgetRenderer::ASSETS_VERSION;
    }
}

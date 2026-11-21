<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Tests\Assets;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Tomvondracek\Altcha\Assets\ServicesStatus;
use Tomvondracek\Altcha\Tests\AltchaTestTrait;

/**
 * After an update without `extensions:configure`, the project keeps the old copy of
 * config/services.yaml (and its old wiring): the dashboard says so.
 */
final class ServicesStatusTest extends TestCase
{
    use AltchaTestTrait;

    public function testTheCopyIsNamedLikeBoltNamesIt(): void
    {
        self::assertSame('/site/config/packages/extension_' . basename(dirname(__DIR__, 2)) . '.yaml', ServicesStatus::copyPath('/site'));
    }

    public function testACurrentCopyIsNotOutdated(): void
    {
        $projectDir = $this->tempDir();
        (new Filesystem())->copy(dirname(__DIR__, 2) . '/config/services.yaml', ServicesStatus::copyPath($projectDir));

        self::assertFalse(ServicesStatus::isOutdated($projectDir));
    }

    public function testAnOlderCopyIsOutdated(): void
    {
        $projectDir = $this->tempDir();
        (new Filesystem())->dumpFile(ServicesStatus::copyPath($projectDir), "services:\n  bolt_altcha.replay_pool: ~\n");

        self::assertTrue(ServicesStatus::isOutdated($projectDir));
    }

    /**
     * Before the first `extensions:configure` there is no copy: AssetsStatus reports that.
     */
    public function testNoCopyIsNotOutdated(): void
    {
        self::assertFalse(ServicesStatus::isOutdated($this->tempDir()));
    }
}

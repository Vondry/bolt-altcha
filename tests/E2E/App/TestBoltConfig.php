<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Tests\E2E\App;

use Bolt\Configuration\Config;

/**
 * Bolt's Config, reduced to what the extension reads: the `extensions_config` path.
 */
final class TestBoltConfig extends Config
{
    /**
     * Bolt's constructor parses the whole Bolt configuration; nothing of it is needed here.
     *
     * @phpstan-ignore constructor.missingParentCall (a stand-in: the parent needs a Bolt project)
     */
    public function __construct(
        private readonly string $extensionsConfigDir,
    ) {
    }

    public function getPath(string $path, bool $absolute = true, mixed $additional = null): string
    {
        return $this->extensionsConfigDir;
    }
}

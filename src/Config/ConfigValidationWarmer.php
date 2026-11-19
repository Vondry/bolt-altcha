<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Config;

use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;
use Tomvondracek\Altcha\Challenge\HmacKeyProvider;

/**
 * Validates the configuration while the cache is warmed (`cache:clear`, `cache:warmup`, Bolt's
 * "Clear the cache"), and the signing key as soon as the config protects something, so a mistake
 * fails the deploy instead of quietly switching the protection off at runtime (Protection).
 *
 * The key is not checked while nothing is protected: right after `composer require`, on a site
 * that still has the placeholder APP_SECRET, Composer's `cache:clear` must not fail.
 */
final readonly class ConfigValidationWarmer implements CacheWarmerInterface
{
    public function __construct(
        private AltchaConfigLoader $configLoader,
        private HmacKeyProvider $keys,
    ) {
    }

    public function isOptional(): bool
    {
        return false;
    }

    /**
     * @return list<string>
     */
    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        $this->configLoader->reset();
        $config = $this->configLoader->load();
        if ($config->enabled && ($config->loginProfile !== null || $config->forms !== [] || $config->routes !== [])) {
            $this->keys->signatureKey();
        }

        return [];
    }
}

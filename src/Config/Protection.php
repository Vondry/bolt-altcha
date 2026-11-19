<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Config;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Service\ResetInterface;
use Tomvondracek\Altcha\Challenge\HmacKeyProvider;

/**
 * Decides whether the protection that runs on its own is on: the login check, the field added to
 * configured forms, the route guard and #[RequireAltcha].
 *
 * Off when `enabled: false`, and also when the configuration is invalid or no strong signing key
 * is set. Those two are logged as critical (once per request) and do not block anything: an
 * invalid file must not turn every form and the admin login into an error page, and with a weak
 * key anyone could sign challenges anyway. ConfigValidationWarmer makes deploys fail on both.
 */
final class Protection implements ResetInterface
{
    private bool $keyErrorLogged = false;

    public function __construct(
        private readonly AltchaConfigLoader $configLoader,
        private readonly HmacKeyProvider $keys,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * The config while the protection is on, else null.
     */
    public function activeConfig(): ?AltchaConfig
    {
        $config = $this->configLoader->tryLoad();
        if ($config === null || ! $config->enabled) {
            return null;
        }

        if (! $this->keys->isUsable()) {
            if (! $this->keyErrorLogged) {
                $this->keyErrorLogged = true;
                $this->logger->critical('ALTCHA is not active: set ALTCHA_HMAC_KEY (at least 32 random characters), or a strong APP_SECRET.');
            }

            return null;
        }

        return $config;
    }

    public function reset(): void
    {
        $this->keyErrorLogged = false;
    }
}

<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Config;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Service\ResetInterface;
use Tomvondracek\Altcha\Assets\AssetsStatus;
use Tomvondracek\Altcha\Challenge\HmacKeyProvider;
use Tomvondracek\Altcha\Log\LogThrottle;

/**
 * Decides whether the protection that runs on its own is on: the login check, the field added to
 * configured forms, the route guard, #[RequireAltcha] and the challenge endpoint.
 *
 * Off when `enabled: false`, and also when the configuration is invalid, no strong signing key is
 * set, or the widget is not published (public/assets/altcha, `extensions:configure`). Those are
 * logged as critical and don't block anything: an invalid file must not turn every form and the
 * admin login into an error page, with a weak key anyone could sign challenges anyway, and
 * without the widget no visitor could solve one. ConfigValidationWarmer makes deploys fail on the
 * config and the key; the dashboard shows a notice about the widget.
 *
 * Each problem is logged once per request, and with a LogThrottle once per LogThrottle::WINDOW.
 */
final class Protection implements ResetInterface
{
    /** @var array<string, true> */
    private array $logged = [];

    private ?bool $published = null;

    public function __construct(
        private readonly AltchaConfigLoader $configLoader,
        private readonly HmacKeyProvider $keys,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?AssetsStatus $assets = null,
        private readonly ?LogThrottle $logThrottle = null,
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
            $this->critical('key', 'ALTCHA is not active: set ALTCHA_HMAC_KEY (at least 32 random characters), or a strong APP_SECRET.');

            return null;
        }

        $this->published ??= $this->assets?->isPublished() ?? true;
        if (! $this->published) {
            $this->critical('assets', 'ALTCHA is not active: the widget is not published in public/assets/altcha. Run `bin/console extensions:configure`.');

            return null;
        }

        return $config;
    }

    public function reset(): void
    {
        $this->logged = [];
        $this->published = null;
    }

    private function critical(string $id, string $message): void
    {
        if (isset($this->logged[$id])) {
            return;
        }

        $this->logged[$id] = true;
        if ($this->logThrottle?->allow('protection.' . $id) ?? true) {
            $this->logger->critical($message);
        }
    }
}

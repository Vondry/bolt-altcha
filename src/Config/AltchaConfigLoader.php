<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Config;

use Bolt\Configuration\Config;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Service\ResetInterface;
use Tomvondracek\Altcha\Exception\InvalidConfigurationException;

/**
 * Loads the extension config for services (they can't use `BaseExtension::getConfig()`, which
 * derives the filename from the calling class's namespace).
 *
 * Same resolution as Bolt's `ConfigTrait`: the project's
 * `config/extensions/tomvondracek-altcha.yaml`, overridden by `…_local.yaml`, falling back to the
 * defaults shipped with the package. Loaded once per request (reset between requests of a
 * long-running worker).
 *
 * Two ways to read it:
 *   - load(): throws on an invalid config. For code that uses the extension on purpose (a
 *     Twig function, the form field, the Verifier): the mistake must surface.
 *   - tryLoad(): logs the error once (critical) and returns null. For the hooks that run on every
 *     request (every form, every POST, the login page): one typo must not take down all forms and
 *     the admin login. ConfigValidationWarmer makes deploys fail on the same error instead.
 */
final class AltchaConfigLoader implements ResetInterface
{
    public const CONFIG_BASENAME = 'tomvondracek-altcha';

    private ?AltchaConfig $config = null;
    private ?InvalidConfigurationException $error = null;
    private bool $errorLogged = false;

    public function __construct(
        private readonly Config $boltConfig,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @throws InvalidConfigurationException
     */
    public function load(): AltchaConfig
    {
        if ($this->config instanceof AltchaConfig) {
            return $this->config;
        }

        if ($this->error instanceof InvalidConfigurationException) {
            throw $this->error;
        }

        try {
            return $this->config = AltchaConfig::fromArray(self::parseFiles($this->files()));
        } catch (InvalidConfigurationException $exception) {
            throw $this->error = $exception;
        } catch (ParseException $exception) {
            throw $this->error = new InvalidConfigurationException(
                'ALTCHA configuration (tomvondracek-altcha.yaml) is not valid YAML: ' . $exception->getMessage(),
                0,
                $exception
            );
        }
    }

    /**
     * The config, or null when it is invalid (logged once per request).
     */
    public function tryLoad(): ?AltchaConfig
    {
        try {
            return $this->load();
        } catch (InvalidConfigurationException $exception) {
            if (! $this->errorLogged) {
                $this->errorLogged = true;
                $this->logger->critical('ALTCHA is not active, its configuration is invalid: {message}', [
                    'message' => $exception->getMessage(),
                    'exception' => $exception,
                ]);
            }

            return null;
        }
    }

    public function reset(): void
    {
        $this->config = null;
        $this->error = null;
        $this->errorLogged = false;
    }

    /**
     * @internal
     */
    public static function defaultConfigFile(): string
    {
        return dirname(__DIR__, 2) . '/config/config.yaml';
    }

    /**
     * Later files override earlier ones key by key; lists are replaced, not appended.
     *
     * @internal
     *
     * @param list<string> $files
     *
     * @return array<array-key, mixed>
     */
    public static function parseFiles(array $files): array
    {
        $config = [];

        foreach ($files as $file) {
            if (! is_readable($file)) {
                continue;
            }

            $parsed = Yaml::parseFile($file);
            if (is_array($parsed)) {
                $config = self::merge($config, $parsed);
            }
        }

        return $config;
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $directory = $this->boltConfig->getPath('extensions_config');
        $main = $directory . DIRECTORY_SEPARATOR . self::CONFIG_BASENAME . '.yaml';
        $local = $directory . DIRECTORY_SEPARATOR . self::CONFIG_BASENAME . '_local.yaml';

        return is_readable($main) ? [$main, $local] : [self::defaultConfigFile(), $local];
    }

    /**
     * @param array<array-key, mixed> $base
     * @param array<array-key, mixed> $override
     *
     * @return array<array-key, mixed>
     */
    private static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && ! array_is_list($value)) {
                $base[$key] = self::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}

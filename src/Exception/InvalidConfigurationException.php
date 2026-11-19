<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Exception;

use InvalidArgumentException;

/**
 * config/extensions/tomvondracek-altcha.yaml contains a value the extension can't use. The
 * message names the key, e.g. `profiles.login.challenge.cost`.
 */
final class InvalidConfigurationException extends InvalidArgumentException
{
    public static function at(string $path, string $problem): self
    {
        return new self(sprintf('ALTCHA configuration (tomvondracek-altcha.yaml), "%s": %s', $path, $problem));
    }
}

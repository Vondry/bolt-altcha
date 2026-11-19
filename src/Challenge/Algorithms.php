<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Challenge;

use AltchaOrg\Altcha\Algorithm\DeriveKeyInterface;
use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Algorithm\Sha;
use AltchaOrg\Altcha\Algorithm\ShaAlgorithm;
use AltchaOrg\Altcha\HmacAlgorithm;
use InvalidArgumentException;
use Tomvondracek\Altcha\Config\ChallengeSettings;

/**
 * Maps the algorithm names of the ALTCHA protocol (ChallengeSettings::ALGORITHMS) to the
 * library's key derivation classes.
 */
final class Algorithms
{
    public static function create(string $name): DeriveKeyInterface
    {
        return match ($name) {
            'PBKDF2/SHA-256' => new Pbkdf2(HmacAlgorithm::SHA256),
            'PBKDF2/SHA-384' => new Pbkdf2(HmacAlgorithm::SHA384),
            'PBKDF2/SHA-512' => new Pbkdf2(HmacAlgorithm::SHA512),
            'SHA-256' => new Sha(ShaAlgorithm::SHA256),
            'SHA-384' => new Sha(ShaAlgorithm::SHA384),
            'SHA-512' => new Sha(ShaAlgorithm::SHA512),
            default => throw new InvalidArgumentException(sprintf(
                'Unsupported ALTCHA algorithm "%s". Supported: %s.',
                $name,
                implode(', ', ChallengeSettings::ALGORITHMS)
            )),
        };
    }
}

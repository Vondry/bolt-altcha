<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Config;

use Tomvondracek\Altcha\Exception\InvalidConfigurationException;

/**
 * The options a challenge is created with. They map to altcha-org/altcha's CreateChallengeOptions:
 * the browser derives a key with `algorithm` and `cost` for every counter value from 0 until it
 * finds the secret counter the server picked between `counter_min` and `counter_max`. The work is
 * therefore about cost × counter key derivations.
 */
final readonly class ChallengeSettings
{
    /**
     * The algorithms the bundled widget workers (assets/altcha/workers/) can solve. ARGON2ID and
     * SCRYPT would need extra workers and PHP extensions.
     */
    public const ALGORITHMS = [
        'PBKDF2/SHA-256',
        'PBKDF2/SHA-384',
        'PBKDF2/SHA-512',
        'SHA-256',
        'SHA-384',
        'SHA-512',
    ];

    /**
     * Creating a challenge derives one key at `cost` on the server, for every anonymous request to
     * the challenge endpoint: the cost stays cheap enough for that (PBKDF2/SHA-256 at 100,000 is
     * ~15 ms). The visitor's work is cost × counter.
     */
    public const MAX_COST = 100_000;

    public const MAX_COUNTER = 1_000_000;

    /**
     * A day: a solved challenge is remembered (ReplayStore) as long as it is valid.
     */
    public const MAX_EXPIRES = 86_400;

    private const KEYS = ['algorithm', 'cost', 'counter_min', 'counter_max', 'key_length', 'expires'];

    public function __construct(
        public string $algorithm = 'PBKDF2/SHA-256',
        public int $cost = 1000,
        public int $counterMin = 1000,
        public int $counterMax = 5000,
        public int $keyLength = 32,
        public int $expires = 600,
    ) {
    }

    /**
     * @param array<array-key, mixed> $raw     the `challenge` map of the config, snake_case keys
     * @param string                  $path    where the map is in the config, for error messages
     */
    public static function fromArray(array $raw, self $defaults, string $path): self
    {
        foreach (array_keys($raw) as $key) {
            if (! in_array($key, self::KEYS, true)) {
                throw InvalidConfigurationException::at(
                    $path . '.' . $key,
                    'unknown challenge option. Known: ' . implode(', ', self::KEYS) . '.'
                );
            }
        }

        $algorithm = $raw['algorithm'] ?? $defaults->algorithm;
        if (! is_string($algorithm) || ! in_array($algorithm, self::ALGORITHMS, true)) {
            throw InvalidConfigurationException::at(
                $path . '.algorithm',
                'must be one of ' . implode(', ', self::ALGORITHMS) . '.'
            );
        }

        $settings = new self(
            algorithm: $algorithm,
            cost: self::int($raw, 'cost', $defaults->cost, 1, self::MAX_COST, $path),
            counterMin: self::int($raw, 'counter_min', $defaults->counterMin, 1, self::MAX_COUNTER, $path),
            counterMax: self::int($raw, 'counter_max', $defaults->counterMax, 1, self::MAX_COUNTER, $path),
            keyLength: self::int($raw, 'key_length', $defaults->keyLength, 16, 64, $path),
            expires: self::int($raw, 'expires', $defaults->expires, 30, self::MAX_EXPIRES, $path),
        );

        if ($settings->counterMin > $settings->counterMax) {
            throw InvalidConfigurationException::at($path . '.counter_min', 'must not be greater than counter_max.');
        }

        // The library splits the key in half for the prefix it compares.
        if ($settings->keyLength % 2 !== 0) {
            throw InvalidConfigurationException::at($path . '.key_length', 'must be an even number of bytes.');
        }

        return $settings;
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private static function int(array $raw, string $key, int $default, int $min, int $max, string $path): int
    {
        $value = $raw[$key] ?? $default;
        if (! is_int($value) || $value < $min || $value > $max) {
            throw InvalidConfigurationException::at($path . '.' . $key, sprintf('must be a whole number from %d to %d.', $min, $max));
        }

        return $value;
    }
}

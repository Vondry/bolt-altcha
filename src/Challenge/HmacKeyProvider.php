<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Challenge;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Tomvondracek\Altcha\Exception\InvalidConfigurationException;

/**
 * The secrets that sign challenges. The master key is ALTCHA_HMAC_KEY, read the way Symfony reads
 * every environment variable (.env, .env.local, the real environment, or the secrets vault:
 * `bin/console secrets:set ALTCHA_HMAC_KEY`). Without it, the master key is derived from
 * APP_SECRET. The two keys the library uses are derived from the master key with different labels,
 * so neither ALTCHA_HMAC_KEY nor APP_SECRET is ever used directly.
 *
 * Anyone who knows the master key can sign challenges with the solution already in them, which
 * turns the protection off. So both sources must be long, and APP_SECRET must not be one of the
 * placeholders Symfony and Bolt ship in their .env.
 */
final readonly class HmacKeyProvider
{
    public const ENV = 'ALTCHA_HMAC_KEY';
    public const MIN_KEY_LENGTH = 32;

    /**
     * Placeholders from the .env of bolt/project and of Symfony's skeletons and documentation.
     */
    public const KNOWN_PLACEHOLDERS = ['!ChangeMe!', 'ThisTokenIsNotSoSecretChangeIt', 'ThisIsNotSoSecretChangeIt', 'changeme', 'ChangeMe', 'secret'];

    public function __construct(
        #[Autowire(param: 'kernel.secret')]
        private string $appSecret,
        #[Autowire(env: 'default::' . self::ENV)]
        private ?string $hmacKey = null,
    ) {
    }

    /**
     * Signs the challenge parameters (`signature`).
     */
    public function signatureKey(): string
    {
        return hash_hmac('sha256', 'bolt-altcha:challenge-signature', $this->masterKey());
    }

    /**
     * Signs the derived key of the secret counter (`keySignature`), so a solution is verified with
     * HMACs only, never with a second key derivation.
     */
    public function keySignatureKey(): string
    {
        return hash_hmac('sha256', 'bolt-altcha:key-signature', $this->masterKey());
    }

    /**
     * Whether a strong key is available; signatureKey() and keySignatureKey() throw otherwise.
     */
    public function isUsable(): bool
    {
        try {
            $this->masterKey();

            return true;
        } catch (InvalidConfigurationException) {
            return false;
        }
    }

    private function masterKey(): string
    {
        if ($this->hmacKey !== null && $this->hmacKey !== '') {
            self::assertStrong($this->hmacKey, sprintf('the environment variable %s', self::ENV));

            return $this->hmacKey;
        }

        self::assertStrong($this->appSecret, sprintf('APP_SECRET, from which the key is derived while %s is not set,', self::ENV));

        return hash_hmac('sha256', 'bolt-altcha', $this->appSecret);
    }

    private static function assertStrong(string $secret, string $what): void
    {
        if (mb_strlen($secret) < self::MIN_KEY_LENGTH || in_array($secret, self::KNOWN_PLACEHOLDERS, true)) {
            throw new InvalidConfigurationException(sprintf(
                'ALTCHA: %s must be a random value of at least %d characters (e.g. `openssl rand -hex 32`); a short or placeholder secret would let anyone sign challenges. Set %s in .env.local.',
                $what,
                self::MIN_KEY_LENGTH,
                self::ENV
            ));
        }
    }
}

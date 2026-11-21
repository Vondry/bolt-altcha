<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Challenge;

use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Challenge;
use AltchaOrg\Altcha\CreateChallengeOptions;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Tomvondracek\Altcha\Config\Profile;

/**
 * Creates signed challenges. Every challenge carries the profile name in its signed `data`, an
 * expiry, and a `keySignature`, so verifying a solution costs two HMACs and no key derivation.
 */
final readonly class ChallengeFactory
{
    public function __construct(
        private HmacKeyProvider $keys,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param int $difficulty multiplies the counter range (`counter_min`, `counter_max`), and so the
     *                        visitor's work; the server's work stays one derivation at `cost`
     */
    public function create(Profile $profile, int $difficulty = 1): Challenge
    {
        if ($difficulty < 1) {
            throw new InvalidArgumentException(sprintf('The difficulty must be at least 1, %d given.', $difficulty));
        }

        $settings = $profile->challenge;

        return $this->altcha()->createChallenge(new CreateChallengeOptions(
            algorithm: Algorithms::create($settings->algorithm),
            cost: $settings->cost,
            keyLength: $settings->keyLength,
            counter: random_int($settings->counterMin * $difficulty, $settings->counterMax * $difficulty),
            expiresAt: $this->clock->now()->getTimestamp() + $settings->expires,
            data: [
                'profile' => $profile->name,
            ],
        ));
    }

    /**
     * The library object configured with this site's keys; shared with the Verifier so both sides
     * always use the same secrets.
     */
    public function altcha(): Altcha
    {
        return new Altcha(
            hmacSignatureSecret: $this->keys->signatureKey(),
            hmacKeySignatureSecret: $this->keys->keySignatureKey(),
        );
    }
}

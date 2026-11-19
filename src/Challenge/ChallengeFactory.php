<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Challenge;

use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Challenge;
use AltchaOrg\Altcha\CreateChallengeOptions;
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

    public function create(Profile $profile): Challenge
    {
        $settings = $profile->challenge;

        return $this->altcha()->createChallenge(new CreateChallengeOptions(
            algorithm: Algorithms::create($settings->algorithm),
            cost: $settings->cost,
            keyLength: $settings->keyLength,
            counter: random_int($settings->counterMin, $settings->counterMax),
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

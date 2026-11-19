<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Verification;

use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\VerifySolutionOptions;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Tomvondracek\Altcha\Challenge\Algorithms;
use Tomvondracek\Altcha\Challenge\ChallengeFactory;
use Tomvondracek\Altcha\Challenge\ReplayStore;
use Tomvondracek\Altcha\Config\AltchaConfigLoader;

/**
 * Checks a payload the widget submitted, in this order: present and well-formed, made with the
 * profile's algorithm, not expired, signed by this site, solved, issued for this profile, and not
 * used before. Every failure is logged at `notice` level with its status, nothing else (no IP, no
 * form data).
 *
 * Use it from your own code:
 *
 *     $result = $verifier->verifyRequest($request, 'newsletter');
 *     if (! $result->isValid()) { … }
 */
final readonly class Verifier
{
    /**
     * A real payload is well under 1 kB; anything much longer is rejected before decoding.
     */
    public const MAX_PAYLOAD_LENGTH = 8192;

    public function __construct(
        private AltchaConfigLoader $configLoader,
        private ChallengeFactory $challengeFactory,
        private ReplayStore $replayStore,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param mixed $payload the submitted value of the widget's field (a base64 string)
     *
     * @throws InvalidArgumentException when the profile does not exist (a programming or
     *                                  configuration error, not a visitor's)
     */
    public function verify(mixed $payload, string $profile): VerificationResult
    {
        $config = $this->configLoader->load();
        if (! $config->enabled) {
            return new VerificationResult(Status::Disabled, $profile);
        }

        $settings = $config->profile($profile) ?? throw new InvalidArgumentException(sprintf(
            'Unknown ALTCHA profile "%s". Define it under `profiles` in tomvondracek-altcha.yaml.',
            $profile
        ));

        return $this->log(new VerificationResult($this->check($payload, $settings->name, $settings->challenge->algorithm), $profile));
    }

    /**
     * Verifies the field `field_name` (default `altcha`) of a POSTed form, the way the widget
     * submits it when it is not part of a Symfony form.
     */
    public function verifyRequest(Request $request, string $profile): VerificationResult
    {
        $fieldName = $this->configLoader->load()->fieldName;

        return $this->verify($request->request->all()[$fieldName] ?? null, $profile);
    }

    private function check(mixed $payload, string $profile, string $algorithm): Status
    {
        if (! is_string($payload) || trim($payload) === '') {
            return Status::Missing;
        }

        // A byte offset: isset() looks at bytes, so multibyte input can't slip past the limit.
        if (isset($payload[self::MAX_PAYLOAD_LENGTH])) {
            return Status::Malformed;
        }

        try {
            $decoded = Payload::fromBase64(trim($payload));
        } catch (InvalidArgumentException) {
            return Status::Malformed;
        }

        $parameters = $decoded->challenge->parameters;

        // The parameters are signed, so a challenge of another algorithm was issued under an
        // older configuration. Checked first, so no other algorithm's code ever runs on input.
        if ($parameters->algorithm !== $algorithm) {
            return Status::Malformed;
        }

        $result = $this->challengeFactory->altcha()->verifySolution(
            new VerifySolutionOptions($decoded, Algorithms::create($algorithm))
        );

        if (! $result->verified) {
            return $result->expired ? Status::Expired : Status::Invalid;
        }

        // `data` is covered by the signature verified above.
        if (($parameters->data['profile'] ?? null) !== $profile) {
            return Status::WrongProfile;
        }

        if (! $this->replayStore->consume((string) $decoded->challenge->signature, $parameters->expiresAt)) {
            return Status::Replayed;
        }

        return Status::Valid;
    }

    private function log(VerificationResult $result): VerificationResult
    {
        if (! $result->isValid()) {
            $this->logger->notice('ALTCHA verification failed: {status} (profile "{profile}").', [
                'status' => $result->status->value,
                'profile' => $result->profile,
            ]);
        }

        return $result;
    }
}

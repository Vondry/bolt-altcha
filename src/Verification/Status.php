<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Verification;

/**
 * Why a payload passed or failed. Only for logs and code: visitors always see the same generic
 * message, so a bot learns nothing from it.
 */
enum Status: string
{
    case Valid = 'valid';

    /**
     * `enabled: false` in the config: everything passes.
     */
    case Disabled = 'disabled';

    /**
     * No payload: JavaScript off, the widget not on the page, or a bot posting the form directly.
     */
    case Missing = 'missing';

    /**
     * Not base64 JSON of a challenge and a solution, too long, or for another algorithm.
     */
    case Malformed = 'malformed';

    /**
     * Past its expiry. The library checks the expiry before the signature, so a forged payload
     * with a past expiry also ends up here.
     */
    case Expired = 'expired';

    /**
     * A wrong signature (forged or from another site) or a wrong solution.
     */
    case Invalid = 'invalid';

    /**
     * A valid solution of a challenge issued for another profile.
     */
    case WrongProfile = 'wrong_profile';

    /**
     * A valid solution that was already used.
     */
    case Replayed = 'replayed';

    public function isValid(): bool
    {
        return $this === self::Valid || $this === self::Disabled;
    }
}

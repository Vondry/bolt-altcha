<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Config;

/**
 * A named set of challenge options and widget attributes. The name is signed into every challenge
 * (`data.profile`), so a solution only verifies for the profile it was issued for: one solved for
 * the newsletter is useless on the login.
 */
final readonly class Profile
{
    /**
     * Allowed profile names; also the requirement of the challenge route's {profile}.
     */
    public const NAME_PATTERN = '[a-z0-9][a-z0-9_-]{0,63}';

    /**
     * @param array<string, mixed> $widget <altcha-widget> attributes, verbatim (see WidgetAttributes)
     */
    public function __construct(
        public string $name,
        public ChallengeSettings $challenge,
        public array $widget,
    ) {
    }

    public static function isValidName(string $name): bool
    {
        return preg_match('/\A' . self::NAME_PATTERN . '\z/', $name) === 1;
    }
}

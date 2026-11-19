<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Config;

use Tomvondracek\Altcha\Exception\InvalidConfigurationException;

/**
 * The validated contents of config/extensions/tomvondracek-altcha.yaml. Every key is optional;
 * config/config.yaml in the package documents them all.
 */
final readonly class AltchaConfig
{
    public const DEFAULT_PROFILE = 'default';

    /**
     * Bolt's own Symfony forms that `bolt:` can protect, by their form name. The login is not a
     * Symfony form submit (Bolt's authenticator reads the request) and is handled separately.
     */
    public const BOLT_FORMS = [
        'reset_password' => 'reset_password_request_form',
        'change_password' => 'change_password_form',
    ];

    /**
     * Challenges one IP address may fetch per minute.
     */
    public const DEFAULT_CHALLENGE_RATE_LIMIT = 60;

    public const ON_FAILURE_REJECT = 'reject';
    public const ON_FAILURE_REDIRECT_BACK = 'redirect_back';

    /**
     * Widget attributes the extension sets itself.
     */
    public const RESERVED_WIDGET_ATTRIBUTES = ['challenge', 'name'];

    private const KEYS = [
        'enabled', 'field_name', 'challenge', 'widget', 'profiles', 'bolt', 'forms', 'routes', 'on_route_failure',
        'challenge_rate_limit',
    ];

    /**
     * @param array<string, Profile> $profiles
     * @param array<string, string>  $forms    form name => profile
     * @param array<string, string>  $routes   route name => profile
     */
    public function __construct(
        public bool $enabled,
        public string $fieldName,
        public array $profiles,
        public ?string $loginProfile,
        public array $forms,
        public array $routes,
        public string $onRouteFailure,
        public int $challengeRateLimit = self::DEFAULT_CHALLENGE_RATE_LIMIT,
    ) {
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        foreach (array_keys($raw) as $key) {
            if (! in_array($key, self::KEYS, true)) {
                throw InvalidConfigurationException::at((string) $key, 'unknown option. Known: ' . implode(', ', self::KEYS) . '.');
            }
        }

        $enabled = $raw['enabled'] ?? true;
        if (! is_bool($enabled)) {
            throw InvalidConfigurationException::at('enabled', 'must be true or false.');
        }

        $fieldName = self::string($raw, 'field_name', 'altcha', '/\A[A-Za-z_][A-Za-z0-9_-]*\z/', 'a form field name of letters, digits, "_" and "-"');

        $profiles = self::profiles($raw);

        $bolt = self::map($raw, 'bolt');
        foreach (array_keys($bolt) as $key) {
            if ($key !== 'login' && ! array_key_exists($key, self::BOLT_FORMS)) {
                throw InvalidConfigurationException::at('bolt.' . $key, 'unknown Bolt form. Known: login, ' . implode(', ', array_keys(self::BOLT_FORMS)) . '.');
            }
        }

        $loginProfile = self::profileReference($bolt['login'] ?? false, 'login', 'bolt.login', $profiles);

        $forms = [];
        foreach (self::BOLT_FORMS as $key => $formName) {
            $profile = self::profileReference($bolt[$key] ?? false, $key, 'bolt.' . $key, $profiles);
            if ($profile !== null) {
                $forms[$formName] = $profile;
            }
        }

        foreach (self::map($raw, 'forms') as $formName => $reference) {
            $profile = self::profileReference($reference, self::DEFAULT_PROFILE, 'forms.' . $formName, $profiles);
            if ($profile !== null) {
                $forms[(string) $formName] = $profile;
            }
        }

        $routes = [];
        foreach (self::map($raw, 'routes') as $routeName => $reference) {
            $profile = self::profileReference($reference, self::DEFAULT_PROFILE, 'routes.' . $routeName, $profiles);
            if ($profile !== null) {
                $routes[(string) $routeName] = $profile;
            }
        }

        $onRouteFailure = $raw['on_route_failure'] ?? self::ON_FAILURE_REDIRECT_BACK;
        if (! in_array($onRouteFailure, [self::ON_FAILURE_REJECT, self::ON_FAILURE_REDIRECT_BACK], true)) {
            throw InvalidConfigurationException::at('on_route_failure', 'must be "reject" or "redirect_back".');
        }

        $challengeRateLimit = $raw['challenge_rate_limit'] ?? self::DEFAULT_CHALLENGE_RATE_LIMIT;
        if (! is_int($challengeRateLimit) || $challengeRateLimit < 0) {
            throw InvalidConfigurationException::at('challenge_rate_limit', 'must be a whole number of challenges per minute and IP address, or 0 for no limit.');
        }

        return new self($enabled, $fieldName, $profiles, $loginProfile, $forms, $routes, $onRouteFailure, $challengeRateLimit);
    }

    public function profile(string $name): ?Profile
    {
        return $this->profiles[$name] ?? null;
    }

    public function formProfile(string $formName): ?string
    {
        return $this->forms[$formName] ?? null;
    }

    public function routeProfile(string $routeName): ?string
    {
        return $this->routes[$routeName] ?? null;
    }

    /**
     * Two layers of widget attributes: the later wins, `null` removes an attribute, and the
     * `configuration` maps (the widget's JSON options) are merged key by key.
     *
     * @param array<array-key, mixed> $base
     * @param array<array-key, mixed> $override
     *
     * @return array<string, mixed>
     */
    public static function mergeWidget(array $base, array $override): array
    {
        $merged = [];
        foreach ($base as $key => $value) {
            $merged[(string) $key] = $value;
        }

        foreach ($override as $key => $value) {
            $key = (string) $key;
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key]) && ! array_is_list($value)) {
                $merged[$key] = self::mergeWidget($merged[$key], $value);
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * @param array<array-key, mixed> $raw
     *
     * @return array<string, Profile>
     */
    private static function profiles(array $raw): array
    {
        $challenge = ChallengeSettings::fromArray(self::map($raw, 'challenge'), new ChallengeSettings(), 'challenge');
        $widget = self::widget(self::map($raw, 'widget'), 'widget');

        $rawProfiles = self::map($raw, 'profiles');
        $rawProfiles += [self::DEFAULT_PROFILE => []];

        $profiles = [];
        foreach ($rawProfiles as $name => $profile) {
            $name = (string) $name;
            $path = 'profiles.' . $name;
            if (! Profile::isValidName($name)) {
                throw InvalidConfigurationException::at($path, 'a profile name is lowercase letters, digits, "_" and "-".');
            }

            $profile ??= [];
            if (! is_array($profile)) {
                throw InvalidConfigurationException::at($path, 'must be a map with `challenge` and/or `widget`.');
            }

            foreach (array_keys($profile) as $key) {
                if ($key !== 'challenge' && $key !== 'widget') {
                    throw InvalidConfigurationException::at($path . '.' . $key, 'a profile has only `challenge` and `widget`.');
                }
            }

            $profiles[$name] = new Profile(
                $name,
                ChallengeSettings::fromArray(self::map($profile, 'challenge', $path), $challenge, $path . '.challenge'),
                self::mergeWidget($widget, self::widget(self::map($profile, 'widget', $path), $path . '.widget')),
            );
        }

        return $profiles;
    }

    /**
     * @param array<array-key, mixed> $widget
     *
     * @return array<string, mixed>
     */
    private static function widget(array $widget, string $path): array
    {
        $attributes = [];
        foreach ($widget as $name => $value) {
            $name = (string) $name;
            if (in_array($name, self::RESERVED_WIDGET_ATTRIBUTES, true)) {
                throw InvalidConfigurationException::at(
                    $path . '.' . $name,
                    'is set by the extension (the challenge URL of the profile and the form field name). Use `field_name` for the field name.'
                );
            }

            if (preg_match('/\A[a-z][a-z0-9-]*\z/', $name) !== 1) {
                throw InvalidConfigurationException::at($path . '.' . $name, 'is not a valid HTML attribute name (lowercase, as in the ALTCHA docs).');
            }

            if ($name === 'configuration' && $value !== null && ! is_array($value)) {
                throw InvalidConfigurationException::at($path . '.configuration', 'must be a map of the widget\'s options (written in camelCase, as in the ALTCHA docs).');
            }

            $attributes[$name] = $value;
        }

        return $attributes;
    }

    /**
     * `false`/`null` = off, `true` = the profile named `$implicit` (or `default` when there is no
     * such profile), a string = that profile, which must exist.
     *
     * @param array<string, Profile> $profiles
     */
    private static function profileReference(mixed $value, string $implicit, string $path, array $profiles): ?string
    {
        if ($value === false || $value === null) {
            return null;
        }

        if ($value === true) {
            return isset($profiles[$implicit]) ? $implicit : self::DEFAULT_PROFILE;
        }

        if (! is_string($value) || ! isset($profiles[$value])) {
            throw InvalidConfigurationException::at(
                $path,
                'must be false, true or the name of a profile under `profiles` (' . implode(', ', array_keys($profiles)) . ').'
            );
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $raw
     *
     * @return array<array-key, mixed>
     */
    private static function map(array $raw, string $key, string $parent = ''): array
    {
        $value = $raw[$key] ?? [];
        if (! is_array($value)) {
            throw InvalidConfigurationException::at(ltrim($parent . '.' . $key, '.'), 'must be a map.');
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private static function string(array $raw, string $key, string $default, string $pattern, string $expected): string
    {
        $value = $raw[$key] ?? $default;
        if (! is_string($value) || preg_match($pattern, $value) !== 1) {
            throw InvalidConfigurationException::at($key, 'must be ' . $expected . '.');
        }

        return $value;
    }
}

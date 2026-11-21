<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Tests\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tomvondracek\Altcha\Config\AltchaConfig;
use Tomvondracek\Altcha\Config\AltchaConfigLoader;
use Tomvondracek\Altcha\Config\ChallengeSettings;
use Tomvondracek\Altcha\Exception\InvalidConfigurationException;

final class AltchaConfigTest extends TestCase
{
    public function testEmptyConfigHasSafeDefaults(): void
    {
        $config = AltchaConfig::fromArray([]);

        self::assertTrue($config->enabled);
        self::assertSame('altcha', $config->fieldName);
        self::assertSame(['default'], array_keys($config->profiles));
        self::assertEquals(new ChallengeSettings(), $config->profiles['default']->challenge);
        self::assertSame([], $config->profiles['default']->widget);
        self::assertNull($config->loginProfile);
        self::assertSame([], $config->forms);
        self::assertSame([], $config->routes);
        self::assertSame(AltchaConfig::ON_FAILURE_REDIRECT_BACK, $config->onRouteFailure);
        self::assertSame(60, $config->challengeRateLimit);
    }

    public function testChallengeRateLimitCanBeChangedOrSwitchedOff(): void
    {
        self::assertSame(10, AltchaConfig::fromArray([
            'challenge_rate_limit' => 10,
        ])->challengeRateLimit);
        self::assertSame(0, AltchaConfig::fromArray([
            'challenge_rate_limit' => 0,
        ])->challengeRateLimit);
    }

    /**
     * The file `extensions:configure --with-config` copies must be valid as shipped, and protect
     * nothing until the site owner switches it on.
     */
    public function testShippedConfigIsValidAndProtectsNothingYet(): void
    {
        $config = AltchaConfig::fromArray(AltchaConfigLoader::parseFiles([AltchaConfigLoader::defaultConfigFile()]));

        self::assertSame(['default', 'login'], array_keys($config->profiles));
        self::assertNull($config->loginProfile);
        self::assertSame([], $config->forms);
        self::assertSame([], $config->routes);
        self::assertSame('PBKDF2/SHA-256', $config->profiles['login']->challenge->algorithm);
        self::assertSame(2000, $config->profiles['login']->challenge->counterMin);
        self::assertSame(1000, $config->profiles['default']->challenge->counterMin);
        self::assertSame([
            'auto' => 'onsubmit',
            'display' => 'invisible',
            'configuration' => [
                'hideLogo' => true,
                'hideFooter' => true,
            ],
        ], $config->profiles['login']->widget);
    }

    public function testProfilesOverrideTheDefaultsKeyByKey(): void
    {
        $config = AltchaConfig::fromArray([
            'challenge' => [
                'cost' => 500,
                'expires' => 300,
            ],
            'widget' => [
                'auto' => 'onsubmit',
                'display' => 'floating',
                'configuration' => [
                    'hideLogo' => true,
                    'minDuration' => 500,
                ],
            ],
            'profiles' => [
                'newsletter' => [
                    'challenge' => [
                        'counter_max' => 9000,
                    ],
                    'widget' => [
                        'display' => null,
                        'type' => 'switch',
                        'configuration' => [
                            'minDuration' => 1000,
                        ],
                    ],
                ],
                'quiet' => null,
            ],
        ]);

        $newsletter = $config->profile('newsletter');
        self::assertNotNull($newsletter);
        self::assertSame(500, $newsletter->challenge->cost);
        self::assertSame(300, $newsletter->challenge->expires);
        self::assertSame(9000, $newsletter->challenge->counterMax);
        self::assertSame([
            'auto' => 'onsubmit',
            'display' => null,
            'configuration' => [
                'hideLogo' => true,
                'minDuration' => 1000,
            ],
            'type' => 'switch',
        ], $newsletter->widget);

        self::assertNotNull($config->profile('quiet'));
        self::assertNotNull($config->profile('default'), 'default always exists');
        self::assertNull($config->profile('missing'));
    }

    public function testBoltFormsFormsAndRoutesMapToProfiles(): void
    {
        $config = AltchaConfig::fromArray([
            'profiles' => [
                'login' => [],
                'strict' => [],
            ],
            'bolt' => [
                'login' => true,
                'reset_password' => 'strict',
                'change_password' => false,
            ],
            'forms' => [
                'contact' => true,
                'order' => 'strict',
                'off' => false,
            ],
            'routes' => [
                'newsletter_subscribe' => true,
                'ignored' => null,
            ],
            'on_route_failure' => 'reject',
        ]);

        self::assertSame('login', $config->loginProfile);
        self::assertSame('strict', $config->formProfile('reset_password_request_form'));
        self::assertNull($config->formProfile('change_password_form'));
        self::assertSame('default', $config->formProfile('contact'));
        self::assertSame('strict', $config->formProfile('order'));
        self::assertNull($config->formProfile('off'));
        self::assertSame('default', $config->routeProfile('newsletter_subscribe'));
        self::assertNull($config->routeProfile('ignored'));
        self::assertSame(AltchaConfig::ON_FAILURE_REJECT, $config->onRouteFailure);
    }

    public function testTrueFallsBackToDefaultWithoutAProfileOfTheSameName(): void
    {
        $config = AltchaConfig::fromArray([
            'bolt' => [
                'login' => true,
                'reset_password' => true,
            ],
        ]);

        self::assertSame('default', $config->loginProfile);
        self::assertSame('default', $config->formProfile('reset_password_request_form'));
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    #[DataProvider('invalidConfigs')]
    public function testInvalidConfigNamesTheKey(array $raw, string $path): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('"' . $path . '"');

        AltchaConfig::fromArray($raw);
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, string}>
     */
    public static function invalidConfigs(): iterable
    {
        yield 'unknown top-level key' => [['enable' => true], 'enable'];
        yield 'enabled not a boolean' => [['enabled' => 'yes'], 'enabled'];
        yield 'removed hmac_key_env' => [['hmac_key_env' => 'ALTCHA_HMAC_KEY'], 'hmac_key_env'];
        yield 'field name with a bracket' => [['field_name' => 'form[altcha]'], 'field_name'];
        yield 'challenge not a map' => [['challenge' => 'fast'], 'challenge'];
        yield 'unknown challenge option' => [['challenge' => ['max_number' => 5]], 'challenge.max_number'];
        yield 'unsupported algorithm' => [['challenge' => ['algorithm' => 'ARGON2ID']], 'challenge.algorithm'];
        yield 'cost zero' => [['challenge' => ['cost' => 0]], 'challenge.cost'];
        yield 'cost as a string' => [['challenge' => ['cost' => '1000']], 'challenge.cost'];
        yield 'counter range reversed' => [['challenge' => ['counter_min' => 10, 'counter_max' => 5]], 'challenge.counter_min'];
        yield 'key too short' => [['challenge' => ['key_length' => 8]], 'challenge.key_length'];
        yield 'key too long' => [['challenge' => ['key_length' => 65]], 'challenge.key_length'];
        yield 'expiry too short' => [['challenge' => ['expires' => 5]], 'challenge.expires'];
        yield 'expiry too long' => [['challenge' => ['expires' => 86_401]], 'challenge.expires'];
        yield 'cost too high for the server' => [['challenge' => ['cost' => 10_001]], 'challenge.cost'];
        yield 'counter too high' => [['challenge' => ['counter_max' => 1_000_001]], 'challenge.counter_max'];
        yield 'odd key length' => [['challenge' => ['key_length' => 17]], 'challenge.key_length'];
        yield 'negative rate limit' => [['challenge_rate_limit' => -1], 'challenge_rate_limit'];
        yield 'rate limit as text' => [['challenge_rate_limit' => '60/min'], 'challenge_rate_limit'];
        yield 'reserved widget attribute' => [['widget' => ['challenge' => '/x']], 'widget.challenge'];
        yield 'widget name set in config' => [['widget' => ['name' => 'x']], 'widget.name'];
        yield 'camelCase widget attribute' => [['widget' => ['hideLogo' => true]], 'widget.hideLogo'];
        yield 'configuration not a map' => [['widget' => ['configuration' => '{}']], 'widget.configuration'];
        yield 'bad profile name' => [['profiles' => ['Login' => []]], 'profiles.Login'];
        yield 'profile not a map' => [['profiles' => ['login' => 'strict']], 'profiles.login'];
        yield 'unknown profile key' => [['profiles' => ['login' => ['difficulty' => 3]]], 'profiles.login.difficulty'];
        yield 'profile challenge error' => [['profiles' => ['login' => ['challenge' => ['cost' => -1]]]], 'profiles.login.challenge.cost'];
        yield 'profile widget error' => [['profiles' => ['login' => ['widget' => ['name' => 'x']]]], 'profiles.login.widget.name'];
        yield 'unknown Bolt form' => [['bolt' => ['register' => true]], 'bolt.register'];
        yield 'unknown profile for login' => [['bolt' => ['login' => 'strict']], 'bolt.login'];
        yield 'number as profile' => [['forms' => ['contact' => 1]], 'forms.contact'];
        yield 'unknown profile for a route' => [['routes' => ['subscribe' => 'nope']], 'routes.subscribe'];
        yield 'Bolt\'s login under routes' => [['routes' => ['bolt_login' => true]], 'routes.bolt_login'];
        // Maps become JSON in the page: caught here, not while the login page renders.
        yield 'NaN in the widget configuration' => [['widget' => ['configuration' => ['floatingOffset' => NAN]]], 'widget.configuration'];
        yield 'infinity in a profile\'s widget configuration' => [['profiles' => ['login' => ['widget' => ['configuration' => ['x' => INF]]]]], 'profiles.login.widget.configuration'];
        yield 'binary bytes in the widget configuration' => [['widget' => ['configuration' => ['x' => "\xff\xfe"]]], 'widget.configuration'];
        yield 'NaN as a widget attribute' => [['widget' => ['workers' => NAN]], 'widget.workers'];
        yield 'unknown failure mode' => [['on_route_failure' => 'ignore'], 'on_route_failure'];
    }

    public function testMergeWidgetReplacesListsAndScalars(): void
    {
        self::assertSame(
            [
                'a' => [3],
                'b' => 'x',
                'c' => [
                    'd' => 1,
                    'e' => 2,
                ],
            ],
            AltchaConfig::mergeWidget(
                [
                    'a' => [1, 2],
                    'b' => [
                        'nested' => true,
                    ],
                    'c' => [
                        'd' => 1,
                    ],
                ],
                [
                    'a' => [3],
                    'b' => 'x',
                    'c' => [
                        'e' => 2,
                    ],
                ],
            )
        );
    }
}

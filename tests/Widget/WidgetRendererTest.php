<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Tests\Widget;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use stdClass;
use Stringable;
use Symfony\Component\HttpFoundation\Request;
use Tomvondracek\Altcha\Tests\AltchaTestTrait;
use Tomvondracek\Altcha\Widget\WidgetRenderer;
use Tomvondracek\Altcha\Widget\WidgetTexts;

final class WidgetRendererTest extends TestCase
{
    use AltchaTestTrait;

    public function testWidgetHasTheProfileAttributesAndTheChallengeUrl(): void
    {
        $html = $this->renderer([
            'widget' => [
                'auto' => 'onsubmit',
                'display' => 'floating',
                'configuration' => [
                    'hideLogo' => true,
                ],
            ],
            'profiles' => [
                'newsletter' => [
                    'widget' => [
                        'type' => 'switch',
                    ],
                ],
            ],
        ])->render('newsletter');

        self::assertStringEndsWith(
            '<altcha-widget challenge="/altcha/challenge/newsletter" name="altcha" language="en" auto="onsubmit" display="floating" configuration="{&quot;hideLogo&quot;:true}" type="switch"></altcha-widget>',
            $html
        );
    }

    public function testCallerAttributesOverrideTheProfile(): void
    {
        $html = $this->renderer([
            'widget' => [
                'auto' => 'onsubmit',
                'display' => 'floating',
                'configuration' => [
                    'hideLogo' => true,
                    'minDuration' => 500,
                ],
            ],
        ])->render('default', [
            'auto' => 'onload',
            'display' => null,
            'workers' => 2,
            'debug' => true,
            'test' => false,
            'configuration' => [
                'minDuration' => 1000,
            ],
            'language' => 'de',
        ], 'contact[altcha]');

        self::assertStringEndsWith(
            '<altcha-widget challenge="/altcha/challenge/default" name="contact[altcha]" language="de" auto="onload" configuration="{&quot;hideLogo&quot;:true,&quot;minDuration&quot;:1000}" workers="2" debug></altcha-widget>',
            $html
        );
    }

    public function testValuesAreEscaped(): void
    {
        $html = $this->renderer()
            ->render('default', [
                'theme' => '"><script>alert(1)</script>',
                'configuration' => [
                    'label' => '</altcha-widget><img src=x onerror=alert(1)>',
                ],
            ]);

        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('theme="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"', $html);
    }

    public function testScriptsArePrintedOncePerPage(): void
    {
        $renderer = $this->renderer();

        self::assertSame(
            '<link rel="stylesheet" href="/assets/altcha/altcha.css?v=' . WidgetRenderer::ASSETS_VERSION . '">'
            . '<script type="module" src="/assets/altcha/bolt-altcha.js?v=' . WidgetRenderer::ASSETS_VERSION . '"></script>',
            $renderer->scripts()
        );
        self::assertSame('', $renderer->scripts());
        self::assertStringNotContainsString('<link', $renderer->render('default'));
    }

    public function testAssetsFollowTheBasePath(): void
    {
        $request = Request::create('http://example.com/site/contact', server: [
            'SCRIPT_FILENAME' => '/var/www/site/index.php',
            'SCRIPT_NAME' => '/site/index.php',
        ]);

        self::assertStringContainsString('href="/site/assets/altcha/altcha.css?', $this->renderer([], $request)->scripts());
    }

    public function testTextsFollowThePageLocale(): void
    {
        $html = $this->renderer([], $this->request('cs'))
            ->render('default');

        self::assertStringContainsString(' language="cs"', $html);
        $texts = self::texts($html, 'cs');
        self::assertSame(array_keys(WidgetTexts::MESSAGES), array_keys($texts));
        self::assertSame('Nejsem robot', $texts['label']);
        self::assertSame('Ověřování...', $texts['verifying']);
    }

    public function testRegionalLocalesUseTheirOwnTexts(): void
    {
        $html = $this->renderer([], $this->request('pt_BR'))
            ->render('default');

        self::assertStringContainsString(' language="pt-br"', $html);
        self::assertSame('Eu não sou um robô', self::texts($html, 'pt-br')['label']);
    }

    public function testLanguageAttributeTranslatesIntoThatLanguage(): void
    {
        $html = $this->renderer([], $this->request('cs'))
            ->render('default', [
                'language' => 'de',
            ]);

        self::assertStringContainsString(' language="de"', $html);
        self::assertSame('Ich bin kein Roboter', self::texts($html, 'de')['label']);
    }

    public function testTextsArePrintedOncePerPageAndLanguage(): void
    {
        $renderer = $this->renderer([], $this->request('cs'));

        $first = $renderer->render('default');
        $second = $renderer->render('default');
        $german = $renderer->render('default', [
            'language' => 'de',
        ]);

        self::assertStringContainsString('data-bolt-altcha-i18n="cs"', $first);
        self::assertStringNotContainsString('data-bolt-altcha-i18n', $second);
        self::assertStringContainsString('data-bolt-altcha-i18n="de"', $german);
    }

    public function testUnknownOrOddLocalesFallBackToEnglish(): void
    {
        foreach (['xx', '../../etc', ''] as $locale) {
            $html = $this->renderer([], $this->request($locale))->render('default');

            self::assertStringContainsString(' language="' . ($locale === 'xx' ? 'xx' : 'en') . '"', $html, $locale);
            $language = $locale === 'xx' ? 'xx' : 'en';
            self::assertSame('I’m not a robot', self::texts($html, $language)['label'], $locale);
        }

        $html = $this->renderer([], $this->request('cs'))->render('default', [
            'language' => '"><script>',
        ]);
        self::assertStringContainsString(' language="cs"', $html, 'an invalid language attribute is ignored');
    }

    public function testTextsCannotBreakOutOfTheirScriptElement(): void
    {
        $html = $this->renderer([], $this->request('en'))->render('default');
        $start = (int) mb_strpos($html, 'data-bolt-altcha-i18n="en">');
        $json = mb_substr($html, $start, (int) mb_strpos($html, '</script>', $start) - $start);

        // The footer text holds HTML: it is encoded, never written as markup.
        self::assertStringNotContainsString('<a ', $json);
        self::assertStringContainsString('\u003Ca href=', $json);
    }

    public function testWithoutARequestNothingBreaks(): void
    {
        $renderer = $this->renderer([], null);

        self::assertStringContainsString('<script type="module"', $renderer->render('default'));
        self::assertStringContainsString('<script type="module"', $renderer->render('default'), 'no page to remember it for');
        self::assertStringContainsString(' language="en"', $renderer->render('default'));
    }

    public function testDisabledRendersNothing(): void
    {
        $renderer = $this->renderer([
            'enabled' => false,
            'bolt' => [
                'login' => true,
            ],
        ]);

        self::assertSame('', $renderer->render('default'));
        self::assertSame('', $renderer->renderLogin());
    }

    public function testLoginWidgetFollowsBoltLogin(): void
    {
        self::assertSame('', $this->renderer()->renderLogin());

        $html = $this->renderer([
            'profiles' => [
                'login' => [],
            ],
            'bolt' => [
                'login' => true,
            ],
        ])->renderLogin();

        self::assertStringContainsString('challenge="/altcha/challenge/login" name="altcha"', $html);
    }

    /**
     * Browsers offer WebCrypto, which the widget solves with, only in secure contexts: a login
     * page served over plain HTTP locks everybody out, so it is logged.
     */
    public function testALoginPageOverPlainHttpIsLogged(): void
    {
        $loginOn = [
            'profiles' => [
                'login' => [],
            ],
            'bolt' => [
                'login' => true,
            ],
        ];
        $cases = [
            'http://cms.example/bolt/login' => ['warning'],
            'https://cms.example/bolt/login' => [],
            'http://localhost/bolt/login' => [],
            'http://site.localhost/bolt/login' => [],
            'http://127.0.0.1:8000/bolt/login' => [],
            'http://[::1]/bolt/login' => [],
            'http://127.example/bolt/login' => ['warning'],
        ];

        foreach ($cases as $uri => $expected) {
            $logger = new class() extends AbstractLogger {
                /** @var list<string> */
                public array $levels = [];

                public function log($level, string|Stringable $message, array $context = []): void
                {
                    $this->levels[] = is_string($level) ? $level : '';
                }
            };

            $html = $this->widgetRenderer($this->services($loginOn), Request::create($uri), true, $logger)->renderLogin();

            self::assertStringContainsString('<altcha-widget', $html, $uri);
            self::assertSame($expected, $logger->levels, $uri);
        }
    }

    /**
     * Not rendered while the login is not checked anyway (LoginAltchaSubscriber stands down too).
     */
    public function testLoginWidgetIsEmptyWhileTheLoginIsNotChecked(): void
    {
        $loginOn = [
            'profiles' => [
                'login' => [],
            ],
            'bolt' => [
                'login' => true,
            ],
        ];

        self::assertSame('', $this->widgetRenderer($this->services($loginOn), new Request(), false)->renderLogin(), 'widget not published');
        self::assertSame('', $this->widgetRenderer($this->services($loginOn, null, '!ChangeMe!'))->renderLogin(), 'weak key');
    }

    /**
     * Attribute names are written into the HTML as they are: a name with a space or `=` could add
     * an event handler.
     */
    public function testInvalidAttributeNamesAreRejected(): void
    {
        foreach (['x onfocus=alert(1) autofocus', 'Display', '"x"', ''] as $name) {
            try {
                $this->renderer()->render('default', [
                    $name => 'y',
                ]);
                self::fail('Accepted the attribute name ' . $name);
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('not a valid ALTCHA widget attribute name', $exception->getMessage());
            }
        }
    }

    public function testLocalesWithAScriptSubtag(): void
    {
        $html = $this->renderer([], $this->request('zh_Hant_TW'))
            ->render('default');

        self::assertStringContainsString(' language="zh-hant-tw"', $html);
        self::assertSame('I’m not a robot', self::texts($html, 'zh-hant-tw')['label'], 'no catalog for zh_Hant_TW: English');
    }

    public function testUnknownProfileIsAnError(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->renderer()
            ->render('missing');
    }

    public function testObjectAttributeIsAnError(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->renderer()
            ->render('default', [
                'theme' => new stdClass(),
            ]);
    }

    public function testUnencodableAttributeIsAnError(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->renderer()
            ->render('default', [
                'configuration' => [
                    'label' => "\xB1\x31",
                ],
            ]);
    }

    /**
     * @param array<array-key, mixed> $config
     */
    private function renderer(array $config = [], ?Request $request = new Request()): WidgetRenderer
    {
        return $this->widgetRenderer($this->services($config), $request);
    }

    private function request(string $locale): Request
    {
        $request = new Request();
        $request->setLocale($locale);

        return $request;
    }

    /**
     * The texts the page hands to the widget for `$language`.
     *
     * @return array<string, string>
     */
    private static function texts(string $html, string $language): array
    {
        $pattern = '~<script type="application/json" data-bolt-altcha-i18n="' . preg_quote($language, '~') . '">(.*?)</script>~';
        self::assertSame(1, preg_match($pattern, $html, $match), 'no texts for ' . $language);

        $texts = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($texts);
        $strings = [];
        foreach ($texts as $key => $text) {
            self::assertIsString($text);
            $strings[(string) $key] = $text;
        }

        return $strings;
    }
}

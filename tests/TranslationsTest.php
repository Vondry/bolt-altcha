<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Tomvondracek\Altcha\Extension;
use Tomvondracek\Altcha\Form\AltchaType;
use Tomvondracek\Altcha\Guard\RequestGuard;
use Tomvondracek\Altcha\Security\LoginAltchaSubscriber;
use Tomvondracek\Altcha\Widget\WidgetTexts;

/**
 * The catalogs in translations/: every language of Bolt's backend, plus those of the other Bolt
 * extensions (appolodev/bolt-seo: `pt`, `ro`), in each of the three domains, each with every
 * message of the English one.
 */
final class TranslationsTest extends TestCase
{
    use AltchaTestTrait;

    public const LOCALES = ['en', 'bg', 'cs', 'de', 'el', 'es', 'fr', 'hu', 'it', 'nl', 'pl', 'pt', 'pt_BR', 'ro', 'ru', 'sk', 'tr', 'uk', 'zh_CN'];

    /**
     * The extension's own domain, and the two domains in which Bolt's templates translate the
     * login error and flash messages.
     */
    public const DOMAINS = [Extension::TRANSLATION_DOMAIN, 'security', 'messages'];

    public function testEveryDomainHasACatalogForEveryLanguage(): void
    {
        $files = glob(self::directory() . '/*');
        self::assertIsArray($files);
        $files = array_map(basename(...), $files);
        sort($files);

        $expected = [];
        foreach (self::DOMAINS as $domain) {
            foreach (self::LOCALES as $locale) {
                $expected[] = $domain . '.' . $locale . '.yaml';
            }
        }
        sort($expected);

        self::assertSame($expected, $files);
    }

    #[DataProvider('domains')]
    public function testEnglishCatalogTranslatesEveryMessageToItself(string $domain): void
    {
        foreach (self::catalog($domain, 'en') as $message => $translation) {
            self::assertSame($message, $translation);
        }
    }

    #[DataProvider('catalogs')]
    public function testCatalogHasEveryMessageWithItsMarkup(string $domain, string $locale): void
    {
        $english = self::catalog($domain, 'en');
        $catalog = self::catalog($domain, $locale);

        self::assertSame(array_keys($english), array_keys($catalog), sprintf('The messages of "%s" in "%s" differ from the English ones.', $locale, $domain));
        foreach ($catalog as $message => $translation) {
            self::assertNotSame('', mb_trim($translation), sprintf('"%s" is empty in "%s".', $message, $locale));
            self::assertSame(self::markup($message), self::markup($translation), sprintf('The placeholders or HTML of "%s" differ in "%s".', $message, $locale));
        }
    }

    /**
     * Someone whose site or backend is in one of Bolt's languages sees the widget and the
     * messages in that language, not in English.
     */
    public function testEveryBackendLanguageOfBoltIsCovered(): void
    {
        $files = glob(dirname(__DIR__) . '/vendor/bolt/core/translations/messages.*.xlf');
        self::assertIsArray($files);
        self::assertNotEmpty($files);

        foreach ($files as $file) {
            $locale = explode('.', basename($file))[1];
            self::assertContains($locale, self::LOCALES, sprintf('Bolt\'s backend speaks "%s".', $locale));

            if ($locale === 'en') {
                continue;
            }

            $translator = self::translator($locale);
            self::assertNotSame(WidgetTexts::MESSAGES['label'], $translator->trans(WidgetTexts::MESSAGES['label'], [], Extension::TRANSLATION_DOMAIN), $locale);
            self::assertNotSame(LoginAltchaSubscriber::MESSAGE_KEY, $translator->trans(LoginAltchaSubscriber::MESSAGE_KEY, [], 'security'), $locale);
            self::assertNotSame(RequestGuard::FLASH_MESSAGE, $translator->trans(RequestGuard::FLASH_MESSAGE, [], 'messages'), $locale);
        }
    }

    /**
     * Every text the code translates is in its domain's catalog, and the catalogs hold nothing
     * that is no longer used.
     */
    public function testCatalogsMatchTheMessagesTheCodeUses(): void
    {
        self::assertSame(
            [...array_values(WidgetTexts::MESSAGES), AltchaType::ERROR_MESSAGE, ...self::templateMessages()],
            array_keys(self::catalog(Extension::TRANSLATION_DOMAIN, 'en'))
        );
        self::assertSame([LoginAltchaSubscriber::MESSAGE_KEY], array_keys(self::catalog('security', 'en')));
        self::assertSame([RequestGuard::FLASH_MESSAGE], array_keys(self::catalog('messages', 'en')));
    }

    /**
     * The widget's texts are exactly those of the vendored widget, so a new widget version with
     * new or changed texts fails here instead of showing English on a Czech site.
     */
    public function testWidgetTextsMatchTheVendoredWidget(): void
    {
        $bundle = (string) file_get_contents(dirname(__DIR__) . '/assets/altcha/altcha.js');
        // The minified widget registers them as `globalThis.$altcha.i18n.set("en",{…})`.
        $call = 'globalThis.$altcha.i18n.set("en",';
        $start = mb_strpos($bundle, $call);
        self::assertIsInt($start, 'the widget registers its English texts');
        $start += mb_strlen($call);
        $end = mb_strpos($bundle, '})', $start);
        self::assertIsInt($end);
        $object = mb_substr($bundle, $start, $end - $start + 1);

        preg_match_all('~(\w+):("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\')~u', $object, $pairs, PREG_SET_ORDER);
        $english = [];
        foreach ($pairs as [, $key, $literal]) {
            $english[$key] = mb_substr($literal, 1, -1);
        }
        ksort($english);
        $expected = WidgetTexts::MESSAGES;
        ksort($expected);

        self::assertSame($expected, $english);
    }

    public function testWidgetTextsAreTranslatedForTheLocale(): void
    {
        $texts = (new WidgetTexts(self::translator()))->forLocale('cs');

        self::assertSame(array_keys(WidgetTexts::MESSAGES), array_keys($texts));
        self::assertSame('Nejsem robot', $texts['label']);
        self::assertSame('I’m not a robot', (new WidgetTexts(self::translator()))->forLocale('xx')['label'], 'unknown languages fall back to English');
    }

    /**
     * The texts the templates translate in the extension's domain (`'…'|trans({}, 'bolt_altcha')`).
     *
     * @return list<string>
     */
    private static function templateMessages(): array
    {
        $templates = glob(dirname(__DIR__) . '/templates/{,*/}*.twig', GLOB_BRACE);
        self::assertIsArray($templates);

        $messages = [];
        foreach ($templates as $template) {
            preg_match_all("~'((?:[^'\\\\]|\\\\.)+)'\\|trans\\(\\{\\}, '" . Extension::TRANSLATION_DOMAIN . "'\\)~", (string) file_get_contents($template), $matches);
            array_push($messages, ...$matches[1]);
        }

        self::assertNotEmpty($messages);

        // Several templates share a message (`Run on the server:`); the catalog has it once.
        return array_values(array_unique($messages));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function domains(): iterable
    {
        foreach (self::DOMAINS as $domain) {
            yield $domain => [$domain];
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function catalogs(): iterable
    {
        foreach (self::DOMAINS as $domain) {
            foreach (self::LOCALES as $locale) {
                yield $domain . '.' . $locale => [$domain, $locale];
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private static function catalog(string $domain, string $locale): array
    {
        $catalog = Yaml::parseFile(self::directory() . '/' . $domain . '.' . $locale . '.yaml');
        self::assertIsArray($catalog);

        $messages = [];
        foreach ($catalog as $message => $translation) {
            self::assertIsString($translation);
            $messages[(string) $message] = $translation;
        }

        return $messages;
    }

    /**
     * Placeholders, tag names and URLs, which a translation must keep.
     *
     * @return list<string>
     */
    private static function markup(string $text): array
    {
        preg_match_all('~%\w+%|</?[a-z]+\b|href="[^"]*"~', $text, $matches);

        return $matches[0];
    }

    private static function directory(): string
    {
        return dirname(__DIR__) . '/translations';
    }
}

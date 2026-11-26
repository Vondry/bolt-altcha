<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Widget;

use InvalidArgumentException;
use JsonException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tomvondracek\Altcha\Assets\AssetsStatus;
use Tomvondracek\Altcha\Challenge\HmacKeyProvider;
use Tomvondracek\Altcha\Config\AltchaConfig;
use Tomvondracek\Altcha\Config\AltchaConfigLoader;
use Tomvondracek\Altcha\Config\Protection;
use Tomvondracek\Altcha\Controller\ChallengeController;
use Tomvondracek\Altcha\Log\LogThrottle;

/**
 * Renders `<altcha-widget>` for a profile, and the widget's stylesheet and scripts once per page.
 * Everything is served from the site itself (public/assets/altcha/, published by
 * `extensions:configure`): no CDN, no third-party request.
 *
 * The widget's texts come from Symfony translations (WidgetTexts) in the page's language: they
 * are printed once per page and language as a JSON <script> (inert, so a strict CSP allows it),
 * which assets/altcha/bolt-altcha.js hands to the widget.
 */
final readonly class WidgetRenderer
{
    /**
     * Cache buster of the files in assets/altcha/: the widget version, plus a counter for changes
     * of bolt-altcha.js. Bump it whenever assets/ changes.
     */
    public const ASSETS_VERSION = '3.7.0-2';

    public const ASSETS_PATH = '/assets/altcha';

    /**
     * The language when there is no request or its locale is not a language code.
     */
    private const FALLBACK_LANGUAGE = 'en';

    /**
     * Language, then up to two subtags: script and/or region (cs, pt-br, zh-hant-tw).
     */
    private const LANGUAGE_PATTERN = '/\A[a-z]{2,3}(-[a-z0-9]{2,8}){0,2}\z/';

    /**
     * HTML attribute names the extension writes: lowercase letters, digits and hyphens.
     */
    private const ATTRIBUTE_NAME_PATTERN = '/\A[a-z][a-z0-9-]*\z/';

    private const SCRIPTS_PRINTED = '_bolt_altcha_scripts_printed';
    private const TEXTS_PRINTED = '_bolt_altcha_texts_printed';

    public function __construct(
        private AltchaConfigLoader $configLoader,
        private UrlGeneratorInterface $urlGenerator,
        private RequestStack $requestStack,
        private WidgetTexts $texts,
        private Protection $protection,
        private AssetsStatus $assets,
        private LoggerInterface $logger = new NullLogger(),
        private ?HmacKeyProvider $keys = null,
        private ?LogThrottle $logThrottle = null,
    ) {
    }

    /**
     * The widget for `$profile`, preceded by the scripts if this page hasn't printed them yet.
     * Empty when the extension is disabled.
     *
     * Throws on an invalid config and without a strong signing key: the challenge endpoint
     * answers 404 then, so the widget could never be solved. The automatic hooks (the login widget,
     * fields added to configured forms) don't get here then (Protection); a template or form that
     * uses the widget on purpose shows the error.
     *
     * @param array<array-key, mixed> $attributes widget attributes, merged over the profile's
     * @param string|null             $fieldName  name of the hidden input with the solution;
     *                                            `field_name` from the config by default
     */
    public function render(string $profile, array $attributes = [], ?string $fieldName = null): string
    {
        $config = $this->configLoader->load();
        if (! $config->enabled) {
            return '';
        }

        $this->keys?->assertUsable();

        $settings = $config->profile($profile) ?? throw new InvalidArgumentException(sprintf(
            'Unknown ALTCHA profile "%s". Define it under `profiles` in tomvondracek-altcha.yaml.',
            $profile
        ));

        $merged = AltchaConfig::mergeWidget($settings->widget, $attributes);
        // A `language` set in the profile or by the caller wins over the page's.
        $language = $merged['language'] ?? null;
        $language = is_string($language) && preg_match(self::LANGUAGE_PATTERN, $language) === 1 ? $language : $this->pageLanguage();
        unset($merged['language']);

        $merged = [
            'challenge' => $this->challengeUrl($profile),
            'name' => $fieldName ?? $config->fieldName,
            'language' => $language,
        ] + $merged;

        return $this->scripts() . $this->texts($language) . '<altcha-widget' . self::attributes($merged) . '></altcha-widget>';
    }

    /**
     * The widget for Bolt's login form, or nothing while the login is not checked: `bolt.login`
     * off, the protection off (Protection), or the widget not published (the same conditions as
     * LoginAltchaSubscriber).
     *
     * Logs a warning when the page is not a secure context: the widget solves with WebCrypto,
     * which browsers only offer on HTTPS pages and on localhost, so over plain HTTP nobody can log
     * in. The check itself never depends on the request (a bot would just post over HTTP).
     */
    public function renderLogin(): string
    {
        $config = $this->protection->activeConfig();
        if ($config === null || $config->loginProfile === null || ! $this->assets->isPublished()) {
            return '';
        }

        $request = $this->requestStack->getMainRequest();
        if ($request !== null && ! self::isSecureContext($request) && ($this->logThrottle?->allow('login.plain_http') ?? true)) {
            $this->logger->warning('ALTCHA protects the login, but the login page was served over plain HTTP ({host}). Browsers solve the challenge only on HTTPS pages (and on localhost): if they really load it over HTTP, nobody can log in. Serve the site over HTTPS; behind a proxy that terminates TLS, configure Symfony\'s trusted proxies.', [
                'host' => $request->getHost(),
            ]);
        }

        return $this->render($config->loginProfile);
    }

    /**
     * The stylesheet and the module script, once per page (`render()` calls it). Call it yourself
     * only to put them in the `<head>`.
     */
    public function scripts(): string
    {
        $request = $this->requestStack->getMainRequest();
        if ($request !== null) {
            if ($request->attributes->getBoolean(self::SCRIPTS_PRINTED)) {
                return '';
            }

            $request->attributes->set(self::SCRIPTS_PRINTED, true);
        }

        $base = ($request?->getBasePath() ?? '') . self::ASSETS_PATH . '/';
        $query = '?' . http_build_query([
            'v' => self::ASSETS_VERSION,
        ]);

        return sprintf(
            '<link rel="stylesheet" href="%s"><script type="module" src="%s"></script>',
            self::escape($base . 'altcha.css' . $query),
            self::escape($base . 'bolt-altcha.js' . $query)
        );
    }

    public function challengeUrl(string $profile): string
    {
        return $this->urlGenerator->generate(ChallengeController::ROUTE, [
            'profile' => $profile,
        ]);
    }

    /**
     * The widget's texts in `$language`, once per page and language: a JSON <script> the loader
     * reads. Encoded so no text can close the element.
     */
    private function texts(string $language): string
    {
        $request = $this->requestStack->getMainRequest();
        if ($request !== null) {
            $printed = $request->attributes->get(self::TEXTS_PRINTED);
            $printed = is_array($printed) ? $printed : [];
            if (in_array($language, $printed, true)) {
                return '';
            }

            $printed[] = $language;
            $request->attributes->set(self::TEXTS_PRINTED, $printed);
        }

        return sprintf(
            '<script type="application/json" data-bolt-altcha-i18n="%s">%s</script>',
            self::escape($language),
            json_encode(
                $this->texts->forLocale(self::locale($language)),
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            )
        );
    }

    /**
     * Whether browsers treat the page as a secure context (and offer WebCrypto): HTTPS, or a
     * loopback host.
     */
    private static function isSecureContext(Request $request): bool
    {
        if ($request->isSecure()) {
            return true;
        }

        $host = mb_strtolower(trim($request->getHost(), '[]'));

        return $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || $host === '::1'
            || (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && str_starts_with($host, '127.'));
    }

    /**
     * The page's language as the widget names languages: the request locale, lowercase, with a
     * hyphen (cs, pt_BR → pt-br).
     */
    private function pageLanguage(): string
    {
        $locale = $this->requestStack->getCurrentRequest()?->getLocale() ?? '';
        $language = mb_strtolower(str_replace('_', '-', $locale));

        return preg_match(self::LANGUAGE_PATTERN, $language) === 1 ? $language : self::FALLBACK_LANGUAGE;
    }

    /**
     * A widget language as a Symfony locale: pt-br → pt_BR, zh-hant-tw → zh_Hant_TW.
     */
    private static function locale(string $language): string
    {
        $parts = explode('-', $language);
        $locale = array_shift($parts);
        foreach ($parts as $subtag) {
            // A four-letter subtag is a script (Hant), the others a region (BR, 419).
            $locale .= '_' . (mb_strlen($subtag) === 4 ? ucfirst($subtag) : mb_strtoupper($subtag));
        }

        return $locale;
    }

    /**
     * `null` and `false` leave an attribute out, `true` writes it without a value, a map (the
     * widget's `configuration`) becomes JSON.
     *
     * @param array<string, mixed> $attributes
     */
    private static function attributes(array $attributes): string
    {
        $html = '';
        foreach ($attributes as $name => $value) {
            if (preg_match(self::ATTRIBUTE_NAME_PATTERN, $name) !== 1) {
                throw new InvalidArgumentException(sprintf('"%s" is not a valid ALTCHA widget attribute name (lowercase letters, digits and "-", as in the ALTCHA docs).', $name));
            }

            if ($value === null || $value === false || $value === []) {
                continue;
            }

            if ($value === true) {
                $html .= ' ' . self::escape($name);

                continue;
            }

            if (is_array($value)) {
                try {
                    $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    throw new InvalidArgumentException(sprintf('The ALTCHA widget attribute "%s" can\'t be encoded as JSON.', $name), 0, $exception);
                }
            } elseif (! is_scalar($value)) {
                throw new InvalidArgumentException(sprintf('The ALTCHA widget attribute "%s" must be a string, number, boolean, map or null.', $name));
            }

            $html .= sprintf(' %s="%s"', self::escape($name), self::escape((string) $value));
        }

        return $html;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}

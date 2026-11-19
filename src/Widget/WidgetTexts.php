<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Widget;

use Symfony\Contracts\Translation\TranslatorInterface;
use Tomvondracek\Altcha\Extension;

/**
 * The texts of the ALTCHA widget, translated by Symfony in the `bolt_altcha` domain, so the
 * widget speaks the site's language and a project can reword any text in its own
 * translations/bolt_altcha.<locale>.yaml.
 *
 * The keys are the widget's (its i18n registry, `$altcha.i18n`); the values are the widget's
 * English texts, which are also the message ids in the catalogs.
 */
final readonly class WidgetTexts
{
    public const MESSAGES = [
        'ariaLinkLabel' => 'Altcha (official website)',
        'cancel' => 'Cancel',
        'enterCode' => 'Enter code',
        'enterCodeAria' => 'Enter code you hear. Press Space to play audio.',
        'enterCodeFromImage' => 'To proceed, please enter the code from the image below.',
        'error' => 'Verification failed. Try again later.',
        'expired' => 'Verification expired. Try again.',
        'footer' => 'Protected by <a href="https://altcha.org/" target="_blank" rel="noopener" aria-label="Altcha (official website, opens in new tab)">ALTCHA</a>',
        'getAudioChallenge' => 'Get an audio challenge',
        'label' => 'I’m not a robot',
        'loading' => 'Loading...',
        'reload' => 'Reload',
        'verify' => 'Verify',
        'verificationRequired' => 'Verification required!',
        'verified' => 'Verified',
        'verifying' => 'Verifying...',
        'waitAlert' => 'Verifying... please wait.',
    ];

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array<string, string> widget key => text in `$locale`
     */
    public function forLocale(string $locale): array
    {
        $texts = [];
        foreach (self::MESSAGES as $key => $message) {
            $texts[$key] = $this->translator->trans($message, [], Extension::TRANSLATION_DOMAIN, $locale);
        }

        return $texts;
    }
}

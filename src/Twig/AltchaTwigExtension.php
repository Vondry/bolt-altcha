<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Twig;

use Tomvondracek\Altcha\Widget\WidgetRenderer;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig functions for hand-written forms:
 *
 *     {{ altcha_widget('newsletter', {auto: 'onload', display: 'floating'}) }}
 *     {{ altcha_script() }}                    {# optional: the scripts in <head> #}
 *     {{ altcha_challenge_url('newsletter') }}  {# for a custom widget setup #}
 */
final class AltchaTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly WidgetRenderer $renderer,
    ) {
    }

    public function getFunctions(): array
    {
        $safe = [
            'is_safe' => ['html'],
        ];

        return [
            new TwigFunction('altcha_widget', $this->widget(...), $safe),
            new TwigFunction('altcha_script', $this->renderer->scripts(...), $safe),
            new TwigFunction('altcha_challenge_url', $this->renderer->challengeUrl(...)),
            new TwigFunction('altcha_login_widget', $this->renderer->renderLogin(...), $safe),
        ];
    }

    /**
     * @param array<array-key, mixed> $attributes
     */
    public function widget(string $profile = 'default', array $attributes = [], ?string $name = null): string
    {
        return $this->renderer->render($profile, $attributes, $name);
    }
}

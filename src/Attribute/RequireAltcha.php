<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Attribute;

use Attribute;

/**
 * Requires a valid ALTCHA solution for every unsafe request (POST, PUT, PATCH, DELETE) to a
 * controller or one of its actions:
 *
 *     #[Route('/newsletter', name: 'newsletter_subscribe', methods: ['POST'])]
 *     #[RequireAltcha(profile: 'newsletter')]
 *     public function subscribe(Request $request): Response
 *
 * The form needs the widget: `{{ altcha_widget('newsletter') }}`. A failure is answered as
 * `on_route_failure` says.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class RequireAltcha
{
    public function __construct(
        public string $profile = 'default',
    ) {
    }
}

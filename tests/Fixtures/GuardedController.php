<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Tests\Fixtures;

use Symfony\Component\HttpFoundation\Response;
use Tomvondracek\Altcha\Attribute\RequireAltcha;

final class GuardedController
{
    #[RequireAltcha(profile: 'newsletter')]
    public function __invoke(): Response
    {
        return new Response('subscribed');
    }
}

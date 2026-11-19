<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Tests\Fixtures;

use Symfony\Component\HttpFoundation\Response;
use Tomvondracek\Altcha\Attribute\RequireAltcha;

#[RequireAltcha(profile: 'contact')]
final class GuardedClassController
{
    #[RequireAltcha(profile: 'newsletter')]
    public function subscribe(): Response
    {
        return new Response('subscribed');
    }
}

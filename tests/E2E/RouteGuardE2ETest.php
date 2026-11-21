<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Tests\E2E;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * A hand-written form protected under `routes:`: a failed submission goes back to the form
 * (`redirect_back`), never to another site.
 */
final class RouteGuardE2ETest extends E2ETestCase
{
    private const CONFIG = [
        'routes' => [
            'newsletter_subscribe' => true,
        ],
    ];

    public function testASubmissionWithSolutionGoesThrough(): void
    {
        $this->bootSite(self::CONFIG);

        $response = $this->request('POST', '/newsletter', [
            'altcha' => $this->solution('default'),
        ]);

        self::assertSame('subscribed', $response->getContent());
    }

    /**
     * @return iterable<string, array{string|null, string}>
     */
    public static function referers(): iterable
    {
        yield 'the form on this site' => ['http://localhost/form', '/form'];
        yield 'with its query' => ['http://localhost/form?list=weekly#top', '/form?list=weekly'];
        yield 'no Referer' => [null, '/'];
        yield 'another site' => ['https://evil.example/form', '/'];
        yield 'another scheme' => ['javascript://localhost/%0Aalert(1)', '/'];
        // PHP's parse_url() reads the host as localhost, browsers go to evil.example.
        yield 'backslash before the user info' => ['https://evil.example\@localhost/form', '/form'];
        // As a Location, `//evil.example/…` is another site.
        yield 'a path that looks like a host' => ['http://localhost//evil.example/form', '/evil.example/form'];
        yield 'a path with a backslash that looks like a host' => ['http://localhost/\evil.example/form', '/evil.example/form'];
        // Browsers drop tabs and line breaks from URLs, so `/<tab>/evil.example` would be
        // `//evil.example`; parse_url() replaces control characters with `_`.
        yield 'a tab between the slashes' => ["http://localhost/\t/evil.example/form", '/_/evil.example/form'];
        yield 'a line break in the query' => ["http://localhost/form?a=1\n&b=2", '/form?a=1_&b=2'];
    }

    #[DataProvider('referers')]
    public function testAFailedSubmissionGoesBackToTheFormOnThisSite(?string $referer, string $expected): void
    {
        $this->bootSite(self::CONFIG);

        $response = $this->request('POST', '/newsletter', [], $referer === null ? [] : [
            'HTTP_REFERER' => $referer,
        ]);

        self::assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode());
        self::assertSame($expected, $response->headers->get('Location'));
    }

    public function testRejectAnswers403(): void
    {
        $this->bootSite([
            'on_route_failure' => 'reject',
        ] + self::CONFIG);

        self::assertSame(Response::HTTP_FORBIDDEN, $this->request('POST', '/newsletter')->getStatusCode());
    }
}

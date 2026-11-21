<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Tests\E2E;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tomvondracek\Altcha\Form\AltchaType;

/**
 * Bolt's password reset forms (`bolt.reset_password`, `bolt.change_password`): Bolt's real form
 * types, the field added by the extension, rendered by the form theme, checked on submit.
 */
final class BoltPasswordFormsE2ETest extends E2ETestCase
{
    private const CONFIG = [
        'bolt' => [
            'reset_password' => true,
            'change_password' => true,
        ],
    ];

    /**
     * @return iterable<string, array{string, string, array<string, mixed>}>
     */
    public static function forms(): iterable
    {
        yield 'reset password request' => ['/bolt/reset-password', 'reset_password_request_form', [
            'email' => 'admin@example.com',
        ]];
        yield 'change password' => ['/bolt/reset-password/reset', 'change_password_form', [
            'plainPassword' => [
                'first' => 'a new password',
                'second' => 'a new password',
            ],
        ]];
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[DataProvider('forms')]
    public function testTheFormShowsTheWidget(string $uri, string $form, array $fields): void
    {
        $this->bootSite(self::CONFIG);

        $html = (string) $this->request('GET', $uri)->getContent();

        self::assertStringContainsString('<altcha-widget', $html);
        self::assertStringContainsString('name="' . $form . '[altcha]"', $html);
        self::assertStringContainsString('challenge="/altcha/challenge/default"', $html);
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[DataProvider('forms')]
    public function testASubmissionWithoutSolutionIsRejected(string $uri, string $form, array $fields): void
    {
        $this->bootSite(self::CONFIG);

        $response = $this->submit($uri, $form, $fields, null);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertStringContainsString(AltchaType::ERROR_MESSAGE, (string) $response->getContent());
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[DataProvider('forms')]
    public function testASubmissionWithSolutionIsAccepted(string $uri, string $form, array $fields): void
    {
        $this->bootSite(self::CONFIG);

        $response = $this->submit($uri, $form, $fields, $this->solution('default'));

        self::assertSame('accepted', $response->getContent());
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[DataProvider('forms')]
    public function testASolutionWorksOnce(string $uri, string $form, array $fields): void
    {
        $this->bootSite(self::CONFIG);
        $solution = $this->solution('default');

        self::assertSame('accepted', $this->submit($uri, $form, $fields, $solution)->getContent());
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->submit($uri, $form, $fields, $solution)->getStatusCode());
    }

    /**
     * The solution posted outside the form (`altcha`, as on the login) doesn't count.
     *
     * @param array<string, mixed> $fields
     */
    #[DataProvider('forms')]
    public function testASolutionOutsideTheFormIsIgnored(string $uri, string $form, array $fields): void
    {
        $this->bootSite(self::CONFIG);
        $token = self::inputValue((string) $this->request('GET', $uri)->getContent(), $form . '[_token]');

        $response = $this->request('POST', $uri, [
            $form => $fields + [
                '_token' => $token,
            ],
            'altcha' => $this->solution('default'),
        ]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[DataProvider('forms')]
    public function testNothingIsAddedWhileTheFormIsNotProtected(string $uri, string $form, array $fields): void
    {
        $this->bootSite([]);

        self::assertStringNotContainsString('<altcha-widget', (string) $this->request('GET', $uri)->getContent());
        self::assertSame('accepted', $this->submit($uri, $form, $fields, null)->getContent());
    }

    /**
     * Opens the form (for the CSRF token) and posts it as the browser would.
     *
     * @param array<string, mixed> $fields
     */
    private function submit(string $uri, string $form, array $fields, ?string $altcha): Response
    {
        $token = self::inputValue((string) $this->request('GET', $uri)->getContent(), $form . '[_token]');
        $fields['_token'] = $token;
        if ($altcha !== null) {
            $fields['altcha'] = $altcha;
        }

        return $this->request('POST', $uri, [
            $form => $fields,
        ]);
    }
}

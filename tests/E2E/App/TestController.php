<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Tests\E2E\App;

use Bolt\Form\ChangePasswordFormType;
use Bolt\Form\ResetPasswordRequestFormType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Twig\Environment;

/**
 * Stands in for Bolt's AuthenticationController and ResetPasswordController: the same forms, the
 * same `handleRequest()` / `isSubmitted() && isValid()` flow, and templates that print the forms
 * the way Bolt's do (`form_row` for the visible fields, then `form_end`). Bolt's controllers
 * themselves need Doctrine, the mailer and the reset-password bundle.
 */
final readonly class TestController
{
    public const LOGIN_TEMPLATE = <<<'TWIG'
        <form method="post">
        <input type="hidden" name="login[_token]" value="{{ csrf_token('login_csrf_token') }}">
        <div class="widgets-login_middle">{{ altcha_login_widget() }}</div>
        </form>
        <p id="error">{{ error ? error.messageKey : '' }}</p>
        TWIG;

    /**
     * As in Bolt's reset_password/request.html.twig and reset.html.twig.
     */
    private const FORM_TEMPLATE = <<<'TWIG'
        {{ form_start(form) }}
        {% for field in fields %}{{ form_row(form[field]) }}{% endfor %}
        <button class="btn btn-primary">Send</button>
        {{ form_end(form) }}
        TWIG;

    public function __construct(
        private Environment $twig,
        private FormFactoryInterface $formFactory,
    ) {
    }

    public function homepage(): Response
    {
        return new Response('home');
    }

    /**
     * GET only: Bolt's LoginFormAuthenticator answers every POST before the controller runs.
     */
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        return new Response($this->twig->createTemplate(self::LOGIN_TEMPLATE)->render([
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]));
    }

    public function resetPasswordRequest(Request $request): Response
    {
        return $this->handle($request, $this->formFactory->create(ResetPasswordRequestFormType::class), ['email']);
    }

    public function changePassword(Request $request): Response
    {
        return $this->handle($request, $this->formFactory->create(ChangePasswordFormType::class), ['plainPassword']);
    }

    public function newsletter(): Response
    {
        return new Response('subscribed');
    }

    /**
     * @param FormInterface<mixed> $form
     * @param list<string>         $fields
     */
    private function handle(Request $request, FormInterface $form, array $fields): Response
    {
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            return new Response('accepted');
        }

        return new Response(
            $this->twig->createTemplate(self::FORM_TEMPLATE)->render([
                'form' => $form->createView(),
                'fields' => $fields,
            ]),
            $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK
        );
    }
}

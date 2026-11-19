<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Tests\Twig;

use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\FormExtension;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Bridge\Twig\Form\TwigRendererEngine;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormRenderer;
use Symfony\Component\Form\Forms;
use Symfony\Component\Translation\IdentityTranslator;
use Tomvondracek\Altcha\Form\AltchaType;
use Tomvondracek\Altcha\Tests\AltchaServices;
use Tomvondracek\Altcha\Tests\AltchaTestTrait;
use Tomvondracek\Altcha\Twig\AltchaTwigExtension;
use Tomvondracek\Altcha\Widget\WidgetRenderer;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

final class AltchaTwigExtensionTest extends TestCase
{
    use AltchaTestTrait;

    public function testFunctionsPrintTheWidgetUnescaped(): void
    {
        $services = $this->services([
            'profiles' => [
                'login' => [],
                'newsletter' => [],
            ],
            'bolt' => [
                'login' => true,
            ],
        ]);

        $html = $this->twig($services, [
            'page.twig' => "{{ altcha_script() }}|{{ altcha_widget('newsletter', {display: 'floating'}) }}|{{ altcha_challenge_url('newsletter') }}",
        ])->render('page.twig');

        [$scripts, $widget, $url] = explode('|', $html);
        self::assertStringStartsWith('<link rel="stylesheet"', $scripts);
        self::assertStringStartsWith('<script type="application/json" data-bolt-altcha-i18n="en">', $widget);
        self::assertStringEndsWith('<altcha-widget challenge="/altcha/challenge/newsletter" name="altcha" language="en" display="floating"></altcha-widget>', $widget);
        self::assertSame('/altcha/challenge/newsletter', $url);
    }

    public function testLoginWidgetTemplate(): void
    {
        $services = $this->services([
            'profiles' => [
                'login' => [],
            ],
            'bolt' => [
                'login' => true,
            ],
        ]);

        $html = $this->twig($services, [])->render('@bolt-altcha/login_widget.html.twig');

        self::assertStringContainsString('<altcha-widget challenge="/altcha/challenge/login" name="altcha"', $html);
    }

    public function testFormThemeRendersTheWidgetAndItsErrors(): void
    {
        $services = $this->services();
        $twig = $this->twig($services, [
            'form.twig' => '{% form_theme form "@bolt-altcha/form/altcha.html.twig" %}{{ form_row(form.altcha) }}',
        ]);
        $renderer = $this->renderer($services);

        $form = Forms::createFormFactoryBuilder()
            ->addType(new AltchaType($services->verifier, $renderer, new IdentityTranslator()))
            ->getFormFactory()
            ->createNamedBuilder('contact')
            ->add('email', TextType::class)
            ->add('altcha', AltchaType::class)
            ->getForm();
        $form->submit([
            'email' => 'fan@example.com',
            'altcha' => 'forged',
        ]);

        $engine = new TwigRendererEngine(['form_div_layout.html.twig'], $twig);
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([
            FormRenderer::class => static fn (): FormRenderer => new FormRenderer($engine),
        ]));

        $html = $twig->render('form.twig', [
            'form' => $form->createView(),
        ]);

        self::assertStringContainsString('<div class="altcha-row"><link rel="stylesheet"', $html);
        self::assertStringContainsString('<altcha-widget challenge="/altcha/challenge/default" name="contact[altcha]"', $html);
        self::assertStringContainsString('<li>' . AltchaType::ERROR_MESSAGE . '</li>', $html);
        self::assertStringNotContainsString('<input', $html, 'the widget brings its own hidden input');
    }

    /**
     * @param array<string, string> $templates
     */
    private function twig(AltchaServices $services, array $templates): Environment
    {
        $files = new FilesystemLoader();
        $files->addPath(dirname(__DIR__, 2) . '/templates', 'bolt-altcha');
        $files->addPath(dirname(__DIR__, 2) . '/vendor/symfony/twig-bridge/Resources/views/Form');

        $twig = new Environment(new ChainLoader([new ArrayLoader($templates), $files]), [
            'strict_variables' => true,
        ]);
        $twig->addExtension(new AltchaTwigExtension($this->renderer($services)));
        $twig->addExtension(new FormExtension());
        $twig->addExtension(new TranslationExtension(new IdentityTranslator()));

        return $twig;
    }

    private function renderer(AltchaServices $services): WidgetRenderer
    {
        return $this->widgetRenderer($services);
    }
}

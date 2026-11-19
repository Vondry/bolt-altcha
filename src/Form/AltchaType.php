<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tomvondracek\Altcha\Extension;
use Tomvondracek\Altcha\Verification\Verifier;
use Tomvondracek\Altcha\Widget\WidgetRenderer;

/**
 * The ALTCHA widget as a form field:
 *
 *     $builder->add('altcha', AltchaType::class, [
 *         'profile' => 'contact',
 *         'widget' => ['auto' => 'onload', 'display' => 'floating'],
 *     ]);
 *
 * The widget writes its solution into a hidden input named like the field, and the field verifies
 * it on submit. The check runs in POST_SUBMIT, not as a validation constraint, so it applies
 * whatever `validation_groups` the form uses. Rendered by the form theme block `altcha_widget`
 * (templates/form/altcha.html.twig).
 */
final class AltchaType extends AbstractType
{
    /**
     * Message id in the `bolt_altcha` domain.
     */
    public const ERROR_MESSAGE = 'The check that you are not a robot failed. Please submit the form again.';

    public function __construct(
        private readonly Verifier $verifier,
        private readonly WidgetRenderer $renderer,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $this->check($event->getForm(), $event->getForm()->getData());
        });
    }

    /**
     * Verifies the solution submitted in an ALTCHA field and puts the error on the field when it
     * fails. AltchaTypeExtension also calls it for a field a partial submit skipped.
     */
    public function check(FormInterface $field, mixed $payload): void
    {
        $profile = $field->getConfig()->getOption('profile');
        if (! is_string($profile) || $this->verifier->verify($payload, $profile)->isValid()) {
            return;
        }

        $field->addError(new FormError(
            $this->translator->trans(self::ERROR_MESSAGE, [], Extension::TRANSLATION_DOMAIN),
            self::ERROR_MESSAGE
        ));
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $profile = is_string($options['profile']) ? $options['profile'] : 'default';
        $widget = is_array($options['widget']) ? $options['widget'] : [];
        // FormView::$vars is an untyped property in Symfony 6.4.
        $vars = is_array($view->vars) ? $view->vars : [];
        $fullName = $vars['full_name'] ?? null;

        $vars['altcha_html'] = $this->renderer->render($profile, $widget, is_string($fullName) ? $fullName : null);
        $view->vars = $vars;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'profile' => 'default',
            'widget' => [],
            'mapped' => false,
            'required' => false,
            'label' => false,
            'compound' => false,
            'error_bubbling' => false,
            'empty_data' => '',
        ]);
        $resolver->setAllowedTypes('profile', 'string');
        $resolver->setAllowedTypes('widget', 'array');
        $resolver->setInfo('profile', 'A profile from tomvondracek-altcha.yaml: its challenge options and widget attributes.');
        $resolver->setInfo('widget', '<altcha-widget> attributes, merged over the profile\'s (names as in the ALTCHA docs).');
    }

    public function getBlockPrefix(): string
    {
        return 'altcha';
    }
}

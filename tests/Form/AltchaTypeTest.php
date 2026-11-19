<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Tests\Form;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Translation\IdentityTranslator;
use Symfony\Component\Validator\Validation;
use Tomvondracek\Altcha\Form\AltchaType;
use Tomvondracek\Altcha\Form\AltchaTypeExtension;
use Tomvondracek\Altcha\Tests\AltchaServices;
use Tomvondracek\Altcha\Tests\AltchaTestTrait;

final class AltchaTypeTest extends TestCase
{
    use AltchaTestTrait;

    public function testValidSolutionMakesTheFormValid(): void
    {
        $services = $this->services();
        $form = $this->contactForm($this->factory($services));

        $form->submit([
            'email' => 'fan@example.com',
            'altcha' => $this->solvedPayload($services),
        ]);

        self::assertTrue($form->isValid());
    }

    public function testMissingOrWrongSolutionIsAFieldError(): void
    {
        $services = $this->services();
        $factory = $this->factory($services);

        foreach ([[], ['altcha' => 'forged']] as $extra) {
            $form = $this->contactForm($factory);
            $form->submit([
                'email' => 'fan@example.com',
            ] + $extra);

            self::assertFalse($form->isValid());
            $errors = $form->get('altcha')->getErrors();
            self::assertCount(1, $errors);
            $error = $errors[0];
            self::assertInstanceOf(FormError::class, $error);
            self::assertSame(AltchaType::ERROR_MESSAGE, $error->getMessageTemplate());
            self::assertCount(0, $form->getErrors(), 'the error stays on the field');
        }
    }

    public function testFieldUsesItsProfile(): void
    {
        $services = $this->services([
            'profiles' => [
                'contact' => [],
            ],
        ]);
        $factory = $this->factory($services);

        $form = $this->contactForm($factory, 'contact');
        $form->submit([
            'email' => 'fan@example.com',
            'altcha' => $this->solvedPayload($services),
        ]);

        self::assertFalse($form->isValid(), 'a default-profile solution does not fit the contact profile');

        $form = $this->contactForm($factory, 'contact');
        $form->submit([
            'email' => 'fan@example.com',
            'altcha' => $this->solvedPayload($services, 'contact'),
        ]);

        self::assertTrue($form->isValid());
    }

    public function testCheckedWhateverTheValidationGroups(): void
    {
        $services = $this->services();
        $form = $this->factory($services)
            ->createNamedBuilder('contact', FormType::class, null, [
                'validation_groups' => ['Strict'],
            ])
            ->add('altcha', AltchaType::class)
            ->getForm();

        $form->submit([
            'altcha' => 'forged',
        ]);

        self::assertFalse($form->isValid());
    }

    public function testViewRendersTheWidgetUnderTheFieldName(): void
    {
        $view = $this->contactForm($this->factory($this->services()), 'default', [
            'display' => 'floating',
        ])->createView();

        $vars = $view['altcha']->vars;
        self::assertIsArray($vars);
        $html = $vars['altcha_html'] ?? null;
        self::assertIsString($html);
        self::assertStringContainsString('name="contact[altcha]"', $html);
        self::assertStringContainsString('display="floating"', $html);
        self::assertIsArray($vars['block_prefixes'] ?? null);
        self::assertContains('altcha', $vars['block_prefixes']);
        self::assertFalse($vars['label'] ?? null);
    }

    public function testFieldIsNotMappedToTheData(): void
    {
        $services = $this->services();
        $form = $this->contactForm($this->factory($services));
        $form->submit([
            'email' => 'fan@example.com',
            'altcha' => $this->solvedPayload($services),
        ]);

        self::assertSame([
            'email' => 'fan@example.com',
        ], $form->getData());
    }

    public function testTypeExtensionAddsTheFieldToConfiguredRootForms(): void
    {
        $services = $this->services([
            'profiles' => [
                'reset' => [],
            ],
            'bolt' => [
                'reset_password' => 'reset',
            ],
            'forms' => [
                'contact' => true,
            ],
        ]);
        $factory = $this->factory($services);

        $reset = $factory->createNamed('reset_password_request_form');
        self::assertTrue($reset->has('altcha'));
        self::assertSame('reset', $reset->get('altcha')->getConfig()->getOption('profile'));

        self::assertTrue($factory->createNamed('contact')->has('altcha'));
        self::assertFalse($factory->createNamed('order')->has('altcha'));

        $parent = $factory->createNamedBuilder('order')
            ->add('contact', FormType::class)
            ->getForm();
        self::assertFalse($parent->get('contact')->has('altcha'), 'a child that shares the name is left alone');
    }

    public function testTypeExtensionKeepsAnExistingField(): void
    {
        $factory = $this->factory($this->services([
            'forms' => [
                'contact' => true,
            ],
        ]));

        $form = $factory->createNamedBuilder('contact')
            ->add('altcha', TextType::class)
            ->getForm();

        self::assertInstanceOf(TextType::class, $form->get('altcha')->getConfig()->getType()->getInnerType());
    }

    /**
     * PATCH, or submit($data, false), submits only the fields present in the data: a form sent
     * without its ALTCHA field must not pass unchecked.
     */
    public function testPartialSubmitWithoutTheFieldIsAnError(): void
    {
        $services = $this->services();
        $factory = $this->factory($services);

        $form = $this->contactForm($factory);
        $form->submit([
            'email' => 'fan@example.com',
        ], false);

        self::assertFalse($form->isValid());
        self::assertFalse($form->get('altcha')->isSubmitted());
        self::assertCount(1, $form->get('altcha')->getErrors());

        $nested = $factory->createNamedBuilder('order')
            ->add('contact', FormType::class)
            ->getForm();
        $nested->get('contact')->add('altcha', AltchaType::class);
        $nested->submit([
            'contact' => [],
        ], false);

        self::assertFalse($nested->isValid(), 'a field in a sub-form is found too');
    }

    public function testPartialSubmitWithTheFieldIsChecked(): void
    {
        $services = $this->services();
        $form = $this->contactForm($this->factory($services));

        $form->submit([
            'altcha' => $this->solvedPayload($services),
        ], false);

        self::assertTrue($form->isValid());
    }

    public function testPartialSubmitWhileDisabledPasses(): void
    {
        $form = $this->contactForm($this->factory($this->services([
            'enabled' => false,
        ])));

        $form->submit([
            'email' => 'fan@example.com',
        ], false);

        self::assertTrue($form->isValid());
    }

    public function testTypeExtensionAddsNothingWhileTheKeyIsWeak(): void
    {
        $factory = $this->factory($this->services([
            'forms' => [
                'contact' => true,
            ],
        ], null, '!ChangeMe!'));

        self::assertFalse($factory->createNamed('contact')->has('altcha'));
    }

    public function testTypeExtensionDoesNothingWhenDisabled(): void
    {
        $factory = $this->factory($this->services([
            'enabled' => false,
            'forms' => [
                'contact' => true,
            ],
        ]));

        self::assertFalse($factory->createNamed('contact')->has('altcha'));
    }

    /**
     * @param array<string, mixed> $widget
     */
    private function contactForm(FormFactoryInterface $factory, string $profile = 'default', array $widget = []): FormInterface
    {
        return $factory->createNamedBuilder('contact')
            ->add('email', TextType::class)
            ->add('altcha', AltchaType::class, [
                'profile' => $profile,
                'widget' => $widget,
            ])
            ->getForm();
    }

    private function factory(AltchaServices $services): FormFactoryInterface
    {
        $renderer = $this->widgetRenderer($services, null);

        $type = new AltchaType($services->verifier, $renderer, new IdentityTranslator());

        return Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType($type)
            ->addTypeExtension(new AltchaTypeExtension($services->protection, $type))
            ->getFormFactory();
    }
}

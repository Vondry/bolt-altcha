<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Form;

use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Tomvondracek\Altcha\Config\Protection;

/**
 * Two jobs on every form:
 *
 * 1. Adds the AltchaType field to the forms listed in the config, without touching their code:
 *    Bolt's password reset forms (`bolt.reset_password`, `bolt.change_password`) and any form
 *    under `forms:`, matched by the root form's name. The field is added when the form gets its
 *    data (PRE_SET_DATA), the first moment the form knows whether it is the root: a child that
 *    happens to share the name is left alone.
 *
 * 2. Makes sure no ALTCHA field is skipped: a PATCH request or `$form->submit($data, false)` only
 *    submits the fields present in the data, so a missing ALTCHA field would never check
 *    anything. After the root form is submitted, every ALTCHA field that was not submitted is
 *    checked as an empty one.
 */
final class AltchaTypeExtension extends AbstractTypeExtension
{
    public function __construct(
        private readonly Protection $protection,
        private readonly AltchaType $altchaType,
    ) {
    }

    public static function getExtendedTypes(): iterable
    {
        return [FormType::class];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $form = $event->getForm();
            if ($form->isRoot()) {
                $this->checkSkippedFields($form);
            }
        });

        $config = $this->protection->activeConfig();
        $profile = $config?->formProfile($builder->getName());
        if ($config === null || $profile === null) {
            return;
        }

        $fieldName = $config->fieldName;
        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event) use ($profile, $fieldName): void {
            $form = $event->getForm();
            if (! $form->isRoot() || $form->has($fieldName)) {
                return;
            }

            $form->add($fieldName, AltchaType::class, [
                'profile' => $profile,
            ]);
        });
    }

    private function checkSkippedFields(FormInterface $form): void
    {
        foreach ($form->all() as $child) {
            if ($child->getConfig()->getType()->getInnerType() instanceof AltchaType) {
                if (! $child->isSubmitted()) {
                    $this->altchaType->check($child, null);
                }

                continue;
            }

            $this->checkSkippedFields($child);
        }
    }
}

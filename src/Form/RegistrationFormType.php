<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'E-Mail-Adresse',
                'constraints' => [
                    new NotBlank([
                        'message' => 'Bitte geben Sie eine E-Mail-Adresse ein.',
                    ]),
                    new Email([
                        'message' => 'Bitte geben Sie eine gültige E-Mail-Adresse ein.',
                    ]),
                ],
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'ihre@email.com',
                ],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'invalid_message' => 'Die Passwörter stimmen nicht überein.',
                'options' => [
                    'attr' => ['class' => 'form-control'],
                ],
                'first_options' => [
                    'label' => 'Passwort',
                    'constraints' => [
                        new NotBlank([
                            'message' => 'Bitte geben Sie ein Passwort ein.',
                        ]),
                        new Length([
                            'min' => 8,
                            'minMessage' => 'Das Passwort muss mindestens {{ limit }} Zeichen lang sein.',
                            'max' => 4096,
                        ]),
                        new Regex([
                            'pattern' => '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/',
                            'message' => 'Das Passwort muss mindestens einen Großbuchstaben, einen Kleinbuchstaben und eine Zahl enthalten.',
                        ]),
                    ],
                    'attr' => [
                        'placeholder' => 'Mindestens 8 Zeichen',
                    ],
                ],
                'second_options' => [
                    'label' => 'Passwort bestätigen',
                    'attr' => [
                        'placeholder' => 'Passwort wiederholen',
                    ],
                ],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Registrieren',
                'attr' => [
                    'class' => 'btn btn-primary w-100',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
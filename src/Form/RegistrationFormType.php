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
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'E-Mail',
                'attr' => [
                    'placeholder' => 'Ihre E-Mail-Adresse',
                    'class' => 'form-control',
                    'autofocus' => true,
                ],
                'constraints' => [
                    new NotBlank(['message' => 'Bitte geben Sie eine E-Mail-Adresse ein']),
                    new Email(['message' => 'Bitte geben Sie eine gültige E-Mail-Adresse ein']),
                ],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'first_options' => [
                    'label' => 'Passwort',
                    'attr' => [
                        'placeholder' => 'Ihr Passwort',
                        'class' => 'form-control',
                    ],
                    'constraints' => [
                        new NotBlank(['message' => 'Bitte geben Sie ein Passwort ein']),
                        new Length([
                            'min' => 6,
                            'minMessage' => 'Das Passwort muss mindestens {{ limit }} Zeichen haben',
                            'max' => 4096,
                        ]),
                    ],
                ],
                'second_options' => [
                    'label' => 'Passwort bestätigen',
                    'attr' => [
                        'placeholder' => 'Passwort wiederholen',
                        'class' => 'form-control',
                    ],
                ],
                'invalid_message' => 'Die Passwörter stimmen nicht überein',
                'mapped' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Registrieren',
                'attr' => [
                    'class' => 'btn btn-primary btn-lg w-100',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'constraints' => [
                new UniqueEntity([
                    'fields' => 'email',
                    'message' => 'Ein Benutzer mit dieser E-Mail-Adresse existiert bereits.'
                ])
            ],
        ]);
    }
}
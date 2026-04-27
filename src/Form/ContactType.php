<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class ContactType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Name',
                'attr' => ['placeholder' => 'Vor- und Nachname'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Bitte ausfüllen'),
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'E-Mail',
                'attr' => ['placeholder' => 'name@beispiel.de'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Bitte ausfüllen'),
                    new Assert\Email(message: 'Bitte gültige E-Mail Adresse angeben'),
                ],
            ])
            ->add('message', TextareaType::class, [
                'label' => 'Nachricht',
                'attr' => ['placeholder' => 'Schreibe hier deine Nachricht...'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Bitte ausfüllen'),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}

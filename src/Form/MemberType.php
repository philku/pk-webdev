<?php

namespace App\Form;

use App\Entity\Member;
use App\Entity\Team;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class MemberType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Name',
                'attr' => ['placeholder' => 'Vor- und Nachname'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'E-Mail',
                'attr' => ['placeholder' => 'name@beispiel.de'],
            ])
            ->add('phone', TelType::class, [
                'label' => 'Telefon',
                'required' => false,
                'attr' => ['placeholder' => '0171-1234567'],
            ])
            ->add('position', TextType::class, [
                'label' => 'Position',
                'required' => false,
                'attr' => ['placeholder' => 'z.B. Stürmer, Torwart'],
            ])
            ->add('role', ChoiceType::class, [
                'label' => 'Rolle',
                'choices' => [
                    'Spieler' => 'Spieler',
                    'Trainer' => 'Trainer',
                    'Betreuer' => 'Betreuer',
                    'Mitglied' => 'Mitglied',
                ],
                'placeholder' => 'Rolle auswählen...',
            ])
            ->add('team', EntityType::class, [
                'label' => 'Team',
                'class' => Team::class,
                'choice_label' => 'name',
                'placeholder' => 'Team auswählen...',
            ])
            ->add('joinedAt', DateType::class, [
                'label' => 'Beitrittsdatum',
                'required' => false,
                'widget' => 'single_text',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Member::class,
        ]);
    }
}

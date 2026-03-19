<?php

namespace App\Form;

use App\Entity\Team;
use App\Entity\Training;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// Classic handleRequest flow — contrasts MemberForm (Live Component) to demo both patterns.
class TrainingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('scheduledAt', DateTimeType::class, [
                'label' => 'Datum & Uhrzeit',
                'widget' => 'single_text',
            ])
            ->add('location', TextType::class, [
                'label' => 'Ort',
                'required' => false,
                'attr' => ['placeholder' => 'z.B. Sportplatz Süd'],
            ])
            ->add('description', TextType::class, [
                'label' => 'Beschreibung',
                'required' => false,
                'attr' => ['placeholder' => 'z.B. Taktiktraining'],
            ])
        ;

        // Team field hidden on edit — changing it would invalidate attendance records.
        if ($options['include_team']) {
            $builder->add('team', EntityType::class, [
                'label' => 'Team',
                'class' => Team::class,
                'choice_label' => 'name',
                'placeholder' => 'Team auswählen...',
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Training::class,
            'include_team' => true,
        ]);
    }
}

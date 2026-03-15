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

// Formular für Trainings — bewusst klassischer handleRequest-Flow.
// Im Kontrast zu MemberForm (Live Component) zeigt das im Portfolio
// beide Symfony-Patterns: klassisch vs. reaktiv.
class TrainingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // DateTimeType mit single_text: ein HTML5-Input statt 5 Dropdowns.
            // Der Browser zeigt automatisch einen Date/Time-Picker.
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

        // Team-Dropdown nur beim Erstellen anzeigen (nicht beim Bearbeiten).
        // Beim Edit ist das Team fix — sonst müssten die Attendances
        // gelöscht und für das neue Team neu erstellt werden.
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
            // Custom-Option: steuert ob das Team-Dropdown gezeigt wird.
            // Default true (Erstellen), beim Edit wird false übergeben.
            'include_team' => true,
        ]);
    }
}

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
            // TextType = normales Textfeld
            ->add('name', TextType::class, [
                'label' => 'Name',
                'attr' => ['placeholder' => 'Vor- und Nachname'],
            ])
            // EmailType = Textfeld mit type="email" (Browser validiert auch)
            ->add('email', EmailType::class, [
                'label' => 'E-Mail',
                'attr' => ['placeholder' => 'name@beispiel.de'],
            ])
            // TelType = Textfeld mit type="tel" (Mobile zeigt Ziffern-Tastatur)
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
            // ChoiceType = Dropdown mit festen Optionen
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
            // EntityType = Dropdown, das automatisch aus der DB befüllt wird!
            // Symfony holt alle Teams und zeigt sie als Optionen an.
            ->add('team', EntityType::class, [
                'label' => 'Team',
                'class' => Team::class,
                // choice_label = welches Feld des Team-Objekts als Text angezeigt wird
                'choice_label' => 'name',
                'placeholder' => 'Team auswählen...',
            ])
            // DateType = Datumsauswahl
            ->add('joinedAt', DateType::class, [
                'label' => 'Beitrittsdatum',
                'required' => false,
                'widget' => 'single_text', // Ein Feld statt 3 Dropdowns (Tag/Monat/Jahr)
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // data_class verbindet das Formular mit der Entity.
        // Beim Absenden werden die Werte automatisch in ein Member-Objekt geschrieben.
        $resolver->setDefaults([
            'data_class' => Member::class,
        ]);
    }
}

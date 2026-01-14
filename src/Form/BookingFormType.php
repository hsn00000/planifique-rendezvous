<?php

namespace App\Form;

use App\Entity\RendezVous;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class BookingFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('prenom', TextType::class, ['label' => 'Prénom'])
            ->add('nom', TextType::class, ['label' => 'Nom'])
            ->add('email', EmailType::class, ['label' => 'Email'])
            ->add('telephone', TelType::class, ['label' => 'Téléphone'])
            ->add('typeLieu', ChoiceType::class, [
                'label' => 'Où souhaitez-vous le rendez-vous ?',
                'choices'  => [
                    'Au cabinet (Présentiel)' => 'cabinet',
                    'En visioconférence' => 'visio',
                    'À mon domicile / Chez le bénéficiaire' => 'domicile',
                ],
                'expanded' => true, // Boutons radio
                'multiple' => false,
            ])
            ->add('adresse', TextType::class, [
                'label' => 'Adresse du domicile',
                'required' => false,
                'attr' => ['placeholder' => 'Saisir votre adresse complète']
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RendezVous::class,
        ]);
    }
}

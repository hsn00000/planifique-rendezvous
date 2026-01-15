<?php

namespace App\Form;

use App\Entity\RendezVous;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType; // Important
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

            // 👇 AJOUTE CES CHAMPS POUR QUE TON TEMPLATE FONCTIONNE
            ->add('typeLieu', ChoiceType::class, [
                'label' => 'Lieu du rendez-vous',
                'choices' => [
                    'Visioconférence' => 'Visioconférence',
                    'A domicile' => 'Domicile', // Le JS réagira au mot 'Domicile'
                    'Au bureau' => 'Bureau'
                ],
                'expanded' => false, // false = Liste déroulante (Select)
                'multiple' => false,
            ])
            ->add('adresse', TextType::class, [
                'label' => 'Adresse complète',
                'required' => false, // Important : false car masqué si Visio
                'attr' => ['placeholder' => '10 rue de la paix...']
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RendezVous::class,
        ]);
    }
}

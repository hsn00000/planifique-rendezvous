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
            ->add('prenom', TextType::class, [
                'label' => 'Prénom',
                'attr' => ['class' => 'form-control-lg', 'placeholder' => 'Jean']
            ])
            ->add('nom', TextType::class, [
                'label' => 'Nom',
                'attr' => ['class' => 'form-control-lg', 'placeholder' => 'Dupont']
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'attr' => ['class' => 'form-control-lg', 'placeholder' => 'jean.dupont@email.com']
            ])
            ->add('telephone', TelType::class, [
                'label' => 'Téléphone mobile',
                'attr' => ['class' => 'form-control-lg', 'placeholder' => '06 12 34 56 78']
            ])
            ->add('typeLieu', ChoiceType::class, [
                'label' => 'Préférence de lieu',
                'choices'  => [
                    'Visioconférence (Teams/Zoom)' => 'Visioconférence',
                    'A mon domicile / Bureau' => 'Domicile',
                    'Au cabinet de Genève' => 'Cabinet-geneve',
                    // Correction ici : utilisez des guillemets doubles ou échappez l'apostrophe
                    "Au cabinet d'Archamps" => 'Cabinet-archamps',
                ],
                'expanded' => false,
                'multiple' => false,
                'attr' => ['class' => 'form-select-lg']
            ])
            ->add('adresse', TextType::class, [
                'label' => 'Adresse du rendez-vous',
                'required' => false,
                'attr' => [
                    'class' => 'form-control-lg',
                    'placeholder' => 'Ex: 12 Avenue des Champs-Élysées, 75008 Paris'
                ],
                'help' => 'Indiquez le code d\'accès ou l\'étage si nécessaire.'
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

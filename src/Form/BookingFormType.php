<?php

namespace App\Form;

use App\Entity\Groupe;
use App\Entity\RendezVous;
use App\Entity\User;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
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
        $groupe = $options['groupe'];
        $isRoundRobin = $options['is_round_robin'];
        $cacherConseiller = $options['cacher_conseiller']; // Nouvelle option

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
            ]);

        // --- LOGIQUE D'AFFICHAGE DU CONSEILLER ---
        // On affiche la liste SEULEMENT SI on n'est pas en mode "Lien Perso" (cacher_conseiller = false)
        if (!$cacherConseiller) {
            $builder->add('conseiller', EntityType::class, [
                'class' => User::class,
                // Si Round Robin activé -> Optionnel. Sinon -> Obligatoire.
                'required' => !$isRoundRobin,
                'label' => $isRoundRobin ? 'Choisir un conseiller (Optionnel)' : 'Choisir un conseiller *',
                'placeholder' => $isRoundRobin ? 'Peu importe (Premier disponible)' : 'Veuillez sélectionner...',
                'attr' => ['class' => 'form-select-lg'],
                'choice_label' => fn (User $user) => $user->getFirstName() . ' ' . $user->getLastName(),
                'query_builder' => function (EntityRepository $er) use ($groupe) {
                    return $er->createQueryBuilder('u')
                        ->where('u.groupe = :groupe')
                        ->setParameter('groupe', $groupe)
                        ->orderBy('u.firstName', 'ASC');
                },
            ]);
        }
        // ------------------------------------------

        $builder
            ->add('typeLieu', ChoiceType::class, [
                'label' => 'Préférence de lieu',
                'choices'  => [
                    'Visioconférence (Teams/Zoom)' => 'Visioconférence',
                    'A mon domicile / Bureau' => 'Domicile',
                    'Au cabinet de Genève' => 'Cabinet-geneve',
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
            'groupe' => null,
            'is_round_robin' => true,
            'cacher_conseiller' => false, // Par défaut, on affiche le choix
        ]);

        $resolver->setAllowedTypes('groupe', ['null', Groupe::class]);
        $resolver->setAllowedTypes('is_round_robin', 'bool');
        $resolver->setAllowedTypes('cacher_conseiller', 'bool');
    }
}

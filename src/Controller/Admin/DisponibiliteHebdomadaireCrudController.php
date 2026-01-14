<?php

namespace App\Controller\Admin;

use App\Entity\DisponibiliteHebdomadaire;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters; // 👈 Important
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TimeField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter; // 👈 Important

class DisponibiliteHebdomadaireCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return DisponibiliteHebdomadaire::class;
    }

    // 👇 AJOUTE CETTE MÉTHODE POUR ACTIVER LE FILTRE
    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('user', 'Conseiller'));
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            AssociationField::new('user', 'Conseiller')
                ->formatValue(function ($value, $entity) {
                    return $entity->getUser() ? sprintf('%s %s (%s)', $entity->getUser()->getFirstName(), $entity->getUser()->getLastName(), $entity->getUser()->getEmail()) : 'Inconnu';
                })
                ->setSortable(true),

            ChoiceField::new('jourSemaine', 'Jour')
                ->setChoices([
                    'Lundi' => 1, 'Mardi' => 2, 'Mercredi' => 3, 'Jeudi' => 4,
                    'Vendredi' => 5, 'Samedi' => 6, 'Dimanche' => 7
                ])
                ->renderAsBadges([
                    1 => 'info', 5 => 'warning', 6 => 'success', 7 => 'danger'
                ]),

            TimeField::new('heureDebut', 'Début'),
            TimeField::new('heureFin', 'Fin'),

            BooleanField::new('estBloque', 'Verrouillé')
                ->renderAsSwitch(false)
        ];
    }
}

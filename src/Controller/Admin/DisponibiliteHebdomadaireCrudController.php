<?php

namespace App\Controller\Admin;

use App\Entity\DisponibiliteHebdomadaire;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TimeField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

class DisponibiliteHebdomadaireCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return DisponibiliteHebdomadaire::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Créneau')
            ->setEntityLabelInPlural('Planning Hebdomadaire')
            ->setDefaultSort(['jourSemaine' => 'ASC', 'heureDebut' => 'ASC']);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(BooleanFilter::new('estBloque', '🔒 Verrouillé ?'));

    }

    public function configureFields(string $pageName): iterable
    {
        return [
            // On affiche le conseiller en lecture seule pour rappel
            AssociationField::new('user', 'Conseiller')
                ->setFormTypeOption('disabled', 'disabled')
                ->setSortable(false),

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

            // Le switch pour bloquer/débloquer
            BooleanField::new('estBloque', 'Est Verrouillé')
                ->renderAsSwitch(false)
                ->setHelp('Si coché, le conseiller ne peut pas supprimer ce créneau.'),
        ];
    }
}

<?php

namespace App\Controller\Admin;

use App\Entity\Bureau;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class BureauCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Bureau::class;
    }

    // --- C'EST ICI QU'ON AJOUTE LE BOUTON HISTORIQUE ---
    public function configureActions(Actions $actions): Actions
    {
        // 1. Création de l'action personnalisée "Historique"
        $viewHistory = Action::new('viewHistory', 'Historique')
            ->setIcon('fas fa-history') // Icône horloge
            ->setLabel('Historique') // Le texte du bouton

            // 2. On lie le clic à la route de votre calendrier d'historique
            // (Il faut que la route 'admin_bureau_history_view' existe bien, voir étape précédente)
            ->linkToRoute('admin_bureau_history_view', function (Bureau $bureau) {
                return ['id' => $bureau->getId()];
            });

        return $actions
            // 3. On ajoute l'action à la page INDEX (la liste)
            ->add(Crud::PAGE_INDEX, $viewHistory)

            // 4. On réorganise l'ordre pour que ce soit joli : Historique | Modifier | Supprimer
            ->reorder(Crud::PAGE_INDEX, ['viewHistory', Action::EDIT, Action::DELETE]);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setPageTitle('index', 'Gestion des Salles')
            ->setEntityLabelInSingular('Salle')
            ->setEntityLabelInPlural('Salles')
            ->setDefaultSort(['lieu' => 'DESC', 'nom' => 'ASC']);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('lieu');
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),

            ChoiceField::new('lieu', 'Localisation')
                ->setChoices([
                    'Cabinet Genève' => 'Cabinet-geneve',
                    'Cabinet Archamps' => 'Cabinet-archamps',
                ])
                ->renderAsBadges([
                    'Cabinet-geneve' => 'success',
                    'Cabinet-archamps' => 'warning',
                ]),

            TextField::new('nom', 'Nom de la salle')
                ->setHelp('Ex: Salle de réunion 1, Bureau Bleu...'),

            EmailField::new('email', 'Email Outlook')
                ->setHelp("L'adresse email de la ressource Outlook."),
        ];
    }
}

<?php

namespace App\Controller\Admin;

use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;

class UserCrudController extends AbstractCrudController
{
    public function __construct(private AdminUrlGenerator $adminUrlGenerator) {}

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    // 👇 1. CONFIGURATION DE LA RECHERCHE ET DES LABELS
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Collaborateur')
            ->setEntityLabelInPlural('Collaborateurs')
            // Permet de chercher rapidement dans la barre de recherche
            ->setSearchFields(['firstName', 'lastName', 'email']);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            // Filtre par Entité (Génère une liste déroulante automatique avec tous les users)
            ->add(EntityFilter::new('id', 'Sélectionner un collaborateur') // On filtre sur l'ID mais on affiche le nom
            ->setFormTypeOption('value_type_options.class', User::class)
                ->setFormTypeOption('value_type_options.choice_label', function (User $u) {
                    return $u->__toString(); // Affiche "Prénom Nom"
                })
            );
    }

    public function configureActions(Actions $actions): Actions
    {
        // Bouton pour aller voir le planning
        $viewSchedule = Action::new('viewSchedule', 'Gérer le planning')
            ->setIcon('fas fa-calendar-alt')
            ->linkToUrl(function (User $user) {
                return $this->adminUrlGenerator
                    ->setController(DisponibiliteHebdomadaireCrudController::class)
                    ->setAction(Action::INDEX)
                    // On envoie le filtre vers l'autre contrôleur
                    ->set('filters', ['user' => ['value' => $user->getId(), 'comparison' => '=']])
                    ->generateUrl();
            });

        return $actions
            ->add(Crud::PAGE_INDEX, $viewSchedule);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            TextField::new('firstName', 'Prénom'),
            TextField::new('lastName', 'Nom'),
            EmailField::new('email'),
            AssociationField::new('groupe'),
            ArrayField::new('roles')->hideOnIndex(),
        ];
    }
}

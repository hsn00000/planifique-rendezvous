<?php

namespace App\Controller\Admin;

use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator; // 👈 Important

class UserCrudController extends AbstractCrudController
{
    // On injecte le générateur d'URL
    public function __construct(private AdminUrlGenerator $adminUrlGenerator) {}

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        // 👇 CRÉATION DU BOUTON "VOIR PLANNING"
        $viewSchedule = Action::new('viewSchedule', 'Gérer Planning', 'fas fa-calendar-alt')
            ->linkToUrl(function (User $user) {
                // On génère l'URL vers le contrôleur des dispos
                return $this->adminUrlGenerator
                    ->setController(DisponibiliteHebdomadaireCrudController::class)
                    ->setAction(Action::INDEX)
                    // On applique le filtre automatiquement pour n'afficher que CE user
                    ->set('filters', ['user' => ['value' => $user->getId(), 'comparison' => '=']])
                    ->generateUrl();
            })
            ->setCssClass('btn btn-outline-primary'); // Style du bouton

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

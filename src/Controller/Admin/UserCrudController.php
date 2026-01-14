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
        // Création de l'action personnalisée
        $viewSchedule = Action::new('viewSchedule', 'Planning Hebdo')
            ->setIcon('fas fa-calendar-alt') // Icône calendrier
            ->linkToUrl(function (User $user) {
                return $this->adminUrlGenerator
                    ->setController(DisponibiliteHebdomadaireCrudController::class)
                    ->setAction(Action::INDEX)
                    // Le filtre magique qui isole les horaires de CE user
                    ->set('filters', ['user' => ['value' => $user->getId(), 'comparison' => '=']])
                    ->generateUrl();
            })
            // 👇 Astuce UI : On ne met pas de classe CSS lourde, on laisse EasyAdmin gérer
            ->setHtmlAttributes(['title' => 'Gérer les disponibilités de ce conseiller']);

        return $actions
            // On ajoute le bouton sur la ligne de chaque utilisateur (PAGE_INDEX)
            ->add(Crud::PAGE_INDEX, $viewSchedule)

            // Optionnel : on le met aussi sur la page de détail si tu l'utilises
            ->add(Crud::PAGE_DETAIL, $viewSchedule);
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

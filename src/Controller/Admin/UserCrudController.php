<?php

namespace App\Controller\Admin;

use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField; // Important
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class UserCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureFields(string $pageName): iterable
    {
        // On configure les colonnes visibles
        return [
            IdField::new('id')->hideOnForm(), // On cache l'ID dans le formulaire de création
            TextField::new('firstName', 'Prénom'),
            TextField::new('lastName', 'Nom'),
            EmailField::new('email', 'Email'),

            // 👇 C'est ici que ça se passe : Afficher le Groupe
            AssociationField::new('groupe', 'Groupe d\'appartenance')
                ->setRequired(false) // Permet d'avoir un user sans groupe (au début)
                ->autocomplete(),    // Rend la sélection plus jolie (recherche)
        ];
    }
}

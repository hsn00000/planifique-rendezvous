<?php

namespace App\Controller\Admin;

use App\Entity\Evenement;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField; // AJOUTÉ
use EasyCorp\Bundle\EasyAdminBundle\Field\ColorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class EvenementCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Evenement::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            TextField::new('titre', 'Nom de l\'événement'),
            AssociationField::new('groupe', 'Groupe assigné'),

            // 👇 Le nouvel interrupteur
            BooleanField::new('isRoundRobin', 'Round Robin (Distribution Auto)')
                ->setHelp('Si activé, le lien ne sera pas lié à un conseiller spécifique, mais distribué à l\'équipe.'),

            IntegerField::new('duree', 'Durée (min)'),
            ColorField::new('couleur', 'Couleur'),
            TextEditorField::new('description')->hideOnIndex(),
        ];
    }
}

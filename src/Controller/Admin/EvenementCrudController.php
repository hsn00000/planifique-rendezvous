<?php

namespace App\Controller\Admin;

use App\Entity\Evenement;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ColorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;

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

            // On a supprimé visioUrl ici
            BooleanField::new('isRoundRobin', 'Round Robin (Distribution Auto)')
                ->setHelp('Si activé, le rendez-vous sera attribué automatiquement à un membre de l\'équipe.'),

            IntegerField::new('duree', 'Durée (min)'),
            IntegerField::new('delaiPrevention', 'Délai minimum avant RDV (min)')
                ->setHelp('Empêche les réservations de dernière minute. Ex: mettez "60" pour interdire les RDV dans moins d\'une heure.'),
            ColorField::new('couleur', 'Couleur'),

            TextEditorField::new('description')->hideOnIndex(),

            DateField::new('dateLimite', 'Date limite de réservation')->setHelp('Après cette date, l\'événement ne sera plus proposé.'),
        ];
    }
}

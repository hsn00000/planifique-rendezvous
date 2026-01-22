<?php

namespace App\Controller\Admin;

use App\Entity\Evenement;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ColorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
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
            IdField::new('id')->hideOnForm(),

            FormField::addPanel('Informations Générales'),
            TextField::new('titre', 'Titre de l\'événement'),
            TextField::new('slug', 'Slug')->hideOnIndex(),
            IntegerField::new('duree', 'Durée (minutes)'),
            ColorField::new('couleur', 'Couleur'),

            FormField::addPanel('Gestion des Pauses (Tampons)'),
            IntegerField::new('tamponAvant', 'Pause AVANT (min)')
                ->setHelp('Temps bloqué avant le début pour la préparation.')
                ->setColumns(6),
            IntegerField::new('tamponApres', 'Pause APRES (min)')
                ->setHelp('Temps bloqué après la fin pour le debriefing ou le trajet.')
                ->setColumns(6),
            IntegerField::new('delaiMinimumReservation', 'Délai minimum de réservation (min)')
                ->setHelp('Délai minimum en minutes avant de pouvoir réserver un créneau (ex: 120 = 2h). Les créneaux dans ce délai ne seront pas affichés.')
                ->setColumns(6),

            FormField::addPanel('Options'),
            AssociationField::new('groupe', 'Groupe associé'),
            BooleanField::new('isRoundRobin', 'Attribution Auto (Round Robin)')
                ->setHelp('Si coché, le client ne choisit pas le conseiller.'),

            DateField::new('dateLimite', 'Date Limite (Optionnel)')
                ->setHelp('Aucun RDV ne pourra être pris après cette date.'),

            TextEditorField::new('description', 'Description'),
        ];
    }
}

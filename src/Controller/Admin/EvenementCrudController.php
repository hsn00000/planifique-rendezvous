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
                ->setHelp('Temps minimum (en minutes) entre maintenant et le début d\'un créneau pour qu\'il soit proposé. Exemple : si vous mettez 120 (2h) et qu\'il est 10h00, seuls les créneaux à partir de 12h00 seront affichés. Les créneaux entre 10h00 et 12h00 seront masqués. Utile pour éviter les réservations de dernière minute et laisser le temps de préparation. Mettez 0 pour autoriser toutes les réservations futures.')
                ->setColumns(6),
            IntegerField::new('limiteMoisReservation', 'Limite de réservation (mois)')
                ->setHelp('Nombre maximum de mois à l\'avance pour les réservations. Exemple : 12 = les clients peuvent réserver jusqu\'à 12 mois à l\'avance. Par défaut : 12 mois.')
                ->setColumns(6),

            FormField::addPanel('Options'),
            AssociationField::new('groupe', 'Groupe associé'),
            BooleanField::new('isRoundRobin', 'Attribution Auto (Round Robin)')
                ->setHelp('Si coché, le client ne choisit pas le conseiller.'),

            TextEditorField::new('description', 'Description'),
        ];
    }
}

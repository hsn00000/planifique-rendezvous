<?php

namespace App\Controller\Admin;

use App\Entity\Bureau;
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

    // 1. CONFIGURATION DU TRI (Pour ne plus que ce soit mélangé)
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setPageTitle('index', 'Gestion des Salles')
            ->setEntityLabelInSingular('Salle')
            ->setEntityLabelInPlural('Salles')
            // C'est ici que la magie opère : on trie d'abord par Lieu, puis par Nom
            ->setDefaultSort(['lieu' => 'DESC', 'nom' => 'ASC']);
    }

    // 2. CONFIGURATION DES FILTRES (Menu de droite)
    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('lieu'); // Permet de filtrer "Uniquement Genève" ou "Uniquement Archamps"
    }

    // 3. CONFIGURATION DES CHAMPS (Visuel)
    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),

            // On met le LIEU en premier pour bien voir le groupe
            ChoiceField::new('lieu', 'Localisation')
                ->setChoices([
                    'Cabinet Genève' => 'Cabinet-geneve',
                    'Cabinet Archamps' => 'Cabinet-archamps',
                ])
                // Les badges mettent de la couleur pour distinguer les groupes
                ->renderAsBadges([
                    'Cabinet-geneve' => 'success',  // Vert
                    'Cabinet-archamps' => 'warning', // Jaune/Orange
                ]),

            TextField::new('nom', 'Nom de la salle')
                ->setHelp('Ex: Salle de réunion 1, Bureau Bleu...'),

            EmailField::new('email', 'Email Outlook')
                ->setHelp("L'adresse email de la ressource Outlook."),
        ];
    }
}

<?php

namespace App\Controller\Admin;

use App\Entity\Evenement;
use App\Entity\Groupe;
use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractDashboardController
{
    #[Route('/admin', name: 'admin')]
    public function index(): Response
    {
        // Option 1 : Rediriger directement vers la liste des Utilisateurs (souvent plus utile)
        // return $this->redirect($this->container->get(AdminUrlGenerator::class)->setController(UserCrudController::class)->generateUrl());

        // Option 2 : Afficher une page d'accueil avec des liens (ce que je te propose ici)
        return $this->render('admin/dashboard.html.twig');
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('<img src="img/logo.png" height="30"> Planifique Admin')
            ->setFaviconPath('img/logo.png');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Vue d\'ensemble', 'fa fa-home');

        yield MenuItem::section('Organisation');
        yield MenuItem::linkToCrud('Groupes', 'fas fa-users-cog', Groupe::class);
        yield MenuItem::linkToCrud('Utilisateurs', 'fas fa-user', User::class);

        yield MenuItem::section('Configuration');
        yield MenuItem::linkToCrud('Types d\'Événements', 'fas fa-calendar-alt', Evenement::class);

        yield MenuItem::section('Navigation');
        yield MenuItem::linkToRoute('Retour au Site', 'fas fa-arrow-left', 'app_home');
    }
}

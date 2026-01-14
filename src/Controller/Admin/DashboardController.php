<?php

namespace App\Controller\Admin;

use App\Entity\DisponibiliteHebdomadaire;
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
        return $this->render('admin/dashboard.html.twig');
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('<img src="/img/logo.png" style="height: 35px; margin-right: 10px; vertical-align: text-bottom;"> Planifique <span style="font-size: 0.8em; color: #777;">Admin</span>')
            ->setFaviconPath('img/logo.png')
            ->renderContentMaximized();
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Vue d\'ensemble', 'fa fa-home');

        yield MenuItem::section('Gestion');
        yield MenuItem::linkToCrud('Groupes', 'fas fa-users', Groupe::class);
        yield MenuItem::linkToCrud('Types d\'Événements', 'fas fa-calendar-check', Evenement::class);

        // C'est ici que tout se passe maintenant :
        yield MenuItem::linkToCrud('Collaborateurs (Dossiers)', 'fas fa-user-tie', User::class);

        yield MenuItem::section('Liens');
        yield MenuItem::linkToRoute('Retour au site', 'fas fa-arrow-left', 'app_home');
    }
}

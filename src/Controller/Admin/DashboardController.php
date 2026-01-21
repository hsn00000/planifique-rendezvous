<?php

namespace App\Controller\Admin;

use App\Entity\Bureau; // N'oublie pas cet import
use App\Entity\DisponibiliteHebdomadaire;
use App\Entity\Evenement;
use App\Entity\Groupe;
use App\Entity\User;
use App\Repository\EvenementRepository;
use App\Repository\GroupeRepository;
use App\Repository\UserRepository;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractDashboardController
{
    // On garde ton constructeur tel quel
    public function __construct(
        private UserRepository $userRepository,
        private EvenementRepository $evenementRepository,
        private GroupeRepository $groupeRepository
    ) {}

    #[Route('/admin', name: 'admin')]
    public function index(): Response
    {
        // On garde tes statistiques
        $stats = [
            'users' => $this->userRepository->count([]),
            'events' => $this->evenementRepository->count([]),
            'groupes' => $this->groupeRepository->count([]),
        ];

        return $this->render('admin/dashboard.html.twig', [
            'stats' => $stats
        ]);
    }

    public function configureDashboard(): Dashboard
    {
        // On garde ton joli titre avec le logo
        return Dashboard::new()
            ->setTitle('<img src="/img/logo.png" style="height: 35px; margin-right: 10px; vertical-align: text-bottom;"> Planifique <span style="font-size: 0.8em; color: #777;">Admin</span>')
            ->setFaviconPath('img/logo.png')
            ->renderContentMaximized()
            ->disableDarkMode();
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Vue d\'ensemble', 'fa fa-home');

        // --- SECTION PLANNING ---
        yield MenuItem::section('Gestion Planning');
        yield MenuItem::linkToCrud('Événements', 'fas fa-calendar-check', Evenement::class);
        yield MenuItem::linkToCrud('Groupes', 'fas fa-users', Groupe::class);
        yield MenuItem::linkToCrud('Collaborateurs', 'fas fa-user-tie', User::class);

        // --- SECTION LIEUX (DOSSIER) ---
        // C'est ici qu'on crée l'effet "Dossier" visuel
        yield MenuItem::section('Infrastructures');

        yield MenuItem::subMenu('Bureaux / Salles', 'fas fa-building')->setSubItems([
            // Le lien pointe vers le CRUD Bureau (qui est maintenant trié par Genève/Archamps)
            MenuItem::linkToCrud('Gérer les Salles', 'fas fa-list', Bureau::class),
        ]);

        // --- LIENS ---
        yield MenuItem::section('Liens');
        yield MenuItem::linkToRoute('Retour au site', 'fas fa-arrow-left', 'app_home');
    }
}

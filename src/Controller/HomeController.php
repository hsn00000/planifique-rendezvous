<?php

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        // 1. On récupère l'utilisateur connecté
        /** @var User $user */
        $user = $this->getUser();

        $evenements = [];

        // 2. S'il est connecté et qu'il a un groupe
        if ($user && $user->getGroupe()) {
            // 3. On récupère les événements de SON groupe
            $evenements = $user->getGroupe()->getEvenements();
        }

        // 4. On envoie la variable 'evenements' à la vue
        return $this->render('home/index.html.twig', [
            'evenements' => $evenements,
        ]);
    }
}

<?php

namespace App\Controller;

use App\Entity\DisponibiliteHebdomadaire;
use App\Repository\DisponibiliteHebdomadaireRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/mon-agenda')]
class DisponibiliteController extends AbstractController
{
    private const JOURS = [
        1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi',
        4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'
    ];

    #[Route('/', name: 'app_dispo_index')]
    public function index(DisponibiliteHebdomadaireRepository $repo): Response
    {
        $dispos = $repo->findBy(['user' => $this->getUser()], ['jourSemaine' => 'ASC', 'heureDebut' => 'ASC']);

        return $this->render('disponibilite/index.html.twig', [
            'dispos' => $dispos,
            'libellesJours' => self::JOURS
        ]);
    }

    #[Route('/ajouter', name: 'app_dispo_add', methods: ['POST'])]
    public function add(Request $request, EntityManagerInterface $em): Response
    {
        $jour = $request->request->get('jour');
        $start = $request->request->get('start');
        $end = $request->request->get('end');

        if ($jour && $start && $end) {
            $dispo = new DisponibiliteHebdomadaire();
            $dispo->setUser($this->getUser());
            $dispo->setJourSemaine((int)$jour);
            $dispo->setHeureDebut(new \DateTime($start));
            $dispo->setHeureFin(new \DateTime($end));

            $em->persist($dispo);
            $em->flush();
            $this->addFlash('success', 'Horaire ajouté au planning hebdomadaire.');
        }

        return $this->redirectToRoute('app_dispo_index');
    }

    #[Route('/supprimer/{id}', name: 'app_dispo_delete')]
    public function delete(DisponibiliteHebdomadaire $dispo, EntityManagerInterface $em): Response
    {
        // Vérification 1 : C'est bien l'utilisateur connecté
        if ($dispo->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        // Vérification 2 : LE CRÉNEAU EST-IL BLOQUÉ ?
        if ($dispo->isEstBloque()) {
            // Message d'erreur
            $this->addFlash('danger', 'Impossible de supprimer ce créneau : il a été verrouillé par l\'administration.');
            return $this->redirectToRoute('app_dispo_index');
        }

        $em->remove($dispo);
        $em->flush();
        $this->addFlash('info', 'Horaire supprimé.');

        return $this->redirectToRoute('app_dispo_index');
    }
}

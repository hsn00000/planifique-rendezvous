<?php

namespace App\Controller;

use App\Entity\Evenement;
use App\Entity\RendezVous;
use App\Entity\User;
use App\Form\BookingFormType;
use App\Repository\DisponibiliteRepository;
use App\Repository\RendezVousRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class BookingController extends AbstractController
{
    // CAS 1 : Lien Personnel (On connaît le conseiller)
    #[Route('/book/{user}/{event}', name: 'app_booking_personal')]
    public function bookPersonal(User $user, Evenement $event, RendezVousRepository $rdvRepo): Response
    {
        // On génère les créneaux pour les 14 prochains jours
        $availableSlots = $this->generateSlots($user, $event, $rdvRepo);

        return $this->render('booking/index.html.twig', [
            'conseiller' => $user,
            'event' => $event,
            'isRoundRobin' => false,
            'slotsByDay' => $availableSlots
        ]);
    }

    // CAS 2 : Lien Round Robin (L'équipe)
    #[Route('/book/team/{event}', name: 'app_booking_roundrobin')]
    public function bookRoundRobin(Evenement $event, RendezVousRepository $rdvRepo): Response
    {
        // Pour le Round Robin, on peut par exemple prendre le premier conseiller du groupe
        // ou adapter la logique de génération pour vérifier la disponibilité globale.
        $user = $event->getGroupe()->getUsers()->first() ?: null;
        $availableSlots = $user ? $this->generateSlots($user, $event, $rdvRepo) : [];

        return $this->render('booking/index.html.twig', [
            'conseiller' => null,
            'event' => $event,
            'isRoundRobin' => true,
            'slotsByDay' => $availableSlots
        ]);
    }

    // ÉTAPE 2 : Formulaire de confirmation
    #[Route('/book/confirm/{event}/{user?}', name: 'app_booking_confirm')]
    public function confirm(Request $request, Evenement $event, ?User $user, EntityManagerInterface $em): Response
    {
        $rendezVous = new RendezVous();
        $rendezVous->setEvenement($event);
        if ($user) $rendezVous->setConseiller($user);

        // Récupération de la date passée en paramètre URL (ex: ?date=2026-01-14 10:30)
        $dateStr = $request->query->get('date');
        if ($dateStr) {
            $rendezVous->setDateDebut(new \DateTime($dateStr));
        }

        $form = $this->createForm(BookingFormType::class, $rendezVous);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($rendezVous);
            $em->flush();

            return $this->render('booking/success.html.twig', [
                'rendezVous' => $rendezVous
            ]);
        }

        return $this->render('booking/confirm.html.twig', [
            'event' => $event,
            'conseiller' => $user,
            'form' => $form->createView(),
            'isRoundRobin' => $event->isRoundRobin()
        ]);
    }

    /**
     * Logique de génération des créneaux de 9h à 18h
     */
    private function generateSlots(User $user, Evenement $event, DisponibiliteRepository $dispoRepo, RendezVousRepository $rdvRepo): array
    {
        $slotsByDay = [];
        $duration = $event->getDuree();

        // 1. Récupérer uniquement les créneaux avec le statut 'disponible' pour ce conseiller
        $disponibilites = $dispoRepo->findBy([
            'conseiller' => $user,
            'statut' => 'disponible'
        ]);

        foreach ($disponibilites as $dispo) {
            $start = clone $dispo->getDateDebut();
            $end = $dispo->getDateFin();
            $dayKey = $start->format('Y-m-d');

            // 2. Découper ces créneaux en fonction de la durée de l'événement
            while ($start < $end) {
                $slotStart = clone $start;
                $slotEnd = (clone $start)->modify("+$duration minutes");

                // 3. Vérifier si le créneau n'est pas déjà pris par un RendezVous existant
                $isBusy = $rdvRepo->findOneBy([
                    'conseiller' => $user,
                    'dateDebut' => $slotStart
                ]);

                if (!$isBusy && $slotEnd <= $end) {
                    $slotsByDay[$dayKey][] = $slotStart->format('H:i');
                }
                $start->modify("+$duration minutes");
            }
        }
        return $slotsByDay;
    }
}

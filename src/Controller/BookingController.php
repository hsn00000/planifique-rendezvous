<?php

namespace App\Controller;

use App\Entity\Evenement;
use App\Entity\RendezVous;
use App\Entity\User;
use App\Form\BookingFormType;
use App\Repository\DisponibiliteHebdomadaireRepository;
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
    public function bookPersonal(User $user, Evenement $event, RendezVousRepository $rdvRepo, DisponibiliteHebdomadaireRepository $dispoRepo): Response
    {
        // 👇 On passe le dispoRepo à la fonction
        $availableSlots = $this->generateSlots($user, $event, $rdvRepo, $dispoRepo);

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
    /**
     * Génère les créneaux basés sur le planning HEBDOMADAIRE
     */
    private function generateSlots(User $user, Evenement $event, RendezVousRepository $rdvRepo, DisponibiliteHebdomadaireRepository $dispoRepo): array
    {
        $calendarData = [];
        $duration = $event->getDuree();

        // On commence au 1er du mois actuel
        $startPeriod = new \DateTime('first day of this month');
        // On affiche ce mois-ci et le mois suivant (2 mois de visibilité)
        $endPeriod = (clone $startPeriod)->modify('+2 months')->modify('-1 day');

        // 1. Récupération des règles hebdo du conseiller
        $disposHebdo = $dispoRepo->findBy(['user' => $user]);
        $rulesByDay = [];
        foreach ($disposHebdo as $dispo) {
            $rulesByDay[$dispo->getJourSemaine()][] = $dispo; // 1 = Lundi, 7 = Dimanche
        }

        // 2. Boucle jour par jour
        $currentDate = clone $startPeriod;

        while ($currentDate <= $endPeriod) {
            $monthKey = $currentDate->format('F Y'); // Ex: "Janvier 2026"
            $dayDate = $currentDate->format('Y-m-d');
            $dayOfWeek = (int)$currentDate->format('N');

            // Initialisation du mois si inexistant
            if (!isset($calendarData[$monthKey])) {
                $calendarData[$monthKey] = [
                    'label' => $monthKey,
                    'days' => []
                ];
            }

            // Structure du jour
            $dayData = [
                'dateObj' => clone $currentDate,
                'dayNum' => $currentDate->format('d'),
                'isToday' => $dayDate === (new \DateTime())->format('Y-m-d'),
                'isPast' => $currentDate < new \DateTime('today'),
                'slots' => [],
                'hasAvailability' => false
            ];

            // Si c'est un jour futur et qu'il y a une règle pour ce jour de la semaine
            if (!$dayData['isPast'] && isset($rulesByDay[$dayOfWeek])) {
                foreach ($rulesByDay[$dayOfWeek] as $rule) {
                    if ($rule->isEstBloque()) continue; // On saute si bloqué par l'admin

                    // Création des bornes pour CE jour précis
                    $start = (clone $currentDate)->setTime(
                        (int)$rule->getHeureDebut()->format('H'),
                        (int)$rule->getHeureDebut()->format('i')
                    );
                    $end = (clone $currentDate)->setTime(
                        (int)$rule->getHeureFin()->format('H'),
                        (int)$rule->getHeureFin()->format('i')
                    );

                    // Découpage en créneaux
                    while ($start < $end) {
                        $slotEnd = (clone $start)->modify("+$duration minutes");
                        if ($slotEnd > $end) break;

                        // Vérif conflit RDV existant
                        $isBusy = $rdvRepo->findOneBy([
                            'conseiller' => $user,
                            'dateDebut' => $start
                        ]);

                        if (!$isBusy) {
                            $dayData['slots'][] = $start->format('H:i');
                            $dayData['hasAvailability'] = true;
                        }
                        $start = $slotEnd;
                    }
                }
            }

            $calendarData[$monthKey]['days'][] = $dayData;
            $currentDate->modify('+1 day');
        }

        return $calendarData;
    }
}

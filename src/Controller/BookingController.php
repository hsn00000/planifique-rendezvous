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
        $slotsByDay = $this->generateSlots($user, $event, $rdvRepo, $dispoRepo);

        return $this->render('booking/index.html.twig', [
            'conseiller' => $user,
            'event' => $event,
            'isRoundRobin' => false,
            'slotsByDay' => $slotsByDay
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
     * Génère les créneaux basés sur le planning HEBDOMADAIRE
     */
    private function generateSlots(User $user, Evenement $event, RendezVousRepository $rdvRepo, DisponibiliteHebdomadaireRepository $dispoRepo): array
    {
        $calendarData = [];
        $duration = $event->getDuree();

        // 1. DÉBUT : 1er du mois actuel
        $startPeriod = new \DateTime('first day of this month');

        // 2. FIN : Logique intelligente
        $dateLimite = $event->getDateLimite();

        if ($dateLimite) {
            // Si une date limite existe, on s'arrête exactement à cette date
            // On clone pour éviter de modifier l'objet original
            $endPeriod = clone $dateLimite;

            // Sécurité : si la date limite est passée, on ne montre rien
            if ($endPeriod < new \DateTime('today')) {
                return [];
            }
        } else {
            // Si PAS de date limite, on ouvre sur 1 AN glissant (12 mois)
            $endPeriod = (clone $startPeriod)->modify('+12 months')->modify('last day of this month');
        }

        $disposHebdo = $dispoRepo->findBy(['user' => $user]);
        $rulesByDay = [];
        foreach ($disposHebdo as $dispo) {
            $rulesByDay[$dispo->getJourSemaine()][] = $dispo;
        }

        $currentDate = clone $startPeriod;

        // On boucle jour par jour jusqu'à la fin de la période
        while ($currentDate <= $endPeriod) {

            // Clé de tri Y-m (ex: 2026-05) pour l'ordre chronologique
            $sortKey = $currentDate->format('Y-m');

            if (!isset($calendarData[$sortKey])) {
                $calendarData[$sortKey] = [
                    'label' => clone $currentDate,
                    'days' => []
                ];
            }

            $dayData = [
                'dateObj' => clone $currentDate,
                'dayNum' => $currentDate->format('d'),
                'isToday' => $currentDate->format('Y-m-d') === (new \DateTime())->format('Y-m-d'),
                'isPast' => $currentDate < new \DateTime('today'),
                'slots' => [],
                'hasAvailability' => false
            ];

            // Calcul des créneaux
            if (!$dayData['isPast']) {
                $dayOfWeek = (int)$currentDate->format('N');

                if (isset($rulesByDay[$dayOfWeek])) {
                    foreach ($rulesByDay[$dayOfWeek] as $rule) {
                        if ($rule->isEstBloque()) continue;

                        $start = (clone $currentDate)->setTime((int)$rule->getHeureDebut()->format('H'), (int)$rule->getHeureDebut()->format('i'));
                        $end = (clone $currentDate)->setTime((int)$rule->getHeureFin()->format('H'), (int)$rule->getHeureFin()->format('i'));

                        while ($start < $end) {
                            $slotEnd = (clone $start)->modify("+$duration minutes");
                            if ($slotEnd > $end) break;

                            $isBusy = $rdvRepo->findOneBy(['conseiller' => $user, 'dateDebut' => $start]);
                            if (!$isBusy) {
                                $dayData['slots'][] = $start->format('H:i');
                                $dayData['hasAvailability'] = true;
                            }
                            $start = $slotEnd;
                        }
                    }
                }
            }

            $calendarData[$sortKey]['days'][] = $dayData;
            $currentDate->modify('+1 day');
        }

        ksort($calendarData); // Tri chronologique des mois

        // On nettoie : si un mois n'a AUCUNE disponibilité (tout vide), on pourrait le laisser
        // mais c'est mieux de l'afficher pour montrer la continuité.
        return array_values($calendarData);
    }
}

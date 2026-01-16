<?php

namespace App\Controller;

use App\Entity\Evenement;
use App\Entity\Groupe;
use App\Entity\RendezVous;
use App\Entity\User;
use App\Form\BookingFormType;
use App\Repository\DisponibiliteHebdomadaireRepository;
use App\Repository\EvenementRepository;
use App\Repository\RendezVousRepository;
use App\Repository\UserRepository;
use App\Service\OutlookService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Routing\Attribute\Route;

class BookingController extends AbstractController
{

    /**
     * Logique de sélection intelligente (Helper)
     */
    private function findAvailableConseiller($groupe, \DateTime $start, int $duree, $rdvRepo, $outlookService): ?User
    {
        $conseillers = $groupe->getUsers()->toArray();
        shuffle($conseillers); // Pour l'équité du Round Robin
        $slotEnd = (clone $start)->modify("+$duree minutes");

        foreach ($conseillers as $conseiller) {
            // 1. Limite de 3 RDV par jour
            if ($rdvRepo->countRendezVousForUserOnDate($conseiller, $start) >= 3) continue;

            // 2. Disponibilité interne (BDD)
            if (!$rdvRepo->isSlotAvailable($conseiller, $start, $slotEnd)) continue;

            // 3. Disponibilité externe (Outlook)
            $busy = $outlookService->getOutlookBusyPeriods($conseiller, $start);
            $freeOnOutlook = true;
            foreach ($busy as $period) {
                if ($start < $period['end'] && $slotEnd > $period['start']) {
                    $freeOnOutlook = false;
                    break;
                }
            }

            if ($freeOnOutlook) return $conseiller;
        }

        return null;
    }

    /**
     * ÉTAPE 1 : Confirmation et Enregistrement
     * Doit être placée AVANT la route générique pour éviter les conflits.
     */
    #[Route('/book/confirm/{eventId}/{userId?}', name: 'app_booking_confirm')]
    public function confirm(
        Request $request,
        string $eventId,
        ?string $userId,
        EvenementRepository $eventRepo,
        UserRepository $userRepo,
        RendezVousRepository $rdvRepo,
        EntityManagerInterface $em,
        MailerInterface $mailer,
        OutlookService $outlookService
    ): Response
    {
        $event = $eventRepo->find($eventId);
        if (!$event) return $this->redirectToRoute('app_home');

        $user = $userId ? $userRepo->find($userId) : null;
        $rendezVous = new RendezVous();
        $rendezVous->setEvenement($event);

        // Gestion de la date
        $dateParam = $request->query->get('date');
        $startDate = null;
        if ($dateParam) {
            try {
                $startDate = new \DateTime($dateParam);
                $rendezVous->setDateDebut($startDate);
                $rendezVous->setDateFin((clone $startDate)->modify('+' . $event->getDuree() . ' minutes'));
            } catch (\Exception $e) {
                return $this->redirectToRoute('app_home');
            }
        }

        // --- LOGIQUE ROUND ROBIN & CONTRAINTES ---
        // Si pas de conseiller sélectionné et que l'événement est en Round Robin
        if (!$user && $event->isRoundRobin() && $startDate) {
            $user = $this->findAvailableConseiller(
                $event->getGroupe(),
                $startDate,
                $event->getDuree(),
                $rdvRepo,
                $outlookService
            );

            if (!$user) {
                $this->addFlash('danger', 'Aucun conseiller n\'est disponible pour ce créneau (limite de 3 RDV atteinte ou agenda plein).');
                return $this->redirectToRoute('app_home');
            }
        }

        if ($user) $rendezVous->setConseiller($user);
        $rendezVous->setTypeLieu('Visioconférence');

        $form = $this->createForm(BookingFormType::class, $rendezVous);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($rendezVous);
            $em->flush();

            try {
                if ($rendezVous->getConseiller()) {
                    $outlookService->addEventToCalendar($rendezVous->getConseiller(), $rendezVous);
                }
            } catch (\Exception $e) {}

            $this->sendConfirmationEmails($mailer, $rendezVous, $event);

            return $this->redirectToRoute('app_booking_success', ['id' => $rendezVous->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('booking/confirm.html.twig', [
            'form' => $form->createView(),
            'event' => $event,
            'conseiller' => $user,
            'dateChoisie' => $rendezVous->getDateDebut(),
            'rendezvous' => $rendezVous
        ]);
    }

    /**
     * ÉTAPE 2 : Page de Succès
     * Accessible uniquement après redirection.
     */
    #[Route('/book/success/{id}', name: 'app_booking_success')]
    public function success(RendezVous $rendezVous): Response
    {
        return $this->render('booking/success.html.twig', [
            'rendezvous' => $rendezVous
        ]);
    }

    /**
     * ÉTAPE 3 : Calendrier de Réservation (Route Générique)
     * Placée EN DERNIER car elle contient des {paramètres} qui captent tout.
     */
    #[Route('/book/{userId}/{eventId}', name: 'app_booking_personal', requirements: ['userId' => '\d+', 'eventId' => '\d+'])]
    public function bookPersonal(
        string $userId,
        string $eventId,
        UserRepository $userRepo,
        EvenementRepository $eventRepo,
        RendezVousRepository $rdvRepo,
        DisponibiliteHebdomadaireRepository $dispoRepo
    ): Response
    {
        // Recherche manuelle
        $user = $userRepo->find($userId);
        $event = $eventRepo->find($eventId);

        if (!$user || !$event) {
            throw $this->createNotFoundException("Conseiller ou événement introuvable.");
        }

        $slotsByDay = $this->generateSlots($user, $event, $rdvRepo, $dispoRepo);

        return $this->render('booking/index.html.twig', [
            'conseiller' => $user,
            'event' => $event,
            'slotsByDay' => $slotsByDay
        ]);
    }

    // --- MÉTHODES PRIVÉES ---

    private function sendConfirmationEmails($mailer, $rdv, $event): void
    {
        $emailClient = new TemplatedEmail()
            ->from('no-reply@planifique.com')
            ->to($rdv->getEmail())
            ->subject('Confirmation RDV : ' . $event->getTitre())
            ->htmlTemplate('emails/booking_confirmation_client.html.twig')
            ->context(['rdv' => $rdv]);

        try {
            $mailer->send($emailClient);
        } catch (\Exception $e) {}
    }

    private function generateSlots(User $user, Evenement $event, RendezVousRepository $rdvRepo, DisponibiliteHebdomadaireRepository $dispoRepo): array
    {
        $calendarData = [];
        $duration = $event->getDuree();
        $startPeriod = new \DateTime('first day of this month');
        $dateLimite = $event->getDateLimite();

        if ($dateLimite) {
            $endPeriod = clone $dateLimite;
            if ($endPeriod < new \DateTime('today')) return [];
        } else {
            $endPeriod = (clone $startPeriod)->modify('+12 months')->modify('last day of this month');
        }

        $disposHebdo = $dispoRepo->findBy(['user' => $user]);
        $rulesByDay = [];
        foreach ($disposHebdo as $dispo) {
            $rulesByDay[$dispo->getJourSemaine()][] = $dispo;
        }

        $currentDate = clone $startPeriod;
        while ($currentDate <= $endPeriod) {
            $sortKey = $currentDate->format('Y-m');
            if (!isset($calendarData[$sortKey])) {
                $calendarData[$sortKey] = ['label' => clone $currentDate, 'days' => []];
            }
            $dayOfWeek = (int)$currentDate->format('N');
            $dayData = [
                'dateObj' => clone $currentDate,
                'dayNum' => $currentDate->format('d'),
                'isToday' => $currentDate->format('Y-m-d') === (new \DateTime())->format('Y-m-d'),
                'isPast' => $currentDate < new \DateTime('today'),
                'slots' => [],
                'hasAvailability' => false
            ];

            if (!$dayData['isPast']) {
                if (isset($rulesByDay[$dayOfWeek])) {
                    foreach ($rulesByDay[$dayOfWeek] as $rule) {
                        if ($rule->isEstBloque()) continue;
                        $start = (clone $currentDate)->setTime((int)$rule->getHeureDebut()->format('H'), (int)$rule->getHeureDebut()->format('i'));
                        $end = (clone $currentDate)->setTime((int)$rule->getHeureFin()->format('H'), (int)$rule->getHeureFin()->format('i'));
                        while ($start < $end) {
                            $slotEnd = (clone $start)->modify("+$duration minutes");
                            if ($slotEnd > $end) break;
                            if ($rdvRepo->isSlotAvailable($user, $start, $slotEnd)) {
                                $dayData['slots'][] = $start->format('H:i');
                                $dayData['hasAvailability'] = true;
                            }
                            $start = $slotEnd; // On avance au prochain slot
                        }
                    }
                }
            }
            $calendarData[$sortKey]['days'][] = $dayData;
            $currentDate->modify('+1 day');
        }
        ksort($calendarData);
        return array_values($calendarData);
    }
}

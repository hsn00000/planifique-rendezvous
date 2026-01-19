<?php

namespace App\Controller;

use App\Entity\Evenement;
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
     * ROUTE 1 : Affichage du calendrier
     * Permet de voir les disponibilités.
     */
    #[Route('/book/event/{eventId}/{userId?}', name: 'app_booking_calendar', requirements: ['eventId' => '\d+', 'userId' => '\d+'])]
    public function calendar(
        int $eventId,
        ?int $userId,
        EvenementRepository $eventRepo,
        UserRepository $userRepo,
        RendezVousRepository $rdvRepo,
        DisponibiliteHebdomadaireRepository $dispoRepo
    ): Response
    {
        $event = $eventRepo->find($eventId);
        if (!$event) throw $this->createNotFoundException("Événement introuvable.");

        $targetUser = $userId ? $userRepo->find($userId) : null;

        // Si aucun user ciblé, on prend le premier du groupe pour l'affichage par défaut
        $displayUser = $targetUser ?? $event->getGroupe()->getUsers()->first();

        if (!$displayUser) {
            throw $this->createNotFoundException("Aucun conseiller dans ce groupe.");
        }

        $slotsByDay = $this->generateSlots($displayUser, $event, $rdvRepo, $dispoRepo);

        return $this->render('booking/index.html.twig', [
            'conseiller' => $targetUser,
            'event' => $event,
            'slotsByDay' => $slotsByDay
        ]);
    }

    /**
     * ROUTE 2 : Confirmation et Finalisation du RDV
     * Gère le formulaire, le Round Robin et l'enregistrement.
     */
    #[Route('/book/confirm/{eventId}', name: 'app_booking_confirm')]
    public function confirm(
        Request $request,
        int $eventId,
        EvenementRepository $eventRepo,
        RendezVousRepository $rdvRepo,
        UserRepository $userRepo,
        EntityManagerInterface $em,
        MailerInterface $mailer,
        OutlookService $outlookService
    ): Response
    {
        $event = $eventRepo->find($eventId);
        if (!$event) return $this->redirectToRoute('app_home');

        $rendezVous = new RendezVous();
        $rendezVous->setEvenement($event);

        // 1. Récupération de la date depuis l'URL
        $dateParam = $request->query->get('date');
        if ($dateParam) {
            try {
                $startDate = new \DateTime($dateParam);

                // Vérification Date Limite
                if ($event->getDateLimite() && $startDate > $event->getDateLimite()) {
                    $this->addFlash('danger', 'La date limite pour cet événement est dépassée.');
                    return $this->redirectToRoute('app_booking_calendar', ['eventId' => $event->getId()]);
                }

                $rendezVous->setDateDebut($startDate);
                $rendezVous->setDateFin((clone $startDate)->modify('+' . $event->getDuree() . ' minutes'));
            } catch (\Exception $e) {
                return $this->redirectToRoute('app_home');
            }
        }

        // 2. Gestion du Lien Personnel (Conseiller Imposé)
        $userIdParam = $request->query->get('user');
        $conseillerImpose = null;

        if ($userIdParam) {
            $conseillerImpose = $userRepo->find($userIdParam);
            if ($conseillerImpose) {
                $rendezVous->setConseiller($conseillerImpose);
            }
        }

        // 3. Création du Formulaire
        $form = $this->createForm(BookingFormType::class, $rendezVous, [
            'groupe' => $event->getGroupe(),
            'is_round_robin' => $event->isRoundRobin(),
            'cacher_conseiller' => ($conseillerImpose !== null)
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            // Si le formulaire contient un choix utilisateur explicite
            if ($form->has('conseiller')) {
                $choixFormulaire = $form->get('conseiller')->getData();
                if ($choixFormulaire) {
                    $rendezVous->setConseiller($choixFormulaire);
                }
            }

            $conseillerFinal = $rendezVous->getConseiller();

            if ($conseillerFinal) {
                // CAS A : Conseiller DÉFINI (Imposé ou Choisi)
                if (!$this->isConseillerDispo($conseillerFinal, $rendezVous->getDateDebut(), $event->getDuree(), $rdvRepo, $outlookService)) {
                    $this->addFlash('danger', 'Oups ! Ce conseiller n\'est plus disponible à cette heure. Veuillez choisir un autre créneau.');
                    return $this->redirectToRoute('app_booking_calendar', [
                        'eventId' => $eventId,
                        'userId' => $conseillerImpose ? $conseillerImpose->getId() : null
                    ]);
                }
            } else {
                // CAS B : Conseiller NON DÉFINI (Round Robin)
                $conseillerTrouve = $this->findAvailableConseiller(
                    $event->getGroupe(),
                    $rendezVous->getDateDebut(),
                    $event->getDuree(),
                    $rdvRepo,
                    $outlookService
                );

                if (!$conseillerTrouve) {
                    $this->addFlash('danger', 'Oups ! Ce créneau n\'est plus disponible (tous nos conseillers sont pris). Veuillez en choisir un autre.');
                    return $this->redirectToRoute('app_booking_calendar', ['eventId' => $eventId]);
                }

                $rendezVous->setConseiller($conseillerTrouve);
            }

            // Enregistrement
            $rendezVous->setTypeLieu('Visioconférence');
            $em->persist($rendezVous);
            $em->flush();

            // Synchro Outlook
            try {
                if ($rendezVous->getConseiller()) {
                    $outlookService->addEventToCalendar($rendezVous->getConseiller(), $rendezVous);
                }
            } catch (\Exception $e) {}

            // Emails
            $this->sendConfirmationEmails($mailer, $rendezVous, $event);

            return $this->redirectToRoute('app_booking_success', ['id' => $rendezVous->getId()]);
        }

        return $this->render('booking/confirm.html.twig', [
            'form' => $form->createView(),
            'event' => $event,
            'dateChoisie' => $rendezVous->getDateDebut(),
            'conseiller' => $rendezVous->getConseiller(),
        ]);
    }

    // --- ALGORITHMES & OUTILS ---

    private function isConseillerDispo(User $user, \DateTime $start, int $duree, $rdvRepo, $outlookService): bool
    {
        $slotEnd = (clone $start)->modify("+$duree minutes");

        // 1. Quota
        if ($rdvRepo->countRendezVousForUserOnDate($user, $start) >= 3) return false;
        // 2. BDD
        if (!$rdvRepo->isSlotAvailable($user, $start, $slotEnd)) return false;
        // 3. Outlook
        try {
            $busyPeriods = $outlookService->getOutlookBusyPeriods($user, $start);
            foreach ($busyPeriods as $period) {
                if ($start < $period['end'] && $slotEnd > $period['start']) return false;
            }
        } catch (\Exception $e) {
            // return true; // (Optionnel : ignorer erreur Outlook)
        }

        return true;
    }

    private function findAvailableConseiller($groupe, \DateTime $start, int $duree, $rdvRepo, $outlookService): ?User
    {
        $conseillers = $groupe->getUsers()->toArray();
        shuffle($conseillers);

        usort($conseillers, function ($userA, $userB) use ($rdvRepo, $start) {
            $countA = $rdvRepo->countRendezVousForUserOnDate($userA, $start);
            $countB = $rdvRepo->countRendezVousForUserOnDate($userB, $start);
            return $countA <=> $countB;
        });

        $slotEnd = (clone $start)->modify("+$duree minutes");

        foreach ($conseillers as $conseiller) {
            if ($rdvRepo->countRendezVousForUserOnDate($conseiller, $start) >= 3) continue;
            if (!$rdvRepo->isSlotAvailable($conseiller, $start, $slotEnd)) continue;

            try {
                $busyPeriods = $outlookService->getOutlookBusyPeriods($conseiller, $start);
                foreach ($busyPeriods as $period) {
                    if ($start < $period['end'] && $slotEnd > $period['start']) {
                        continue 2;
                    }
                }
            } catch (\Exception $e) {}

            return $conseiller;
        }

        return null;
    }

    #[Route('/book/success/{id}', name: 'app_booking_success')]
    public function success(RendezVous $rendezVous): Response
    {
        return $this->render('booking/success.html.twig', [
            'rendezvous' => $rendezVous
        ]);
    }

    // --- OUTILS CALENDRIER ---

    private function generateSlots($user, $event, $rdvRepo, $dispoRepo): array {
        $calendarData = [];
        $duration = $event->getDuree();

        // --- CORRECTION : DÉFINITION DE $minBookingTime ---
        // On calcule l'heure minimum autorisée (Maintenant + Délai de prévention)
        $minBookingTime = new \DateTime();

        // On vérifie si la méthode existe (au cas où tu n'as pas encore mis à jour l'entité)
        // Cela t'évitera une autre erreur si tu n'as pas fait la migration BDD
        if (method_exists($event, 'getDelaiPrevention') && $event->getDelaiPrevention() > 0) {
            $minBookingTime->modify('+' . $event->getDelaiPrevention() . ' minutes');
        }
        // ---------------------------------------------------

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

                            // --- UTILISATION DE LA VARIABLE DÉFINIE PLUS HAUT ---
                            if ($start < $minBookingTime) {
                                $start = $slotEnd;
                                continue;
                            }
                            // ----------------------------------------------------

                            if ($rdvRepo->isSlotAvailable($user, $start, $slotEnd)) {
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
        ksort($calendarData);
        return array_values($calendarData);
    }

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
}

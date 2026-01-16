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
        // (Note : Pour une V2, tu pourrais fusionner les dispos de tout le groupe)
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
                // On force le conseiller dans l'entité
                $rendezVous->setConseiller($conseillerImpose);
            }
        }

        // 3. Création du Formulaire
        $form = $this->createForm(BookingFormType::class, $rendezVous, [
            'groupe' => $event->getGroupe(),
            'is_round_robin' => $event->isRoundRobin(),
            // Si un conseiller est imposé, on cache le sélecteur dans le formulaire
            'cacher_conseiller' => ($conseillerImpose !== null)
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            // Si le formulaire contient un choix utilisateur explicite, on le prend
            if ($form->has('conseiller')) {
                $choixFormulaire = $form->get('conseiller')->getData();
                if ($choixFormulaire) {
                    $rendezVous->setConseiller($choixFormulaire);
                }
            }

            // À ce stade, le conseiller est soit :
            // - Imposé (via URL)
            // - Choisi (via Formulaire)
            // - Null (via "Peu importe" / Round Robin)
            $conseillerFinal = $rendezVous->getConseiller();

            if ($conseillerFinal) {
                // CAS A : Conseiller DÉFINI (Imposé ou Choisi)
                // On vérifie s'il est toujours libre
                if (!$this->isConseillerDispo($conseillerFinal, $rendezVous->getDateDebut(), $event->getDuree(), $rdvRepo, $outlookService)) {
                    $this->addFlash('danger', 'Oups ! Ce conseiller n\'est plus disponible à cette heure. Veuillez choisir un autre créneau.');
                    return $this->redirectToRoute('app_booking_calendar', [
                        'eventId' => $eventId,
                        'userId' => $conseillerImpose ? $conseillerImpose->getId() : null
                    ]);
                }
            } else {
                // CAS B : Conseiller NON DÉFINI (Round Robin)
                // Le client a choisi "Peu importe"
                $conseillerTrouve = $this->findAvailableConseiller(
                    $event->getGroupe(),
                    $rendezVous->getDateDebut(),
                    $event->getDuree(),
                    $rdvRepo,
                    $outlookService
                );

                // --- GESTION ERREUR CLIENT ---
                if (!$conseillerTrouve) {
                    $this->addFlash('danger', 'Oups ! Ce créneau n\'est plus disponible (tous nos conseillers sont pris). Veuillez en choisir un autre.');
                    return $this->redirectToRoute('app_booking_calendar', ['eventId' => $eventId]);
                }
                // -----------------------------

                $rendezVous->setConseiller($conseillerTrouve);
            }

            // Enregistrement
            $rendezVous->setTypeLieu('Visioconférence'); // Ou valeur du form
            $em->persist($rendezVous);
            $em->flush();

            // Synchro Outlook (sécurisée)
            try {
                if ($rendezVous->getConseiller()) {
                    $outlookService->addEventToCalendar($rendezVous->getConseiller(), $rendezVous);
                }
            } catch (\Exception $e) {
                // On log l'erreur mais on ne bloque pas le client
            }

            // Envoi des emails
            $this->sendConfirmationEmails($mailer, $rendezVous, $event);

            return $this->redirectToRoute('app_booking_success', ['id' => $rendezVous->getId()]);
        }

        return $this->render('booking/confirm.html.twig', [
            'form' => $form->createView(),
            'event' => $event,
            'dateChoisie' => $rendezVous->getDateDebut(),
            'conseiller' => $rendezVous->getConseiller(), // Pour afficher la photo/nom
        ]);
    }

    // --- ALGORITHMES & OUTILS ---

    /**
     * Vérifie la disponibilité d'un conseiller précis (BDD + Outlook + Quota)
     */
    private function isConseillerDispo(User $user, \DateTime $start, int $duree, $rdvRepo, $outlookService): bool
    {
        $slotEnd = (clone $start)->modify("+$duree minutes");

        // 1. Quota journalier (3 RDV max)
        if ($rdvRepo->countRendezVousForUserOnDate($user, $start) >= 3) return false;

        // 2. BDD Interne
        if (!$rdvRepo->isSlotAvailable($user, $start, $slotEnd)) return false;

        // 3. Outlook
        try {
            $busyPeriods = $outlookService->getOutlookBusyPeriods($user, $start);
            foreach ($busyPeriods as $period) {
                if ($start < $period['end'] && $slotEnd > $period['start']) return false;
            }
        } catch (\Exception $e) {
            // Si Outlook ne répond pas, on peut décider de bloquer ou laisser passer.
            // Ici, par sécurité, on laisse passer (true) ou on bloque (false) selon ta politique.
            // return true;
        }

        return true;
    }

    /**
     * Trouve un conseiller disponible dans le groupe (Round Robin)
     */
    private function findAvailableConseiller($groupe, \DateTime $start, int $duree, $rdvRepo, $outlookService): ?User
    {
        $conseillers = $groupe->getUsers()->toArray();
        shuffle($conseillers); // Mélange pour équité

        // Tri : Celui qui a le MOINS de RDV passe en premier
        usort($conseillers, function ($userA, $userB) use ($rdvRepo, $start) {
            $countA = $rdvRepo->countRendezVousForUserOnDate($userA, $start);
            $countB = $rdvRepo->countRendezVousForUserOnDate($userB, $start);
            return $countA <=> $countB;
        });

        $slotEnd = (clone $start)->modify("+$duree minutes");

        foreach ($conseillers as $conseiller) {

            // 1. Limite 3 RDV
            // (Tu peux commenter cette ligne si tu veux faire des tests intensifs)
            if ($rdvRepo->countRendezVousForUserOnDate($conseiller, $start) >= 3) continue;

            // 2. Dispo BDD Interne (OBLIGATOIRE)
            if (!$rdvRepo->isSlotAvailable($conseiller, $start, $slotEnd)) continue;

            // 3. Outlook (Avec gestion d'erreur)
            try {
                $busyPeriods = $outlookService->getOutlookBusyPeriods($conseiller, $start);
                foreach ($busyPeriods as $period) {
                    if ($start < $period['end'] && $slotEnd > $period['start']) {
                        continue 2; // Occupé sur Outlook
                    }
                }
            } catch (\Exception $e) {
                // On ignore les erreurs Outlook pour ne pas bloquer l'app
            }

            // Si on arrive ici, le conseiller est validé !
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

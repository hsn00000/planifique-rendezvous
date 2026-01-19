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
        $displayUser = $targetUser ?? $event->getGroupe()->getUsers()->first();

        if (!$displayUser) throw $this->createNotFoundException("Aucun conseiller dans ce groupe.");

        $slotsByDay = $this->generateSlots($displayUser, $event, $rdvRepo, $dispoRepo);

        return $this->render('booking/index.html.twig', [
            'conseiller' => $targetUser,
            'event' => $event,
            'slotsByDay' => $slotsByDay
        ]);
    }

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

        // 1. Date
        $dateParam = $request->query->get('date');
        if ($dateParam) {
            try {
                $startDate = new \DateTime($dateParam);
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

        // 2. Conseiller Imposé
        $userIdParam = $request->query->get('user');
        $conseillerImpose = null;
        if ($userIdParam) {
            $conseillerImpose = $userRepo->find($userIdParam);
            if ($conseillerImpose) $rendezVous->setConseiller($conseillerImpose);
        }

        // 3. Formulaire
        $form = $this->createForm(BookingFormType::class, $rendezVous, [
            'groupe' => $event->getGroupe(),
            'is_round_robin' => $event->isRoundRobin(),
            'cacher_conseiller' => ($conseillerImpose !== null)
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            if ($form->has('conseiller')) {
                $choixFormulaire = $form->get('conseiller')->getData();
                if ($choixFormulaire) $rendezVous->setConseiller($choixFormulaire);
            }

            $conseillerFinal = $rendezVous->getConseiller();

            if ($conseillerFinal) {
                // VERIFICATION DU CONSEILLER (Avec Tampons)
                if (!$this->checkDispoWithBuffers($conseillerFinal, $rendezVous->getDateDebut(), $event->getDuree(), $rdvRepo, $outlookService)) {
                    $this->addFlash('danger', 'Ce conseiller n\'est plus disponible (conflit avec les temps de pause/trajet).');
                    return $this->redirectToRoute('app_booking_calendar', [
                        'eventId' => $eventId,
                        'userId' => $conseillerImpose ? $conseillerImpose->getId() : null
                    ]);
                }
            } else {
                // ROUND ROBIN (Avec Tampons)
                $conseillerTrouve = $this->findAvailableConseiller(
                    $event->getGroupe(),
                    $rendezVous->getDateDebut(),
                    $event->getDuree(),
                    $rdvRepo,
                    $outlookService
                );

                if (!$conseillerTrouve) {
                    $this->addFlash('danger', 'Aucun conseiller n\'est disponible sur ce créneau (temps de pause/trajet inclus).');
                    return $this->redirectToRoute('app_booking_calendar', ['eventId' => $eventId]);
                }
                $rendezVous->setConseiller($conseillerTrouve);
            }

            $rendezVous->setTypeLieu('Visioconférence');
            $em->persist($rendezVous);
            $em->flush();

            try {
                if ($rendezVous->getConseiller()) {
                    $outlookService->addEventToCalendar($rendezVous->getConseiller(), $rendezVous);
                }
            } catch (\Exception $e) {}

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

    // --- LOGIQUE DES TAMPONS ---

    /**
     * Vérifie la disponibilité en prenant en compte les tampons des RDV existants.
     * Exemple : Si un RDV est de 10h à 11h avec 30min tampon APRES,
     * le créneau est considéré occupé de 10h à 11h30.
     */
    private function checkDispoWithBuffers(User $user, \DateTime $start, int $duree, $rdvRepo, $outlookService): bool
    {
        $slotStart = clone $start;
        $slotEnd = (clone $start)->modify("+$duree minutes");

        // 1. Quota
        if ($rdvRepo->countRendezVousForUserOnDate($user, $start) >= 3) return false;

        // 2. BDD avec Tampons
        // On cherche les conflits avec les RDV existants
        $dayStart = (clone $start)->setTime(0, 0, 0);
        $dayEnd = (clone $start)->setTime(23, 59, 59);

        $existingRdvs = $rdvRepo->createQueryBuilder('r')
            ->where('r.conseiller = :user')
            ->andWhere('r.dateDebut BETWEEN :start AND :end')
            ->setParameter('user', $user)
            ->setParameter('start', $dayStart)
            ->setParameter('end', $dayEnd)
            ->getQuery()
            ->getResult();

        foreach ($existingRdvs as $rdv) {
            // Récupération des tampons de l'événement déjà planifié
            $tAvant = $rdv->getEvenement()->getTamponAvant(); // ex: 30
            $tApres = $rdv->getEvenement()->getTamponApres(); // ex: 60

            // Le RDV occupe réellement : [Début - Avant] jusqu'à [Fin + Après]
            $busyStart = (clone $rdv->getDateDebut())->modify("-{$tAvant} minutes");
            $busyEnd = (clone $rdv->getDateFin())->modify("+{$tApres} minutes");

            // Si notre nouveau créneau touche cette zone élargie, c'est mort
            if ($slotStart < $busyEnd && $slotEnd > $busyStart) {
                return false;
            }
        }

        // 3. Outlook
        try {
            $busyPeriods = $outlookService->getOutlookBusyPeriods($user, $start);
            foreach ($busyPeriods as $period) {
                if ($slotStart < $period['end'] && $slotEnd > $period['start']) return false;
            }
        } catch (\Exception $e) {}

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

        foreach ($conseillers as $conseiller) {
            if ($this->checkDispoWithBuffers($conseiller, $start, $duree, $rdvRepo, $outlookService)) {
                return $conseiller;
            }
        }
        return null;
    }

    // --- CALENDRIER OPTIMISÉ ---
    private function generateSlots($user, $event, $rdvRepo, $dispoRepo): array {
        $calendarData = [];
        $duration = $event->getDuree();

        // Fréquence des créneaux (Tu peux changer 30 par 15 ou 60 ici)
        $increment = 30;

        $startPeriod = new \DateTime('first day of this month');
        $dateLimite = $event->getDateLimite();
        $endPeriod = $dateLimite ? clone $dateLimite : (clone $startPeriod)->modify('+12 months')->modify('last day of this month');

        if ($dateLimite && $endPeriod < new \DateTime('today')) return [];

        // CHARGEMENT OPTIMISÉ DES RDV
        $allRdvs = $rdvRepo->createQueryBuilder('r')
            ->where('r.conseiller = :user')
            ->andWhere('r.dateDebut BETWEEN :start AND :end')
            ->setParameter('user', $user)
            ->setParameter('start', $startPeriod)
            ->setParameter('end', $endPeriod)
            ->getQuery()
            ->getResult();

        $disposHebdo = $dispoRepo->findBy(['user' => $user]);
        $rulesByDay = [];
        foreach ($disposHebdo as $dispo) $rulesByDay[$dispo->getJourSemaine()][] = $dispo;

        $currentDate = clone $startPeriod;
        while ($currentDate <= $endPeriod) {
            $sortKey = $currentDate->format('Y-m');
            if (!isset($calendarData[$sortKey])) $calendarData[$sortKey] = ['label' => clone $currentDate, 'days' => []];

            $dayOfWeek = (int)$currentDate->format('N');
            $dayData = [
                'dateObj' => clone $currentDate,
                'dayNum' => $currentDate->format('d'),
                'isToday' => $currentDate->format('Y-m-d') === (new \DateTime())->format('Y-m-d'),
                'isPast' => $currentDate < new \DateTime('today'),
                'slots' => [],
                'hasAvailability' => false
            ];

            if (!$dayData['isPast'] && isset($rulesByDay[$dayOfWeek])) {
                $dayStartFilter = (clone $currentDate)->setTime(0,0,0);
                $dayEndFilter = (clone $currentDate)->setTime(23,59,59);

                // Filtre RDV du jour en mémoire PHP (rapide)
                $rdvsDuJour = array_filter($allRdvs, function($r) use ($dayStartFilter, $dayEndFilter) {
                    return $r->getDateDebut() >= $dayStartFilter && $r->getDateDebut() <= $dayEndFilter;
                });

                foreach ($rulesByDay[$dayOfWeek] as $rule) {
                    if ($rule->isEstBloque()) continue;
                    $start = (clone $currentDate)->setTime((int)$rule->getHeureDebut()->format('H'), (int)$rule->getHeureDebut()->format('i'));
                    $end = (clone $currentDate)->setTime((int)$rule->getHeureFin()->format('H'), (int)$rule->getHeureFin()->format('i'));

                    while ($start < $end) {
                        // Fin théorique du RDV
                        $slotEnd = (clone $start)->modify("+$duration minutes");

                        // Si le RDV dépasse l'heure de fin de dispo du conseiller, on arrête
                        if ($slotEnd > $end) break;

                        $isFree = true;

                        // QUOTA (Désactivé pour tes tests, décommente pour la prod)
                        // if (count($rdvsDuJour) >= 3) { $isFree = false; }

                        if ($isFree) {
                            foreach ($rdvsDuJour as $rdv) {
                                // Gestion des Tampons
                                $tAvant = $rdv->getEvenement()->getTamponAvant();
                                $tApres = $rdv->getEvenement()->getTamponApres();
                                $busyStart = (clone $rdv->getDateDebut())->modify("-{$tAvant} minutes");
                                $busyEnd = (clone $rdv->getDateFin())->modify("+{$tApres} minutes");

                                // Si chevauchement
                                if ($start < $busyEnd && $slotEnd > $busyStart) {
                                    $isFree = false;
                                    break;
                                }
                            }
                        }

                        if ($isFree) {
                            $dayData['slots'][] = $start->format('H:i');
                            $dayData['hasAvailability'] = true;
                        }

                        // --- C'EST ICI QUE TOUT CHANGE ---
                        // Au lieu de sauter de la durée du RDV ($start = $slotEnd),
                        // on avance par petit pas fixe (30 min).
                        $start->modify("+$increment minutes");
                    }
                }
            }
            $calendarData[$sortKey]['days'][] = $dayData;
            $currentDate->modify('+1 day');
        }
        ksort($calendarData);
        return array_values($calendarData);
    }

    #[Route('/book/success/{id}', name: 'app_booking_success')]
    public function success(RendezVous $rendezVous): Response
    {
        return $this->render('booking/success.html.twig', ['rendezvous' => $rendezVous]);
    }

    private function sendConfirmationEmails($mailer, $rdv, $event): void
    {
        $emailClient = new TemplatedEmail()
            ->from('no-reply@planifique.com')
            ->to($rdv->getEmail())
            ->subject('Confirmation RDV : ' . $event->getTitre())
            ->htmlTemplate('emails/booking_confirmation_client.html.twig')
            ->context(['rdv' => $rdv]);
        try { $mailer->send($emailClient); } catch (\Exception $e) {}
    }
}

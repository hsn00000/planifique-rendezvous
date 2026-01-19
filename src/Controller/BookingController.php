<?php

namespace App\Controller;

use App\Entity\Evenement;
use App\Entity\RendezVous;
use App\Entity\User;
use App\Entity\Bureau;
use App\Form\BookingFormType;
use App\Repository\BureauRepository;
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
     * ÉTAPE 1 : Le Formulaire (Coordonnées + Lieu)
     * Remplace ton ancienne page de confirmation.
     */
    #[Route('/book/event/{eventId}', name: 'app_booking_form', requirements: ['eventId' => '\d+'])]
    public function form(
        int $eventId,
        Request $request,
        EvenementRepository $eventRepo,
        UserRepository $userRepo
    ): Response
    {
        $event = $eventRepo->find($eventId);
        if (!$event) throw $this->createNotFoundException("Événement introuvable.");

        $rendezVous = new RendezVous();
        $rendezVous->setEvenement($event);

        // Gestion conseiller imposé par URL
        $userIdParam = $request->query->get('user');
        if ($userIdParam && $u = $userRepo->find($userIdParam)) {
            $rendezVous->setConseiller($u);
        }

        $form = $this->createForm(BookingFormType::class, $rendezVous, [
            'groupe' => $event->getGroupe(),
            'is_round_robin' => $event->isRoundRobin(),
            'cacher_conseiller' => ($userIdParam !== null)
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Sauvegarde des données en Session
            $session = $request->getSession();
            $lieuChoisi = $form->get('typeLieu')->getData();

            $bookingData = [
                'lieu' => $lieuChoisi,
                'conseiller_id' => $rendezVous->getConseiller()?->getId(),
                'prenom' => $rendezVous->getPrenom(),
                'nom' => $rendezVous->getNom(),
                'email' => $rendezVous->getEmail(),
                'telephone' => $rendezVous->getTelephone(),
                'adresse' => $rendezVous->getAdresse(),
            ];

            $session->set('temp_booking_data', $bookingData);

            // Redirection vers le calendrier
            return $this->redirectToRoute('app_booking_calendar', [
                'eventId' => $eventId,
                'user' => $userIdParam
            ]);
        }

        // On utilise ton template 'confirm.html.twig' (qu'on renommera details)
        return $this->render('booking/details.html.twig', [
            'form' => $form->createView(),
            'event' => $event,
            'conseiller' => $rendezVous->getConseiller(),
            'dateChoisie' => null
        ]);
    }

    /**
     * ÉTAPE 2 : Le Calendrier
     */
    #[Route('/book/calendar/{eventId}', name: 'app_booking_calendar')]
    public function calendar(
        Request $request,
        int $eventId,
        EvenementRepository $eventRepo,
        UserRepository $userRepo,
        RendezVousRepository $rdvRepo,
        DisponibiliteHebdomadaireRepository $dispoRepo,
        BureauRepository $bureauRepo,
        OutlookService $outlookService
    ): Response
    {
        $session = $request->getSession();
        if (!$session->has('temp_booking_data')) {
            return $this->redirectToRoute('app_booking_form', ['eventId' => $eventId]);
        }

        $data = $session->get('temp_booking_data');
        $lieuChoisi = $data['lieu'];

        $event = $eventRepo->find($eventId);
        $targetUserId = $request->query->get('user');
        $targetUser = $targetUserId ? $userRepo->find($targetUserId) : null;
        $displayUser = $targetUser ?? $event->getGroupe()->getUsers()->first();

        $outlookService->synchronizeCalendar($displayUser);

        // Génération des slots (avec filtre Bureau)
        $slotsByDay = $this->generateSlots($displayUser, $event, $rdvRepo, $dispoRepo, $bureauRepo, $lieuChoisi);

        return $this->render('booking/index.html.twig', [
            'conseiller' => $targetUser,
            'event' => $event,
            'slotsByDay' => $slotsByDay,
            'lieuChoisi' => $lieuChoisi
        ]);
    }

    /**
     * ÉTAPE 3 : Finalisation (Clic sur un créneau)
     */
    #[Route('/book/finalize/{eventId}', name: 'app_booking_finalize')]
    public function finalize(
        Request $request,
        int $eventId,
        EvenementRepository $eventRepo,
        RendezVousRepository $rdvRepo,
        UserRepository $userRepo,
        BureauRepository $bureauRepo,
        EntityManagerInterface $em,
        MailerInterface $mailer,
        OutlookService $outlookService
    ): Response
    {
        $session = $request->getSession();
        $data = $session->get('temp_booking_data');

        if (!$data) return $this->redirectToRoute('app_booking_form', ['eventId' => $eventId]);

        $event = $eventRepo->find($eventId);
        $dateParam = $request->query->get('date');

        if (!$dateParam) return $this->redirectToRoute('app_booking_calendar', ['eventId' => $eventId]);

        // Reconstruction
        $rendezVous = new RendezVous();
        $rendezVous->setEvenement($event);
        $rendezVous->setTypeLieu($data['lieu']);
        $rendezVous->setPrenom($data['prenom']);
        $rendezVous->setNom($data['nom']);
        $rendezVous->setEmail($data['email']);
        $rendezVous->setTelephone($data['telephone']);
        $rendezVous->setAdresse($data['adresse']);

        $startDate = new \DateTime($dateParam);
        $rendezVous->setDateDebut($startDate);
        $rendezVous->setDateFin((clone $startDate)->modify('+' . $event->getDuree() . ' minutes'));

        // Conseiller
        if ($data['conseiller_id']) {
            $conseiller = $userRepo->find($data['conseiller_id']);
            if (!$this->checkDispoWithBuffers($conseiller, $startDate, $event->getDuree(), $rdvRepo, $outlookService)) {
                $this->addFlash('danger', 'Créneau indisponible.');
                return $this->redirectToRoute('app_booking_calendar', ['eventId' => $eventId]);
            }
            $rendezVous->setConseiller($conseiller);
        } else {
            // Round Robin
            $conseiller = $this->findAvailableConseiller($event->getGroupe(), $startDate, $event->getDuree(), $rdvRepo, $outlookService);
            if (!$conseiller) {
                $this->addFlash('danger', 'Aucun conseiller disponible.');
                return $this->redirectToRoute('app_booking_calendar', ['eventId' => $eventId]);
            }
            $rendezVous->setConseiller($conseiller);
        }

        // Bureau (Logique Salle)
        if (in_array($data['lieu'], ['Cabinet-geneve', 'Cabinet-archamps'])) {
            $bureauLibre = $bureauRepo->findAvailableBureau($data['lieu'], $rendezVous->getDateDebut(), $rendezVous->getDateFin());
            if (!$bureauLibre) {
                $this->addFlash('danger', 'Plus de bureau disponible à cette heure.');
                return $this->redirectToRoute('app_booking_calendar', ['eventId' => $eventId]);
            }
            $rendezVous->setBureau($bureauLibre);
        }

        $em->persist($rendezVous);
        $em->flush();

        try {
            if ($rendezVous->getConseiller()) {
                $outlookService->addEventToCalendar($rendezVous->getConseiller(), $rendezVous);
            }
        } catch (\Exception $e) {}

        $this->sendConfirmationEmails($mailer, $rendezVous, $event);
        $session->remove('temp_booking_data');

        return $this->redirectToRoute('app_booking_success', ['id' => $rendezVous->getId()]);
    }

    // --- FONCTIONS PRIVÉES ---

    private function generateSlots($user, $event, $rdvRepo, $dispoRepo, $bureauRepo, string $lieu): array
    {
        $calendarData = [];
        $duration = $event->getDuree();
        $increment = 30; // Ton pas de 30min
        $startPeriod = new \DateTime('first day of this month');
        $dateLimite = $event->getDateLimite();
        $endPeriod = $dateLimite ? clone $dateLimite : (clone $startPeriod)->modify('+12 months')->modify('last day of this month');
        if ($dateLimite && $endPeriod < new \DateTime('today')) return [];

        $allRdvs = $rdvRepo->createQueryBuilder('r')
            ->where('r.conseiller = :user')
            ->andWhere('r.dateDebut BETWEEN :start AND :end')
            ->setParameter('user', $user)
            ->setParameter('start', $startPeriod)
            ->setParameter('end', $endPeriod)
            ->getQuery()->getResult();

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
                $rdvsDuJour = array_filter($allRdvs, fn($r) => $r->getDateDebut() >= $dayStartFilter && $r->getDateDebut() <= $dayEndFilter);

                foreach ($rulesByDay[$dayOfWeek] as $rule) {
                    if ($rule->isEstBloque()) continue;
                    $start = (clone $currentDate)->setTime((int)$rule->getHeureDebut()->format('H'), (int)$rule->getHeureDebut()->format('i'));
                    $end = (clone $currentDate)->setTime((int)$rule->getHeureFin()->format('H'), (int)$rule->getHeureFin()->format('i'));

                    while ($start < $end) {
                        $slotEnd = (clone $start)->modify("+$duration minutes");
                        if ($slotEnd > $end) break;

                        $isFree = true;

                        // 1. Check Conseiller (Tampons)
                        foreach ($rdvsDuJour as $rdv) {
                            $tAvant = $rdv->getEvenement()->getTamponAvant();
                            $tApres = $rdv->getEvenement()->getTamponApres();
                            $busyStart = (clone $rdv->getDateDebut())->modify("-{$tAvant} minutes");
                            $busyEnd = (clone $rdv->getDateFin())->modify("+{$tApres} minutes");
                            if ($start < $busyEnd && $slotEnd > $busyStart) { $isFree = false; break; }
                        }

                        // 2. Check Bureau (LE SEUL AJOUT)
                        if ($isFree && in_array($lieu, ['Cabinet-geneve', 'Cabinet-archamps'])) {
                            $bureauDispo = $bureauRepo->findAvailableBureau($lieu, $start, $slotEnd);
                            if (!$bureauDispo) { $isFree = false; }
                        }

                        if ($isFree) {
                            $dayData['slots'][] = $start->format('H:i');
                            $dayData['hasAvailability'] = true;
                        }
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

    private function checkDispoWithBuffers(User $user, \DateTime $start, int $duree, $rdvRepo, $outlookService): bool
    {
        $slotStart = clone $start;
        $slotEnd = (clone $start)->modify("+$duree minutes");
        if ($rdvRepo->countRendezVousForUserOnDate($user, $start) >= 3) return false;
        $dayStart = (clone $start)->setTime(0, 0, 0);
        $dayEnd = (clone $start)->setTime(23, 59, 59);
        $existingRdvs = $rdvRepo->createQueryBuilder('r')->where('r.conseiller = :user')->andWhere('r.dateDebut BETWEEN :start AND :end')->setParameter('user', $user)->setParameter('start', $dayStart)->setParameter('end', $dayEnd)->getQuery()->getResult();
        foreach ($existingRdvs as $rdv) {
            $tAvant = $rdv->getEvenement()->getTamponAvant();
            $tApres = $rdv->getEvenement()->getTamponApres();
            $busyStart = (clone $rdv->getDateDebut())->modify("-{$tAvant} minutes");
            $busyEnd = (clone $rdv->getDateFin())->modify("+{$tApres} minutes");
            if ($slotStart < $busyEnd && $slotEnd > $busyStart) return false;
        }
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
            if ($this->checkDispoWithBuffers($conseiller, $start, $duree, $rdvRepo, $outlookService)) return $conseiller;
        }
        return null;
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

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
     * ÉTAPE 1 : LE FORMULAIRE
     */
    /**
     * ÉTAPE 1 : LE FORMULAIRE
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

        // 1. Gestion conseiller (URL ou Auto-détection)
        $userIdParam = $request->query->get('user');
        $viewUser = null;

        if ($userIdParam && $u = $userRepo->find($userIdParam)) {
            $rendezVous->setConseiller($u);
            $viewUser = $u;
        }
        elseif ($this->getUser() && !$event->isRoundRobin() && $event->getGroupe()->getUsers()->contains($this->getUser())) {
            $currentUser = $this->getUser();
            $rendezVous->setConseiller($currentUser);
            $viewUser = $currentUser;
            $userIdParam = $currentUser->getId();
        }
        elseif (!$event->isRoundRobin()) {
            $defaultUser = $event->getGroupe()->getUsers()->first();
            if ($defaultUser) {
                $rendezVous->setConseiller($defaultUser);
                $viewUser = $defaultUser;
            }
        }

        // --- NOUVEAU : PRÉ-REMPLISSAGE EN CAS DE RETOUR ---
        // Si le client revient du calendrier, on remet ses infos dans le formulaire
        $session = $request->getSession();
        if ($session->has('temp_booking_data')) {
            $data = $session->get('temp_booking_data');
            $rendezVous->setPrenom($data['prenom'] ?? '');
            $rendezVous->setNom($data['nom'] ?? '');
            $rendezVous->setEmail($data['email'] ?? '');
            $rendezVous->setTelephone($data['telephone'] ?? '');
            $rendezVous->setAdresse($data['adresse'] ?? '');
            if (isset($data['lieu'])) {
                $rendezVous->setTypeLieu($data['lieu']);
            }
        }
        // --------------------------------------------------

        $form = $this->createForm(BookingFormType::class, $rendezVous, [
            'groupe' => $event->getGroupe(),
            'is_round_robin' => $event->isRoundRobin(),
            'cacher_conseiller' => true,
            'action' => $this->generateUrl('app_booking_form', [
                'eventId' => $eventId,
                'user' => $userIdParam
            ])
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $lieuChoisi = $form->get('typeLieu')->getData();
            $conseillerId = $rendezVous->getConseiller()?->getId();

            if (!$conseillerId && $userIdParam) $conseillerId = $userIdParam;

            $bookingData = [
                'lieu' => $lieuChoisi,
                'conseiller_id' => $conseillerId,
                'prenom' => $rendezVous->getPrenom(),
                'nom' => $rendezVous->getNom(),
                'email' => $rendezVous->getEmail(),
                'telephone' => $rendezVous->getTelephone(),
                'adresse' => $rendezVous->getAdresse(),
            ];

            $session->set('temp_booking_data', $bookingData);

            return $this->redirectToRoute('app_booking_calendar', [
                'eventId' => $eventId,
                'user' => $userIdParam
            ]);
        }

        return $this->render('booking/details.html.twig', [
            'form' => $form->createView(),
            'event' => $event,
            'conseiller' => $viewUser,
        ]);
    }

    /**
     * ÉTAPE 2 : LE CALENDRIER
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

        $viewUser = null;       // Utilisateur affiché (Template)
        $calculationUser = null; // Utilisateur pour calculs

        // 1. Session (Priorité)
        if (!empty($data['conseiller_id'])) {
            $viewUser = $userRepo->find($data['conseiller_id']);
            $calculationUser = $viewUser;
        }
        // 2. URL
        elseif ($targetUserId) {
            $viewUser = $userRepo->find($targetUserId);
            $calculationUser = $viewUser;
        }
        // 3. Défaut (Round Robin OU Non-Round Robin sans user)
        else {
            $firstUser = $event->getGroupe()->getUsers()->first();
            $calculationUser = $firstUser;

            // Si ce n'est PAS un Round Robin, on affiche le user par défaut
            if (!$event->isRoundRobin()) {
                $viewUser = $firstUser;
            } else {
                // Si Round Robin, on force l'affichage "Équipe"
                $viewUser = null;
            }
        }

        $outlookService->synchronizeCalendar($calculationUser);

        $slotsByDay = $this->generateSlots($calculationUser, $event, $rdvRepo, $dispoRepo, $bureauRepo, $lieuChoisi);

        return $this->render('booking/index.html.twig', [
            'conseiller' => $viewUser,
            'event' => $event,
            'slotsByDay' => $slotsByDay,
            'lieuChoisi' => $lieuChoisi
        ]);
    }

    /**
     * ÉTAPE 2.5 : RÉCAPITULATIF (Page de validation)
     */
    #[Route('/book/summary/{eventId}', name: 'app_booking_summary')]
    public function summary(
        Request $request,
        int $eventId,
        EvenementRepository $eventRepo,
        UserRepository $userRepo
    ): Response
    {
        $session = $request->getSession();
        $data = $session->get('temp_booking_data');

        // Sécurité : si pas de données, retour au formulaire
        if (!$data) return $this->redirectToRoute('app_booking_form', ['eventId' => $eventId]);

        $event = $eventRepo->find($eventId);
        $dateParam = $request->query->get('date');

        // Sécurité : si pas de date choisie, retour au calendrier
        if (!$dateParam) return $this->redirectToRoute('app_booking_calendar', ['eventId' => $eventId]);

        $startDate = new \DateTime($dateParam);
        $endDate = (clone $startDate)->modify('+' . $event->getDuree() . ' minutes');

        // Logique d'affichage du conseiller (identique au calendrier)
        $targetUserId = $request->query->get('user');
        $viewUser = null;

        if (!empty($data['conseiller_id'])) {
            $viewUser = $userRepo->find($data['conseiller_id']);
        } elseif ($targetUserId) {
            $viewUser = $userRepo->find($targetUserId);
        } elseif (!$event->isRoundRobin()) {
            $viewUser = $event->getGroupe()->getUsers()->first();
        }
        // Sinon Round Robin = null (affiche "Notre Équipe")

        return $this->render('booking/summary.html.twig', [
            'event' => $event,
            'dateDebut' => $startDate,
            'dateFin' => $endDate,
            'conseiller' => $viewUser,
            'client' => $data,
            'lieu' => $data['lieu'],
            'dateParam' => $dateParam, // Pour passer à l'étape suivante
            'userIdParam' => $targetUserId // Pour conserver le paramètre d'URL
        ]);
    }

    /**
     * ÉTAPE 3 : FINALISATION
     */
    #[Route('/book/finalize/{eventId}', name: 'app_booking_finalize')]
    public function finalize(
        Request $request,
        int $eventId,
        EvenementRepository $eventRepo,
        RendezVousRepository $rdvRepo,
        UserRepository $userRepo,
        BureauRepository $bureauRepo, // <--- Injection du repository Bureau
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

        // --- 1. ATTRIBUTION CONSEILLER ---
        if (!empty($data['conseiller_id'])) {
            $conseiller = $userRepo->find($data['conseiller_id']);
            if (!$this->checkDispoWithBuffers($conseiller, $startDate, $event->getDuree(), $rdvRepo, $outlookService)) {
                $this->addFlash('danger', 'Ce créneau n\'est plus disponible.');
                return $this->redirectToRoute('app_booking_calendar', ['eventId' => $eventId]);
            }
            $rendezVous->setConseiller($conseiller);
        } else {
            if (!$event->isRoundRobin()) {
                $conseiller = $event->getGroupe()->getUsers()->first();
                if (!$this->checkDispoWithBuffers($conseiller, $startDate, $event->getDuree(), $rdvRepo, $outlookService)) {
                    $this->addFlash('danger', 'Ce créneau n\'est plus disponible.');
                    return $this->redirectToRoute('app_booking_calendar', ['eventId' => $eventId]);
                }
            } else {
                $conseiller = $this->findAvailableConseiller($event->getGroupe(), $startDate, $event->getDuree(), $rdvRepo, $outlookService);
            }

            if (!$conseiller) {
                $this->addFlash('danger', 'Aucun conseiller disponible sur ce créneau.');
                return $this->redirectToRoute('app_booking_calendar', ['eventId' => $eventId]);
            }
            $rendezVous->setConseiller($conseiller);
        }

        // --- 2. ATTRIBUTION BUREAU (Nouvelle logique) ---
        // On vérifie si le lieu correspond à un de nos cabinets physiques
        if (in_array($data['lieu'], ['Cabinet-geneve', 'Cabinet-archamps'])) {
            $bureauLibre = $bureauRepo->findAvailableBureau($data['lieu'], $rendezVous->getDateDebut(), $rendezVous->getDateFin());

            if (!$bureauLibre) {
                $this->addFlash('danger', 'Désolé, aucune salle n\'est disponible à cette heure.');
                return $this->redirectToRoute('app_booking_calendar', ['eventId' => $eventId]);
            }

            $rendezVous->setBureau($bureauLibre);
        }

        $em->persist($rendezVous);
        $em->flush();

        // --- 3. SYNCHRO OUTLOOK ---
        try {
            if ($rendezVous->getConseiller()) {
                $outlookService->addEventToCalendar($rendezVous->getConseiller(), $rendezVous);
            }
        } catch (\Exception $e) {}

        // --- 4. ENVOI EMAILS ---
        try {
            $this->sendConfirmationEmails($mailer, $rendezVous, $event);
        } catch (\Exception $e) {}

        $session->remove('temp_booking_data');

        return $this->redirectToRoute('app_booking_success', ['id' => $rendezVous->getId()]);
    }

    // --- FONCTIONS PRIVÉES ---

    private function generateSlots($user, $event, $rdvRepo, $dispoRepo, $bureauRepo, string $lieu): array
    {
        $calendarData = [];
        $duration = $event->getDuree();
        $increment = 30;
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

                    // Vérification Quota Journalier (ex: max 3 RDV/jour)
                    if ($rdvRepo->countRendezVousForUserOnDate($user, $currentDate) >= 3) {
                        continue;
                    }

                    $start = (clone $currentDate)->setTime((int)$rule->getHeureDebut()->format('H'), (int)$rule->getHeureDebut()->format('i'));
                    $end = (clone $currentDate)->setTime((int)$rule->getHeureFin()->format('H'), (int)$rule->getHeureFin()->format('i'));

                    while ($start < $end) {
                        $slotEnd = (clone $start)->modify("+$duration minutes");
                        if ($slotEnd > $end) break;

                        $isFree = true;

                        // 1. Vérification disponibilité Conseiller (avec tampons)
                        foreach ($rdvsDuJour as $rdv) {
                            $tAvant = $rdv->getEvenement()->getTamponAvant();
                            $tApres = $rdv->getEvenement()->getTamponApres();
                            $busyStart = (clone $rdv->getDateDebut())->modify("-{$tAvant} minutes");
                            $busyEnd = (clone $rdv->getDateFin())->modify("+{$tApres} minutes");
                            if ($start < $busyEnd && $slotEnd > $busyStart) { $isFree = false; break; }
                        }

                        // 2. Vérification disponibilité Salle (SI lieu physique)
                        if ($isFree && in_array($lieu, ['Cabinet-geneve', 'Cabinet-archamps'])) {
                            $bureauDispo = $bureauRepo->findAvailableBureau($lieu, $start, $slotEnd);
                            if (!$bureauDispo) {
                                $isFree = false; // Pas de salle libre, on ferme le créneau
                            }
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
        if ($rdvRepo->countRendezVousForUserOnDate($user, $start) >= 3) return false;

        $slotStart = clone $start;
        $slotEnd = (clone $start)->modify("+$duree minutes");

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

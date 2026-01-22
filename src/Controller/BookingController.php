<?php

namespace App\Controller;

use App\Entity\Evenement;
use App\Entity\RendezVous;
use App\Entity\User;
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
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Routing\Attribute\Route;

class BookingController extends AbstractController
{
    /**
     * ÉTAPE 1 : LE FORMULAIRE
     */
    #[Route('/book/event/{eventId}', name: 'app_booking_form', requirements: ['eventId' => '\d+'])]
    public function form(
        int $eventId,
        Request $request,
        EvenementRepository $eventRepo,
        UserRepository $userRepo
    ): Response {
        $event = $eventRepo->find($eventId);
        if (!$event) throw $this->createNotFoundException("Événement introuvable.");

        $rendezVous = new RendezVous();
        $rendezVous->setEvenement($event);

        // Gestion conseiller (URL ou Auto)
        $userIdParam = $request->query->get('user');
        $viewUser = null;

        if ($userIdParam && $u = $userRepo->find($userIdParam)) {
            $rendezVous->setConseiller($u);
            $viewUser = $u;
        } elseif ($this->getUser() && !$event->isRoundRobin() && $event->getGroupe()->getUsers()->contains($this->getUser())) {
            $currentUser = $this->getUser();
            $rendezVous->setConseiller($currentUser);
            $viewUser = $currentUser;
            $userIdParam = $currentUser->getId();
        } elseif (!$event->isRoundRobin()) {
            $defaultUser = $event->getGroupe()->getUsers()->first();
            if ($defaultUser) {
                $rendezVous->setConseiller($defaultUser);
                $viewUser = $defaultUser;
            }
        }

        // Pré-remplissage si retour
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
    ): Response {
        $session = $request->getSession();
        if (!$session->has('temp_booking_data')) {
            return $this->redirectToRoute('app_booking_form', ['eventId' => $eventId]);
        }

        $data = $session->get('temp_booking_data');
        $lieuChoisi = $data['lieu'];

        $event = $eventRepo->find($eventId);
        $targetUserId = $request->query->get('user');

        $viewUser = null;
        $calculationUser = null;

        // 1. Session
        if (!empty($data['conseiller_id'])) {
            $viewUser = $userRepo->find($data['conseiller_id']);
            $calculationUser = $viewUser;
        }
        // 2. URL
        elseif ($targetUserId) {
            $viewUser = $userRepo->find($targetUserId);
            $calculationUser = $viewUser;
        }
        // 3. Défaut
        else {
            $firstUser = $event->getGroupe()->getUsers()->first();
            $calculationUser = $firstUser;

            if (!$event->isRoundRobin()) {
                $viewUser = $firstUser;
            } else {
                $viewUser = null;
            }
        }

        // OPTIMISATION : Désactiver la synchronisation Outlook à chaque chargement du calendrier
        // Elle est coûteuse en temps et peut être faite en arrière-plan ou moins fréquemment
        // $outlookService->synchronizeCalendar($calculationUser);

        // Chargement progressif : ne charger que les 2 premiers mois initialement
        $slotsByDay = $this->generateSlots($calculationUser, $event, $rdvRepo, $dispoRepo, $bureauRepo, $outlookService, $lieuChoisi, 2);

        return $this->render('booking/index.html.twig', [
            'conseiller' => $viewUser,
            'event' => $event,
            'slotsByDay' => $slotsByDay,
            'lieuChoisi' => $lieuChoisi,
            'eventId' => $eventId,
            'userId' => $targetUserId,
            'limiteMois' => $event->getLimiteMoisReservation() ?? 12
        ]);
    }

    /**
     * Route AJAX pour charger les créneaux d'un mois spécifique (lazy loading)
     */
    #[Route('/book/calendar/{eventId}/month', name: 'app_booking_calendar_month', methods: ['GET'])]
    public function loadMonth(
        Request $request,
        int $eventId,
        EvenementRepository $eventRepo,
        UserRepository $userRepo,
        RendezVousRepository $rdvRepo,
        DisponibiliteHebdomadaireRepository $dispoRepo,
        BureauRepository $bureauRepo,
        OutlookService $outlookService
    ): \Symfony\Component\HttpFoundation\JsonResponse {
        $session = $request->getSession();
        if (!$session->has('temp_booking_data')) {
            return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'Session expirée'], 403);
        }

        $month = $request->query->get('month'); // Format: YYYY-MM
        if (!$month) {
            return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'Mois manquant'], 400);
        }

        $data = $session->get('temp_booking_data');
        $lieuChoisi = $data['lieu'];
        $event = $eventRepo->find($eventId);
        $targetUserId = $request->query->get('user');

        // Déterminer l'utilisateur
        $calculationUser = null;
        if (!empty($data['conseiller_id'])) {
            $calculationUser = $userRepo->find($data['conseiller_id']);
        } elseif ($targetUserId) {
            $calculationUser = $userRepo->find($targetUserId);
        } else {
            $calculationUser = $event->getGroupe()->getUsers()->first();
        }

        if (!$calculationUser) {
            return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'Utilisateur non trouvé'], 404);
        }

        try {
            // Générer les créneaux pour le mois spécifique
            $monthStart = new \DateTime($month . '-01');
            $monthEnd = (clone $monthStart)->modify('last day of this month');
            
            // Vérifier que le mois demandé n'est pas au-delà de la limite
            $now = new \DateTime();
            $maxMonthsAhead = $event->getLimiteMoisReservation() ?? 12; // Utiliser la limite paramétrable de l'événement
            $maxDate = (clone $now)->modify("+$maxMonthsAhead months")->modify('last day of this month');
            
            if ($monthStart > $maxDate) {
                // Si le mois demandé est au-delà de la limite, retourner un mois vide
                $formattedData = [
                    'label' => $this->formatMonthLabel($monthStart),
                    'days' => []
                ];
                return new \Symfony\Component\HttpFoundation\JsonResponse($formattedData);
            }
            
            $slots = $this->generateSlotsForMonth(
                $calculationUser,
                $event,
                $rdvRepo,
                $dispoRepo,
                $bureauRepo,
                $outlookService,
                $lieuChoisi,
                $monthStart,
                $monthEnd
            );

            // Retourner seulement le mois demandé au format attendu par le frontend
            $monthKey = $monthStart->format('Y-m');
            $monthData = null;
            
            // generateSlotsForMonth retourne un tableau indexé numériquement (array_values)
            // Chercher le mois dans les résultats
            foreach ($slots as $slot) {
                if (!isset($slot['label'])) {
                    continue;
                }
                
                $slotMonthKey = null;
                if ($slot['label'] instanceof \DateTime) {
                    $slotMonthKey = $slot['label']->format('Y-m');
                } elseif (is_string($slot['label'])) {
                    // Si le label est une string, essayer d'extraire la clé
                    // Le label peut être "Mars 2026" ou "2026-03" selon le format
                    if (preg_match('/(\d{4})-(\d{2})/', $slot['label'], $matches)) {
                        $slotMonthKey = $matches[1] . '-' . $matches[2];
                    } elseif (preg_match('/(\d{4})/', $slot['label'], $matches)) {
                        // Si on trouve juste l'année, chercher le mois dans le label
                        $months = ['janvier' => '01', 'février' => '02', 'mars' => '03', 'avril' => '04',
                                  'mai' => '05', 'juin' => '06', 'juillet' => '07', 'août' => '08',
                                  'septembre' => '09', 'octobre' => '10', 'novembre' => '11', 'décembre' => '12'];
                        $labelLower = mb_strtolower($slot['label']);
                        foreach ($months as $monthName => $monthNum) {
                            if (strpos($labelLower, $monthName) !== false) {
                                $slotMonthKey = $matches[1] . '-' . $monthNum;
                                break;
                            }
                        }
                    }
                }
                
                if ($slotMonthKey === $monthKey) {
                    $monthData = $slot;
                    break;
                }
            }
            
            // Si le mois n'est pas trouvé, créer un mois vide plutôt que de retourner une erreur
            if (!$monthData) {
                $monthData = [
                    'label' => $monthStart,
                    'days' => []
                ];
            }
        } catch (\Exception $e) {
            // Logger l'erreur pour le débogage
            error_log('Erreur dans loadMonth: ' . $e->getMessage() . ' - ' . $e->getTraceAsString());
            
            // En cas d'erreur, retourner un mois vide plutôt qu'une erreur pour éviter de bloquer le calendrier
            $monthStart = new \DateTime($month . '-01');
            $formattedData = [
                'label' => $this->formatMonthLabel($monthStart),
                'days' => []
            ];
            return new \Symfony\Component\HttpFoundation\JsonResponse($formattedData);
        }

        // Formater les données pour le frontend
        $formattedData = [
            'label' => $monthData['label'] instanceof \DateTime 
                ? $this->formatMonthLabel($monthData['label'])
                : ($monthData['label'] ?? $this->formatMonthLabel($monthStart)),
            'days' => array_map(function($day) {
                return [
                    'dateObj' => $day['dateObj'] instanceof \DateTime 
                        ? $day['dateObj']->format('Y-m-d') 
                        : $day['dateObj'],
                    'dayNum' => $day['dayNum'] ?? '',
                    'isToday' => $day['isToday'] ?? false,
                    'hasAvailability' => $day['hasAvailability'] ?? false,
                    'slots' => $day['slots'] ?? [],
                    'dateText' => $day['dateObj'] instanceof \DateTime 
                        ? $day['dateObj']->format('Y-m-d') 
                        : '',
                    'dateValue' => $day['dateObj'] instanceof \DateTime 
                        ? $day['dateObj']->format('Y-m-d') 
                        : ''
                ];
            }, $monthData['days'] ?? [])
        ];

        return new \Symfony\Component\HttpFoundation\JsonResponse($formattedData);
    }

    /**
     * ÉTAPE 2.5 : RÉCAPITULATIF
     */
    #[Route('/book/summary/{eventId}', name: 'app_booking_summary')]
    public function summary(
        Request $request,
        int $eventId,
        EvenementRepository $eventRepo,
        UserRepository $userRepo
    ): Response {
        $session = $request->getSession();
        $data = $session->get('temp_booking_data');

        if (!$data) return $this->redirectToRoute('app_booking_form', ['eventId' => $eventId]);

        $event = $eventRepo->find($eventId);
        $dateParam = $request->query->get('date');

        if (!$dateParam) return $this->redirectToRoute('app_booking_calendar', ['eventId' => $eventId]);

        $startDate = new \DateTime($dateParam);
        $endDate = (clone $startDate)->modify('+' . $event->getDuree() . ' minutes');

        $targetUserId = $request->query->get('user');
        $viewUser = null;

        if (!empty($data['conseiller_id'])) {
            $viewUser = $userRepo->find($data['conseiller_id']);
        } elseif ($targetUserId) {
            $viewUser = $userRepo->find($targetUserId);
        } elseif (!$event->isRoundRobin()) {
            $viewUser = $event->getGroupe()->getUsers()->first();
        }

        return $this->render('booking/summary.html.twig', [
            'event' => $event,
            'dateDebut' => $startDate,
            'dateFin' => $endDate,
            'conseiller' => $viewUser,
            'client' => $data,
            'lieu' => $data['lieu'],
            'dateParam' => $dateParam,
            'userIdParam' => $targetUserId
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
        BureauRepository $bureauRepo,
        EntityManagerInterface $em,
        MailerInterface $mailer,
        OutlookService $outlookService,
        ValidatorInterface $validator
    ): Response {
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

        // --- 2. ATTRIBUTION BUREAU (BDD + vérification Outlook) ---
        if (in_array($data['lieu'], ['Cabinet-geneve', 'Cabinet-archamps'], true)) {

            // Fonction helper pour tester un lieu
            $tryLieu = function (string $lieu) use ($bureauRepo, $outlookService, $rendezVous, $conseiller) {
                // 1) On récupère toutes les salles libres en BDD pour ce lieu
                $freeBureaux = $bureauRepo->findAvailableBureaux(
                    $lieu,
                    $rendezVous->getDateDebut(),
                    $rendezVous->getDateFin()
                );

                if (empty($freeBureaux)) {
                    return null;
                }

                // 2) On vérifie côté Outlook laquelle est vraiment libre
                return $outlookService->pickAvailableBureauOnOutlook(
                    $conseiller,
                    $freeBureaux,
                    $rendezVous->getDateDebut(),
                    $rendezVous->getDateFin()
                );
            };

            $lieuInitial = $rendezVous->getTypeLieu();
            $bureauLibre = $tryLieu($lieuInitial);

            // Si aucune salle libre dans le lieu initial, on tente l'autre lieu
            if (!$bureauLibre) {
                $autreLieu = $lieuInitial === 'Cabinet-geneve' ? 'Cabinet-archamps' : 'Cabinet-geneve';
                $bureauLibre = $tryLieu($autreLieu);

                if ($bureauLibre) {
                    $rendezVous->setTypeLieu($autreLieu);
                    $this->addFlash('info', 'Le lieu initial était complet, nous avons réservé une salle dans l\'autre cabinet.');
                }
            }

            // Si toujours rien, on refuse la réservation
            if (!$bureauLibre) {
                $this->addFlash('danger', 'Aucune salle n\'est disponible à cet horaire (conflits Outlook détectés). Merci de choisir un autre créneau.');
                return $this->redirectToRoute('app_booking_calendar', ['eventId' => $eventId]);
            }

            $rendezVous->setBureau($bureauLibre);
        }

        // --- VALIDATION FINALE : Vérifier qu'il n'y a pas de chevauchement ---
        $violations = $validator->validate($rendezVous);
        
        if (count($violations) > 0) {
            foreach ($violations as $violation) {
                $this->addFlash('danger', $violation->getMessage());
            }
            return $this->redirectToRoute('app_booking_calendar', ['eventId' => $eventId]);
        }

        $em->persist($rendezVous);
        $em->flush();

        // --- 3. SYNCHRO OUTLOOK (client non invité) ---
        try {
            if ($rendezVous->getConseiller()) {
                $outlookService->addEventToCalendar($rendezVous->getConseiller(), $rendezVous);
            }
        } catch (\Exception $e) {}

        // --- 4. ENVOI EMAILS (HTML + ICS public sans bureau) ---
        try {
            $this->sendConfirmationEmails($mailer, $rendezVous);
        } catch (\Exception $e) {}

        $session->remove('temp_booking_data');

        return $this->redirectToRoute('app_booking_success', ['id' => $rendezVous->getId()]);
    }

    #[Route('/book/success/{id}', name: 'app_booking_success')]
    public function success(RendezVous $rendezVous): Response
    {
        return $this->render('booking/success.html.twig', ['rendezvous' => $rendezVous]);
    }

    // =========================================================================
    // --- FONCTIONS PRIVÉES ---
    // =========================================================================

    private function generateSlots($user, $event, $rdvRepo, $dispoRepo, $bureauRepo, $outlookService, string $lieu, int $monthsToLoad = 2): array
    {
        $calendarData = [];
        $duration = $event->getDuree();
        $increment = 30;
        $startPeriod = new \DateTime('first day of this month');
        
        // Limite maximale : utiliser la valeur paramétrable de l'événement (par défaut 12 mois)
        $maxMonthsAhead = $event->getLimiteMoisReservation() ?? 12;
        $maxDate = (clone $startPeriod)->modify("+$maxMonthsAhead months")->modify('last day of this month');
        
        // Chargement progressif : charger seulement X mois initialement (par défaut 2)
        // Les autres mois seront chargés via AJAX quand l'utilisateur navigue
        $requestedEndPeriod = (clone $startPeriod)->modify("+$monthsToLoad months")->modify('last day of this month');
        
        // Utiliser le minimum entre la demande et la limite max
        $endPeriod = $requestedEndPeriod > $maxDate ? $maxDate : $requestedEndPeriod;
        
        if ($endPeriod < new \DateTime('today')) return [];

        return $this->generateSlotsForMonth($user, $event, $rdvRepo, $dispoRepo, $bureauRepo, $outlookService, $lieu, $startPeriod, $endPeriod);
    }

    private function generateSlotsForMonth($user, $event, $rdvRepo, $dispoRepo, $bureauRepo, $outlookService, string $lieu, \DateTime $startPeriod, \DateTime $endPeriod): array
    {
        $calendarData = [];
        $duration = $event->getDuree();
        $increment = 30;

        $now = new \DateTime();
        // Utiliser le délai minimum de réservation configuré par l'admin
        $delaiMinimum = $event->getDelaiMinimumReservation() ?? 0; // En minutes
        $minBookingTime = (clone $now)->modify("+$delaiMinimum minutes");

        // Chargement des RDV du conseiller
        $allRdvs = $rdvRepo->createQueryBuilder('r')
            ->where('r.conseiller = :user')
            ->andWhere('r.dateDebut BETWEEN :start AND :end')
            ->setParameter('user', $user)
            ->setParameter('start', $startPeriod)
            ->setParameter('end', $endPeriod)
            ->getQuery()->getResult();

        // OPTIMISATION : Pré-charger tous les RDV avec bureau pour ce lieu (une seule requête)
        $allBureauxRdvs = [];
        if (in_array($lieu, ['Cabinet-geneve', 'Cabinet-archamps'])) {
            $entityManager = $rdvRepo->getEntityManager();
            $query = $entityManager->createQuery(
                'SELECT r, b
         FROM App\Entity\RendezVous r
         INNER JOIN r.bureau b
         WHERE b.lieu = :lieu
         AND r.dateDebut >= :start
         AND r.dateDebut <= :end'
            )->setParameters([
                'lieu' => $lieu,
                'start' => $startPeriod,
                'end' => $endPeriod
            ]);
            $allBureauxRdvs = $query->getResult();
        }

        $disposHebdo = $dispoRepo->findBy(['user' => $user]);
        $rulesByDay = [];
        foreach ($disposHebdo as $dispo) $rulesByDay[$dispo->getJourSemaine()][] = $dispo;

        // Récupérer tous les bureaux du lieu une seule fois
        $allBureaux = [];
        if (in_array($lieu, ['Cabinet-geneve', 'Cabinet-archamps'])) {
            $allBureaux = $bureauRepo->findBy(['lieu' => $lieu]);
        }

        // OPTIMISATION CRITIQUE : Cache pour les vérifications Outlook par demi-journée
        // Évite les centaines d'appels API (un par créneau) en vérifiant seulement 2 fois par jour
        // Limité aux 30 prochains jours (1 mois) pour équilibrer performance et précision
        $outlookDayCache = [];

        $currentDate = clone $startPeriod;
        while ($currentDate <= $endPeriod) {
            $sortKey = $currentDate->format('Y-m');
            if (!isset($calendarData[$sortKey])) $calendarData[$sortKey] = ['label' => clone $currentDate, 'days' => []];
            $dayOfWeek = (int)$currentDate->format('N');
            $dayData = [
                'dateObj' => clone $currentDate,
                'dayNum' => $currentDate->format('d'),
                'isToday' => $currentDate->format('Y-m-d') === $now->format('Y-m-d'),
                'isPast' => $currentDate < (clone $now)->setTime(0,0,0),
                'slots' => [],
                'hasAvailability' => false
            ];

            if (!$dayData['isPast'] && isset($rulesByDay[$dayOfWeek])) {
                $dayStartFilter = (clone $currentDate)->setTime(0,0,0);
                $dayEndFilter = (clone $currentDate)->setTime(23,59,59);
                $rdvsDuJour = array_filter($allRdvs, fn($r) => $r->getDateDebut() >= $dayStartFilter && $r->getDateDebut() <= $dayEndFilter);

                // OPTIMISATION : Filtrer les RDV avec bureau pour ce jour une seule fois
                $bureauxRdvsDuJour = [];
                if (in_array($lieu, ['Cabinet-geneve', 'Cabinet-archamps'])) {
                    $bureauxRdvsDuJour = array_filter($allBureauxRdvs, function($r) use ($dayStartFilter, $dayEndFilter) {
                        return $r->getDateDebut() >= $dayStartFilter && $r->getDateDebut() <= $dayEndFilter;
                    });
                }

                foreach ($rulesByDay[$dayOfWeek] as $rule) {
                    if ($rule->isEstBloque()) continue;

                    // quota 3/jour
                    if ($rdvRepo->countRendezVousForUserOnDate($user, $currentDate) >= 3) {
                        continue;
                    }

                    $start = (clone $currentDate)->setTime((int)$rule->getHeureDebut()->format('H'), (int)$rule->getHeureDebut()->format('i'));
                    $end = (clone $currentDate)->setTime((int)$rule->getHeureFin()->format('H'), (int)$rule->getHeureFin()->format('i'));

                    while ($start < $end) {
                        $slotEnd = (clone $start)->modify("+$duration minutes");
                        if ($slotEnd > $end) break;

                        // VÉRIFICATION : Le créneau doit respecter le délai minimum de réservation
                        // Utilise le délai configuré par l'admin au lieu de vérifier l'heure actuelle à chaque fois
                        if ($start < $minBookingTime) {
                            $start->modify("+$increment minutes");
                            continue;
                        }

                        $isFree = true;

                        // dispo conseiller + tampons (RDV existants)
                        foreach ($rdvsDuJour as $rdv) {
                            $tAvant = $rdv->getEvenement()->getTamponAvant();
                            $tApres = $rdv->getEvenement()->getTamponApres();
                            $busyStart = (clone $rdv->getDateDebut())->modify("-{$tAvant} minutes");
                            $busyEnd = (clone $rdv->getDateFin())->modify("+{$tApres} minutes");
                            if ($start < $busyEnd && $slotEnd > $busyStart) { $isFree = false; break; }
                        }

                        // OPTIMISATION : Vérification des salles en mémoire (pas de requête SQL)
                        if ($isFree && in_array($lieu, ['Cabinet-geneve', 'Cabinet-archamps']) && !empty($allBureaux)) {
                            // On vérifie en mémoire quels bureaux sont occupés pour ce créneau (BDD locale)
                            $occupiedBureauIds = [];
                            foreach ($bureauxRdvsDuJour as $rdv) {
                                $bureau = $rdv->getBureau();
                                if ($bureau) {
                                    // Vérifier si ce RDV chevauche notre créneau
                                    if ($start < $rdv->getDateFin() && $slotEnd > $rdv->getDateDebut()) {
                                        $occupiedBureauIds[] = $bureau->getId();
                                    }
                                }
                            }
                            $occupiedBureauIds = array_unique($occupiedBureauIds);

                            // Filtrer les bureaux libres en BDD locale
                            $freeBureauxInBdd = [];
                            foreach ($allBureaux as $bureau) {
                                if (!in_array($bureau->getId(), $occupiedBureauIds, true)) {
                                    $freeBureauxInBdd[] = $bureau;
                                }
                            }

                            // Vérifier s'il reste au moins un bureau libre en BDD locale
                            if (empty($freeBureauxInBdd)) {
                                $isFree = false; // Aucune salle libre en BDD → on masque ce créneau
                            } else {
                                // OPTIMISATION : Vérification Outlook limitée aux 3 prochains mois avec cache par demi-journée
                                // Compromis entre performance et précision : vérifie 2 fois par jour au lieu de chaque créneau
                                $daysFromNow = (int)(($currentDate->getTimestamp() - $now->getTimestamp()) / 86400);
                                if ($daysFromNow >= 0 && $daysFromNow <= 90) {
                                    // Clé du cache : date + période (matin = avant 13h, après-midi = 13h et après)
                                    $period = (int)$start->format('H') < 13 ? 'morning' : 'afternoon';
                                    $cacheKey = $currentDate->format('Y-m-d') . '_' . $period;
                                    
                                    if (!isset($outlookDayCache[$cacheKey])) {
                                        // Vérifier une seule fois par demi-journée pour toutes les salles libres en BDD
                                        $periodStart = (clone $currentDate)->setTime($period === 'morning' ? 8 : 13, 0, 0);
                                        $periodEnd = (clone $currentDate)->setTime($period === 'morning' ? 13 : 18, 0, 0);
                                        try {
                                            // Limiter le nombre de bureaux vérifiés pour éviter les timeouts
                                            // Si trop de bureaux, on limite à 10 pour éviter les appels API trop longs
                                            $bureauxToCheck = count($freeBureauxInBdd) > 10 
                                                ? array_slice($freeBureauxInBdd, 0, 10) 
                                                : $freeBureauxInBdd;
                                            
                                            $outlookDayCache[$cacheKey] = $outlookService->hasAtLeastOneFreeRoomOnOutlook($user, $bureauxToCheck, $periodStart, $periodEnd);
                                        } catch (\Exception $e) {
                                            // En cas d'erreur/timeout Outlook, on bloque la période par sécurité
                                            error_log('Erreur Outlook pour ' . $lieu . ' le ' . $currentDate->format('Y-m-d') . ': ' . $e->getMessage());
                                            $outlookDayCache[$cacheKey] = false;
                                        }
                                    }
                                    // Utiliser le résultat du cache pour cette période
                                    if (!$outlookDayCache[$cacheKey]) {
                                        $isFree = false; // Aucune salle libre côté Outlook pour cette période → on masque ce créneau
                                    }
                                }
                                // Pour les jours au-delà de 3 mois (90 jours), on se base uniquement sur la BDD locale
                                // La vérification Outlook complète sera faite lors de la finalisation dans finalize()
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
        $existingRdvs = $rdvRepo->createQueryBuilder('r')
            ->where('r.conseiller = :user')
            ->andWhere('r.dateDebut BETWEEN :start AND :end')
            ->setParameter('user', $user)
            ->setParameter('start', $dayStart)
            ->setParameter('end', $dayEnd)
            ->getQuery()->getResult();

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

    /**
     * ICS public (sans bureau interne)
     */
    private function generateIcsContent(RendezVous $rdv): string
    {
        $start = $rdv->getDateDebut()->format('Ymd\THis');
        $end = $rdv->getDateFin()->format('Ymd\THis');
        $dtStamp = (new \DateTime())->format('Ymd\THis');

        // Lieu public (pas de bureau interne)
        $lieu = $rdv->getTypeLieu();
        $adresse = $rdv->getAdresse() ?: '';
        if (strcasecmp($lieu, 'Cabinet-geneve') === 0) {
            $adresse = 'Chemin du Pavillon 2, 1218 Le Grand-Saconnex';
        } elseif (strcasecmp($lieu, 'Cabinet-archamps') === 0) {
            $adresse = '160 Rue Georges de Mestral, 74160 Archamps, France';
        }
        $location = $lieu . ($adresse ? ' - ' . $adresse : '');

        $summary = 'Rendez-vous : ' . $rdv->getEvenement()->getTitre();
        $description = sprintf(
            "Bonjour %s %s,\nVotre rendez-vous est confirmé.",
            $rdv->getPrenom(),
            $rdv->getNom()
        );

        return
            "BEGIN:VCALENDAR\r\n" .
            "VERSION:2.0\r\n" .
            "PRODID:-//Planifique//Booking System//FR\r\n" .
            "CALSCALE:GREGORIAN\r\n" .
            "METHOD:PUBLISH\r\n" .
            "BEGIN:VEVENT\r\n" .
            "UID:rdv-" . $rdv->getId() . "@planifique\r\n" .
            "DTSTAMP:$dtStamp\r\n" .
            "DTSTART:$start\r\n" .
            "DTEND:$end\r\n" .
            "SUMMARY:" . addcslashes($summary, ",;\\") . "\r\n" .
            "LOCATION:" . addcslashes($location, ",;\\") . "\r\n" .
            "DESCRIPTION:" . addcslashes($description, ",;\\") . "\r\n" .
            "STATUS:CONFIRMED\r\n" .
            "END:VEVENT\r\n" .
            "END:VCALENDAR\r\n";
    }

    /**
     * Mail client : HTML + ICS public
     */
    private function sendConfirmationEmails(MailerInterface $mailer, RendezVous $rdv): void
    {
        $icsContent = $this->generateIcsContent($rdv);

        $email = (new TemplatedEmail())
            ->from('automate@planifique.com')
            ->to($rdv->getEmail())
            ->subject('Confirmation de rendez-vous : ' . $rdv->getEvenement()->getTitre())
            ->htmlTemplate('emails/booking_confirmation_client.html.twig')
            ->context(['rdv' => $rdv])
            // ICS public sans bureau interne
            ->attach($icsContent, 'rendez-vous.ics', 'application/octet-stream');

        $mailer->send($email);
    }

    /**
     * Formate un DateTime en label de mois en français (ex: "Mars 2026")
     */
    private function formatMonthLabel(\DateTime $date): string
    {
        $months = [
            1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
            5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
            9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
        ];
        
        $month = (int)$date->format('n');
        $year = $date->format('Y');
        
        return $months[$month] . ' ' . $year;
    }
}

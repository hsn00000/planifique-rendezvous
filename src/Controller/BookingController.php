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
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
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
            // Si un conseiller spécifique est sélectionné ET que ce n'est PAS un round-robin,
            // on vérifie uniquement ce conseiller (cohérent avec finalize())
            if (!$event->isRoundRobin()) {
                $calculationUser = $viewUser;
            } else {
                // Round-robin : on vérifie le groupe (au moins un conseiller disponible)
                $calculationUser = $event->getGroupe();
            }
        }
        // 2. URL
        elseif ($targetUserId) {
            $viewUser = $userRepo->find($targetUserId);
            if (!$event->isRoundRobin()) {
                $calculationUser = $viewUser;
            } else {
                $calculationUser = $event->getGroupe();
            }
        }
        // 3. Défaut
        else {
            $firstUser = $event->getGroupe()->getUsers()->first();
            if (!$event->isRoundRobin()) {
                $viewUser = $firstUser;
                $calculationUser = $firstUser;
            } else {
                $viewUser = null;
                $calculationUser = $event->getGroupe();
            }
        }

        // OPTIMISATION : Désactiver la synchronisation Outlook à chaque chargement du calendrier
        // Elle est coûteuse en temps et peut être faite en arrière-plan ou moins fréquemment
        // $outlookService->synchronizeCalendar($calculationUser);

        // Chargement progressif : ne charger que les 2 premiers mois initialement
        // IMPORTANT : Passer le bon paramètre selon la logique ci-dessus
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

        // Déterminer l'utilisateur (même logique que calendar())
        $calculationUser = null;
        if (!empty($data['conseiller_id'])) {
            $viewUser = $userRepo->find($data['conseiller_id']);
            // Si un conseiller spécifique est sélectionné ET que ce n'est PAS un round-robin,
            // on vérifie uniquement ce conseiller (cohérent avec finalize())
            if (!$event->isRoundRobin()) {
                $calculationUser = $viewUser;
            } else {
                // Round-robin : on vérifie le groupe (au moins un conseiller disponible)
                $calculationUser = $event->getGroupe();
            }
        } elseif ($targetUserId) {
            $viewUser = $userRepo->find($targetUserId);
            if (!$event->isRoundRobin()) {
                $calculationUser = $viewUser;
            } else {
                $calculationUser = $event->getGroupe();
            }
        } else {
            $firstUser = $event->getGroupe()->getUsers()->first();
            if (!$event->isRoundRobin()) {
                $calculationUser = $firstUser;
            } else {
                $calculationUser = $event->getGroupe();
            }
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
            
            // Passer le bon paramètre selon la logique ci-dessus
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
        error_log('=== FINALIZE APPELÉ ===');
        error_log('EventId: ' . $eventId);
        error_log('Query params: ' . json_encode($request->query->all()));
        error_log('Request URI: ' . $request->getRequestUri());
        
        $session = $request->getSession();
        $data = $session->get('temp_booking_data');

        if (!$data) {
            error_log('ERREUR finalize: Pas de données de session');
            $this->addFlash('danger', 'Votre session a expiré. Veuillez recommencer.');
            return $this->redirectToRoute('app_booking_form', ['eventId' => $eventId]);
        }
        
        error_log('Session data présentes: ' . json_encode(array_keys($data)));

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

        // Parser la date (format: "2026-01-29 09:30" ou "2026-01-29T09:30")
        try {
            // Essayer différents formats de date
            if (strpos($dateParam, 'T') !== false) {
                $startDate = new \DateTime($dateParam);
            } elseif (strpos($dateParam, ' ') !== false) {
                // Format "2026-01-29 09:30"
                $startDate = \DateTime::createFromFormat('Y-m-d H:i', $dateParam);
                if (!$startDate) {
                    // Essayer avec un format plus flexible
                    $startDate = new \DateTime($dateParam);
                }
            } else {
                // Format "2026-01-29" seulement
                $startDate = new \DateTime($dateParam);
            }
            
            if (!$startDate) {
                throw new \Exception('Impossible de parser la date: ' . $dateParam);
            }
            
            error_log('INFO finalize: Date parsée = ' . $startDate->format('Y-m-d H:i:s'));
        } catch (\Exception $e) {
            error_log('ERREUR finalize: Erreur lors du parsing de la date: ' . $e->getMessage());
            $this->addFlash('danger', 'Format de date invalide.');
            return $this->redirectToRoute('app_booking_calendar', ['eventId' => $eventId]);
        }
        
        $rendezVous->setDateDebut($startDate);
        $rendezVous->setDateFin((clone $startDate)->modify('+' . $event->getDuree() . ' minutes'));

        // Générer un token sécurisé pour l'annulation/modification
        $cancelToken = bin2hex(random_bytes(32));
        $rendezVous->setCancelToken($cancelToken);

        // --- 1. ATTRIBUTION CONSEILLER ---
        // IMPORTANT : La logique doit correspondre à generateSlotsForMonth()
        // Si un conseiller est sélectionné ET que l'événement n'est PAS en round-robin, on vérifie uniquement ce conseiller
        // Sinon, on cherche un conseiller disponible dans le groupe (comme dans generateSlotsForMonth)
        $conseiller = null;
        
        if (!empty($data['conseiller_id']) && !$event->isRoundRobin()) {
            // Conseiller spécifique sélectionné et pas de round-robin : vérifier uniquement ce conseiller
            error_log('INFO finalize: Conseiller spécifique sélectionné ID=' . $data['conseiller_id']);
            $conseiller = $userRepo->find($data['conseiller_id']);
            if (!$conseiller) {
                error_log('ERREUR finalize: Conseiller introuvable ID=' . $data['conseiller_id']);
                $this->addFlash('danger', 'Conseiller introuvable.');
                return $this->redirectToRoute('app_booking_calendar', ['eventId' => $eventId]);
            }
            if (!$this->checkDispoWithBuffers($conseiller, $startDate, $event->getDuree(), $rdvRepo, $outlookService)) {
                error_log('ERREUR finalize: Créneau non disponible pour conseiller ID=' . $conseiller->getId());
                $this->addFlash('danger', 'Ce créneau n\'est plus disponible pour ce conseiller.');
                return $this->redirectToRoute('app_booking_calendar', ['eventId' => $eventId]);
            }
            error_log('INFO finalize: Conseiller spécifique disponible ID=' . $conseiller->getId());
        } else {
            // Round-robin OU pas de conseiller sélectionné : chercher un conseiller disponible dans le groupe
            error_log('INFO finalize: Recherche d\'un conseiller disponible dans le groupe (round-robin ou pas de sélection)');
            $conseiller = $this->findAvailableConseiller($event->getGroupe(), $startDate, $event->getDuree(), $rdvRepo, $outlookService);
            
            if (!$conseiller) {
                error_log('ERREUR finalize: Aucun conseiller disponible dans le groupe');
                $this->addFlash('danger', 'Aucun conseiller disponible sur ce créneau.');
                return $this->redirectToRoute('app_booking_calendar', ['eventId' => $eventId]);
            }
            error_log('INFO finalize: Conseiller disponible trouvé ID=' . $conseiller->getId());
        }
        
        $rendezVous->setConseiller($conseiller);

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
                error_log('ERREUR finalize: Aucune salle disponible');
                $this->addFlash('danger', 'Aucune salle n\'est disponible à cet horaire (conflits Outlook détectés). Merci de choisir un autre créneau.');
                return $this->redirectToRoute('app_booking_calendar', ['eventId' => $eventId]);
            }

            $rendezVous->setBureau($bureauLibre);
            error_log('INFO finalize: Bureau attribué: ' . $bureauLibre->getNom());
        }

        // --- VALIDATION FINALE : Vérifier qu'il n'y a pas de chevauchement ---
        $violations = $validator->validate($rendezVous);
        
        if (count($violations) > 0) {
            foreach ($violations as $violation) {
                $this->addFlash('danger', $violation->getMessage());
            }
            return $this->redirectToRoute('app_booking_calendar', ['eventId' => $eventId]);
        }

        try {
            $em->persist($rendezVous);
            $em->flush();
            error_log('SUCCÈS: RDV sauvegardé avec ID=' . $rendezVous->getId());
        } catch (\Exception $e) {
            error_log('ERREUR lors de la sauvegarde du RDV: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            $this->addFlash('danger', 'Une erreur est survenue lors de la réservation: ' . $e->getMessage());
            return $this->redirectToRoute('app_booking_calendar', ['eventId' => $eventId]);
        }

        // Vérifier que le RDV a bien un ID après le flush
        if (!$rendezVous->getId()) {
            error_log('ERREUR: Le RDV n\'a pas d\'ID après le flush');
            $this->addFlash('danger', 'Une erreur est survenue lors de la réservation. Veuillez réessayer.');
            return $this->redirectToRoute('app_booking_calendar', ['eventId' => $eventId]);
        }

        // --- 3. SYNCHRO OUTLOOK (client non invité) ---
        try {
            if ($rendezVous->getConseiller()) {
                $outlookService->addEventToCalendar($rendezVous->getConseiller(), $rendezVous);
            }
        } catch (\Exception $e) {
            error_log('Erreur lors de la synchronisation Outlook: ' . $e->getMessage());
            // On continue même si Outlook échoue
        }

        $session->remove('temp_booking_data');

        // --- 4. ENVOI EMAILS (HTML + ICS public sans bureau) ---
        // IMPORTANT : L'envoi d'email est fait via Messenger
        // En dev avec sync, cela peut prendre du temps (connexion SMTP)
        // En prod avec async, l'email sera envoyé en arrière-plan
        try {
            error_log('INFO finalize: Début de l\'envoi de l\'email de confirmation à ' . $rendezVous->getEmail());
            $startTime = microtime(true);
            
            // Vérifier que l'email est valide
            if (empty($rendezVous->getEmail()) || !filter_var($rendezVous->getEmail(), FILTER_VALIDATE_EMAIL)) {
                throw new \Exception('Adresse email invalide: ' . $rendezVous->getEmail());
            }
            
            $this->sendConfirmationEmails($mailer, $rendezVous);
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            error_log('INFO finalize: Email de confirmation envoyé avec succès en ' . $duration . 'ms à ' . $rendezVous->getEmail());
        } catch (\Exception $e) {
            error_log('ERREUR finalize: Erreur lors de l\'envoi de l\'email: ' . $e->getMessage());
            error_log('ERREUR finalize: Stack trace: ' . $e->getTraceAsString());
            // On continue même si l'email échoue (le RDV est déjà sauvegardé)
            // L'utilisateur verra quand même la page de succès
        }

        // Redirection vers la page de succès
        error_log('SUCCÈS: Redirection vers la page de succès avec ID=' . $rendezVous->getId());
        return $this->redirectToRoute('app_booking_success', ['id' => $rendezVous->getId()]);
    }

    #[Route('/book/success/{id}', name: 'app_booking_success')]
    public function success(int $id, RendezVousRepository $rdvRepo): Response
    {
        error_log('SUCCÈS: Page success appelée avec ID=' . $id);
        $rendezVous = $rdvRepo->find($id);
        
        if (!$rendezVous) {
            error_log('ERREUR success: RDV introuvable ID=' . $id);
            $this->addFlash('danger', 'Rendez-vous introuvable.');
            return $this->redirectToRoute('app_home');
        }
        
        error_log('SUCCÈS: RDV trouvé, affichage de la page de succès');
        return $this->render('booking/success.html.twig', ['rendezvous' => $rendezVous]);
    }

    /**
     * ANNULATION : Affiche la page de confirmation d'annulation
     */
    #[Route('/book/cancel/{token}', name: 'app_booking_cancel')]
    public function cancel(
        string $token,
        RendezVousRepository $rdvRepo,
        EntityManagerInterface $em,
        OutlookService $outlookService
    ): Response {
        $rendezVous = $rdvRepo->findOneBy(['cancelToken' => $token]);
        
        if (!$rendezVous) {
            $this->addFlash('danger', 'Lien d\'annulation invalide ou expiré.');
            return $this->redirectToRoute('app_home');
        }

        // Vérifier que le RDV n'est pas déjà passé
        if ($rendezVous->getDateDebut() < new \DateTime()) {
            $this->addFlash('warning', 'Ce rendez-vous est déjà passé et ne peut plus être annulé.');
            return $this->render('booking/cancel.html.twig', [
                'rendezvous' => $rendezVous,
                'canCancel' => false
            ]);
        }

        return $this->render('booking/cancel.html.twig', [
            'rendezvous' => $rendezVous,
            'canCancel' => true
        ]);
    }

    /**
     * ANNULATION : Confirme et exécute l'annulation
     */
    #[Route('/book/cancel/{token}/confirm', name: 'app_booking_cancel_confirm', methods: ['POST'])]
    public function cancelConfirm(
        string $token,
        RendezVousRepository $rdvRepo,
        EntityManagerInterface $em,
        OutlookService $outlookService,
        MailerInterface $mailer
    ): Response {
        $rendezVous = $rdvRepo->findOneBy(['cancelToken' => $token]);
        
        if (!$rendezVous) {
            $this->addFlash('danger', 'Lien d\'annulation invalide ou expiré.');
            return $this->redirectToRoute('app_home');
        }

        // Vérifier que le RDV n'est pas déjà passé
        if ($rendezVous->getDateDebut() < new \DateTime()) {
            $this->addFlash('warning', 'Ce rendez-vous est déjà passé et ne peut plus être annulé.');
            return $this->render('booking/cancel.html.twig', [
                'rendezvous' => $rendezVous,
                'canCancel' => false
            ]);
        }

        // Supprimer l'événement Outlook
        if ($rendezVous->getConseiller() && $rendezVous->getOutlookId()) {
            try {
                $outlookService->deleteEventFromCalendar($rendezVous->getConseiller(), $rendezVous);
            } catch (\Exception $e) {
                error_log('Erreur lors de la suppression Outlook: ' . $e->getMessage());
            }
        }

        // Envoyer un email de confirmation d'annulation
        try {
            $this->sendCancellationEmail($mailer, $rendezVous);
        } catch (\Exception $e) {
            error_log('Erreur lors de l\'envoi de l\'email d\'annulation: ' . $e->getMessage());
        }

        // Supprimer le RDV de la base de données
        $em->remove($rendezVous);
        $em->flush();

        return $this->render('booking/cancel_confirmed.html.twig', [
            'rendezvous' => $rendezVous
        ]);
    }

    /**
     * MODIFICATION : Affiche la page de modification
     */
    #[Route('/book/modify/{token}', name: 'app_booking_modify')]
    public function modify(
        string $token,
        Request $request,
        RendezVousRepository $rdvRepo,
        EvenementRepository $eventRepo,
        UserRepository $userRepo,
        DisponibiliteHebdomadaireRepository $dispoRepo,
        BureauRepository $bureauRepo,
        OutlookService $outlookService
    ): Response {
        $rendezVous = $rdvRepo->findOneBy(['cancelToken' => $token]);
        
        if (!$rendezVous) {
            $this->addFlash('danger', 'Lien de modification invalide ou expiré.');
            return $this->redirectToRoute('app_home');
        }

        // Vérifier que le RDV n'est pas déjà passé
        if ($rendezVous->getDateDebut() < new \DateTime()) {
            $this->addFlash('warning', 'Ce rendez-vous est déjà passé et ne peut plus être modifié.');
            return $this->redirectToRoute('app_home');
        }

        $event = $rendezVous->getEvenement();
        $lieuChoisi = $rendezVous->getTypeLieu();

        // Générer les créneaux disponibles pour le groupe
        $slotsByDay = $this->generateSlots($event->getGroupe(), $event, $rdvRepo, $dispoRepo, $bureauRepo, $outlookService, $lieuChoisi, 2);

        return $this->render('booking/modify.html.twig', [
            'rendezvous' => $rendezVous,
            'event' => $event,
            'slotsByDay' => $slotsByDay,
            'lieuChoisi' => $lieuChoisi,
            'eventId' => $event->getId(),
            'userId' => $rendezVous->getConseiller()?->getId()
        ]);
    }

    /**
     * MODIFICATION : Confirme et exécute la modification
     */
    #[Route('/book/modify/{token}/confirm', name: 'app_booking_modify_confirm', methods: ['POST'])]
    public function modifyConfirm(
        string $token,
        Request $request,
        RendezVousRepository $rdvRepo,
        EvenementRepository $eventRepo,
        UserRepository $userRepo,
        BureauRepository $bureauRepo,
        EntityManagerInterface $em,
        OutlookService $outlookService,
        MailerInterface $mailer,
        ValidatorInterface $validator
    ): Response {
        $rendezVous = $rdvRepo->findOneBy(['cancelToken' => $token]);
        
        if (!$rendezVous) {
            $this->addFlash('danger', 'Lien de modification invalide ou expiré.');
            return $this->redirectToRoute('app_home');
        }

        // Vérifier que le RDV n'est pas déjà passé
        if ($rendezVous->getDateDebut() < new \DateTime()) {
            $this->addFlash('warning', 'Ce rendez-vous est déjà passé et ne peut plus être modifié.');
            return $this->redirectToRoute('app_booking_modify', ['token' => $token]);
        }

        $dateParam = $request->request->get('date');
        if (!$dateParam) {
            $this->addFlash('danger', 'Veuillez sélectionner une nouvelle date.');
            return $this->redirectToRoute('app_booking_modify', ['token' => $token]);
        }

        $event = $rendezVous->getEvenement();
        $oldDateDebut = clone $rendezVous->getDateDebut();
        $oldDateFin = clone $rendezVous->getDateFin();

        // Nouvelle date
        $newDateDebut = new \DateTime($dateParam);
        $newDateFin = (clone $newDateDebut)->modify('+' . $event->getDuree() . ' minutes');

        // Vérifier la disponibilité du conseiller (en excluant le RDV actuel)
        if (!$this->checkDispoWithBuffers($rendezVous->getConseiller(), $newDateDebut, $event->getDuree(), $rdvRepo, $outlookService, $rendezVous)) {
            $this->addFlash('danger', 'Ce créneau n\'est plus disponible.');
            return $this->redirectToRoute('app_booking_modify', ['token' => $token]);
        }

        // Vérifier la disponibilité des salles si nécessaire
        if (in_array($rendezVous->getTypeLieu(), ['Cabinet-geneve', 'Cabinet-archamps'], true)) {
            $freeBureaux = $bureauRepo->findAvailableBureaux(
                $rendezVous->getTypeLieu(),
                $newDateDebut,
                $newDateFin
            );

            if (empty($freeBureaux)) {
                $this->addFlash('danger', 'Aucune salle disponible pour ce créneau.');
                return $this->redirectToRoute('app_booking_modify', ['token' => $token]);
            }

            $bureauLibre = $outlookService->pickAvailableBureauOnOutlook(
                $rendezVous->getConseiller(),
                $freeBureaux,
                $newDateDebut,
                $newDateFin
            );

            if (!$bureauLibre) {
                $this->addFlash('danger', 'Aucune salle disponible côté Outlook pour ce créneau.');
                return $this->redirectToRoute('app_booking_modify', ['token' => $token]);
            }

            $rendezVous->setBureau($bureauLibre);
        }

        // Mettre à jour les dates
        $rendezVous->setDateDebut($newDateDebut);
        $rendezVous->setDateFin($newDateFin);

        // Validation
        $violations = $validator->validate($rendezVous);
        if (count($violations) > 0) {
            foreach ($violations as $violation) {
                $this->addFlash('danger', $violation->getMessage());
            }
            return $this->redirectToRoute('app_booking_modify', ['token' => $token]);
        }

        // Mettre à jour Outlook
        if ($rendezVous->getConseiller() && $rendezVous->getOutlookId()) {
            try {
                $outlookService->updateEventInCalendar($rendezVous->getConseiller(), $rendezVous);
            } catch (\Exception $e) {
                error_log('Erreur lors de la mise à jour Outlook: ' . $e->getMessage());
            }
        }

        $em->flush();

        // Envoyer un email de confirmation de modification
        try {
            $this->sendModificationEmail($mailer, $rendezVous, $oldDateDebut, $oldDateFin);
        } catch (\Exception $e) {
            error_log('Erreur lors de l\'envoi de l\'email de modification: ' . $e->getMessage());
        }

        $this->addFlash('success', 'Votre rendez-vous a été modifié avec succès.');
        return $this->render('booking/modify_confirmed.html.twig', [
            'rendezvous' => $rendezVous,
            'oldDateDebut' => $oldDateDebut,
            'oldDateFin' => $oldDateFin
        ]);
    }

    // =========================================================================
    // --- FONCTIONS PRIVÉES ---
    // =========================================================================

    private function generateSlots($groupeOrUser, $event, $rdvRepo, $dispoRepo, $bureauRepo, $outlookService, string $lieu, int $monthsToLoad = 2): array
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

        // Si c'est un groupe, on vérifie tous les conseillers, sinon on vérifie juste l'utilisateur
        if ($groupeOrUser instanceof \App\Entity\Groupe) {
            return $this->generateSlotsForMonth($groupeOrUser, $event, $rdvRepo, $dispoRepo, $bureauRepo, $outlookService, $lieu, $startPeriod, $endPeriod);
        } else {
            // Rétrocompatibilité : si on passe un User, on crée un groupe temporaire avec un seul user
            return $this->generateSlotsForMonth($groupeOrUser, $event, $rdvRepo, $dispoRepo, $bureauRepo, $outlookService, $lieu, $startPeriod, $endPeriod);
        }
    }

    private function generateSlotsForMonth($groupeOrUser, $event, $rdvRepo, $dispoRepo, $bureauRepo, $outlookService, string $lieu, \DateTime $startPeriod, \DateTime $endPeriod): array
    {
        $calendarData = [];
        $duration = $event->getDuree();
        $increment = 30;

        $now = new \DateTime();
        // Utiliser le délai minimum de réservation configuré par l'admin
        $delaiMinimum = $event->getDelaiMinimumReservation() ?? 0; // En minutes
        $minBookingTime = (clone $now)->modify("+$delaiMinimum minutes");

        // Détecter si c'est un groupe ou un user
        $isGroupe = $groupeOrUser instanceof \App\Entity\Groupe;
        $conseillers = $isGroupe ? $groupeOrUser->getUsers()->toArray() : [$groupeOrUser];
        
        if (empty($conseillers)) {
            return [];
        }

        // Chargement des RDV de TOUS les conseillers du groupe
        $allRdvs = $rdvRepo->createQueryBuilder('r')
            ->where('r.conseiller IN (:conseillers)')
            ->andWhere('r.dateDebut BETWEEN :start AND :end')
            ->setParameter('conseillers', $conseillers)
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

        // Charger les disponibilités hebdomadaires de TOUS les conseillers
        $disposHebdo = $dispoRepo->createQueryBuilder('d')
            ->where('d.user IN (:conseillers)')
            ->setParameter('conseillers', $conseillers)
            ->getQuery()
            ->getResult();
        
        // Organiser les disponibilités par jour de semaine et par conseiller
        $rulesByDay = [];
        foreach ($disposHebdo as $dispo) {
            $rulesByDay[$dispo->getJourSemaine()][] = $dispo;
        }

        // Récupérer tous les bureaux du lieu une seule fois
        $allBureaux = [];
        $hasValidEmails = false;
        if (in_array($lieu, ['Cabinet-geneve', 'Cabinet-archamps'])) {
            $allBureaux = $bureauRepo->findBy(['lieu' => $lieu]);
            // Vérifier si au moins un bureau a un email valide (pour éviter les appels Outlook inutiles)
            foreach ($allBureaux as $bureau) {
                if (!empty($bureau->getEmail())) {
                    $hasValidEmails = true;
                    break;
                }
            }
        }

        // OPTIMISATION CRITIQUE : Cache pour les vérifications Outlook par demi-journée
        // Évite les centaines d'appels API (un par créneau) en vérifiant seulement 2 fois par jour
        // Limité aux 30 prochains jours (1 mois) pour équilibrer performance et précision
        $outlookDayCache = [];
        
        // Cache pour les vérifications Outlook des conseillers par jour (pour éviter les appels répétés)
        // Clé : conseiller_id_date (ex: "15_2025-01-23")
        $outlookConseillerCache = [];

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
                // IMPORTANT : Récupérer TOUS les RDV qui chevauchent cette journée
                // (même ceux qui commencent avant minuit ou se terminent après minuit)
                $rdvsDuJour = array_filter($allRdvs, function($r) use ($dayStartFilter, $dayEndFilter) {
                    // Un RDV chevauche la journée si : il commence avant la fin du jour ET se termine après le début du jour
                    return $r->getDateDebut() < $dayEndFilter && $r->getDateFin() > $dayStartFilter;
                });

                // OPTIMISATION : Filtrer les RDV avec bureau pour ce jour une seule fois
                $bureauxRdvsDuJour = [];
                if (in_array($lieu, ['Cabinet-geneve', 'Cabinet-archamps'])) {
                    $bureauxRdvsDuJour = array_filter($allBureauxRdvs, function($r) use ($dayStartFilter, $dayEndFilter) {
                        return $r->getDateDebut() >= $dayStartFilter && $r->getDateDebut() <= $dayEndFilter;
                    });
                }

                // Pour chaque règle de disponibilité, vérifier si au moins un conseiller est disponible
                foreach ($rulesByDay[$dayOfWeek] as $rule) {
                    if ($rule->isEstBloque()) continue;

                    $start = (clone $currentDate)->setTime((int)$rule->getHeureDebut()->format('H'), (int)$rule->getHeureDebut()->format('i'));
                    $end = (clone $currentDate)->setTime((int)$rule->getHeureFin()->format('H'), (int)$rule->getHeureFin()->format('i'));

                    while ($start < $end) {
                        $slotEnd = (clone $start)->modify("+$duration minutes");
                        if ($slotEnd > $end) break;

                        // VÉRIFICATION : Le créneau doit respecter le délai minimum de réservation
                        if ($start < $minBookingTime) {
                            $start->modify("+$increment minutes");
                            continue;
                        }

                        // VÉRIFIER SI AU MOINS UN CONSEILLER EST DISPONIBLE pour ce créneau
                        $atLeastOneConseillerAvailable = false;
                        
                        // Initialiser $isFree selon le type (groupe = false, conseiller spécifique = false)
                        $isFree = false;
                        
                        foreach ($conseillers as $conseiller) {
                            // Vérifier le quota 3/jour pour ce conseiller
                            if ($rdvRepo->countRendezVousForUserOnDate($conseiller, $currentDate) >= 3) {
                                if (!$isGroupe) {
                                    // Si c'est un conseiller spécifique et qu'il a atteint son quota, le créneau n'est pas disponible
                                    $isFree = false;
                                    break;
                                }
                                continue; // Pour un groupe, on continue avec les autres conseillers
                            }
                            
                            // Vérifier si ce conseiller a une disponibilité pour cette règle ET que le créneau est dans sa plage horaire
                            $conseillerDispos = array_filter($disposHebdo, function($d) use ($conseiller, $dayOfWeek, $start, $slotEnd, $currentDate) {
                                if ($d->getUser() !== $conseiller || $d->getJourSemaine() !== $dayOfWeek || $d->isEstBloque()) {
                                    return false;
                                }
                                // Vérifier que le créneau est dans la plage horaire de disponibilité
                                $dispoStart = (clone $currentDate)->setTime((int)$d->getHeureDebut()->format('H'), (int)$d->getHeureDebut()->format('i'));
                                $dispoEnd = (clone $currentDate)->setTime((int)$d->getHeureFin()->format('H'), (int)$d->getHeureFin()->format('i'));
                                return $start >= $dispoStart && $slotEnd <= $dispoEnd;
                            });
                            if (empty($conseillerDispos)) {
                                if (!$isGroupe) {
                                    // Si c'est un conseiller spécifique et qu'il n'a pas de disponibilité, le créneau n'est pas disponible
                                    $isFree = false;
                                    break;
                                }
                                continue; // Pour un groupe, on continue avec les autres conseillers
                            }
                            
                            // Vérifier si ce conseiller est libre (pas de RDV qui chevauche)
                            $conseillerIsFree = true;
                            foreach ($rdvsDuJour as $rdv) {
                                // Ne vérifier que les RDV de ce conseiller
                                if ($rdv->getConseiller() !== $conseiller) continue;
                                
                                $tAvant = $rdv->getEvenement()->getTamponAvant();
                                $tApres = $rdv->getEvenement()->getTamponApres();
                                $busyStart = (clone $rdv->getDateDebut())->modify("-{$tAvant} minutes");
                                $busyEnd = (clone $rdv->getDateFin())->modify("+{$tApres} minutes");
                                if ($start < $busyEnd && $slotEnd > $busyStart) {
                                    $conseillerIsFree = false;
                                    break;
                                }
                            }
                            
                            // Vérifier Outlook pour ce conseiller (seulement pour les 7 prochains jours pour éviter les timeouts)
                            // IMPORTANT : Cette vérification doit être cohérente avec checkDispoWithBuffers() dans finalize()
                            // OPTIMISATION : Utiliser un cache par jour et par conseiller pour éviter les appels répétés
                            // IMPORTANT : Même fenêtre de 7 jours que checkDispoWithBuffers() pour garantir la cohérence
                            if ($conseillerIsFree) {
                                $daysFromNow = (int)(($currentDate->getTimestamp() - $now->getTimestamp()) / 86400);
                                if ($daysFromNow >= 0 && $daysFromNow <= 7) {
                                    // Clé du cache : conseiller ID + date
                                    $cacheKey = $conseiller->getId() . '_' . $currentDate->format('Y-m-d');
                                    
                                    // Vérifier le cache d'abord
                                    if (!isset($outlookConseillerCache[$cacheKey])) {
                                        // Si pas en cache, faire l'appel Outlook et mettre en cache
                                        try {
                                            $busyPeriods = $outlookService->getOutlookBusyPeriods($conseiller, $currentDate);
                                            $outlookConseillerCache[$cacheKey] = $busyPeriods;
                                        } catch (\Exception $e) {
                                            // En cas d'erreur Outlook, on considère comme non-disponible par sécurité
                                            $outlookConseillerCache[$cacheKey] = null; // null = erreur, considérer comme occupé
                                        }
                                    }
                                    
                                    // Vérifier les périodes occupées (depuis le cache ou erreur)
                                    $busyPeriods = $outlookConseillerCache[$cacheKey];
                                    if ($busyPeriods === null) {
                                        // Erreur Outlook → considérer comme non-disponible
                                        error_log('INFO generateSlotsForMonth: Erreur Outlook pour conseiller ID=' . $conseiller->getId() . ' date=' . $currentDate->format('Y-m-d'));
                                        $conseillerIsFree = false;
                                    } else {
                                        // Vérifier si le créneau chevauche une période occupée
                                        foreach ($busyPeriods as $period) {
                                            if ($start < $period['end'] && $slotEnd > $period['start']) {
                                                error_log('INFO generateSlotsForMonth: Créneau occupé Outlook pour conseiller ID=' . $conseiller->getId() . 
                                                    ' créneau: ' . $start->format('Y-m-d H:i') . ' - ' . $slotEnd->format('H:i') . 
                                                    ' période Outlook: ' . $period['start']->format('Y-m-d H:i') . ' - ' . $period['end']->format('H:i'));
                                                $conseillerIsFree = false;
                                                break;
                                            }
                                        }
                                    }
                                }
                            }
                            
                            if ($conseillerIsFree) {
                                $isFree = true;
                                if (!$isGroupe) {
                                    // Si c'est un conseiller spécifique et qu'il est disponible, on peut arrêter
                                    break;
                                }
                                // Pour un groupe, on continue pour vérifier les autres conseillers (mais on a déjà trouvé un disponible)
                                // On peut garder $isFree = true et continuer pour la vérification des salles
                            } elseif (!$isGroupe) {
                                // Si c'est un conseiller spécifique et qu'il n'est pas libre, le créneau n'est pas disponible
                                $isFree = false;
                                break;
                            }
                        }
                        
                        // Si aucun conseiller n'est disponible, $isFree reste false
                        // Si au moins un conseiller est disponible (groupe) ou le conseiller spécifique est disponible, $isFree = true

                        // OPTIMISATION : Vérification des salles en mémoire (pas de requête SQL)
                        // TOUJOURS vérifier la BDD locale, même si les emails ne sont pas configurés
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
                            } elseif ($hasValidEmails) {
                                // Seulement si des emails sont configurés, on fait la vérification Outlook
                                // OPTIMISATION : Vérification Outlook limitée avec cache par demi-journée
                                // Pour améliorer les performances, on limite la vérification Outlook à 30 jours
                                // Au-delà, on se base uniquement sur la BDD locale (vérification finale dans finalize())
                                $daysFromNow = (int)(($currentDate->getTimestamp() - $now->getTimestamp()) / 86400);
                                
                                // Vérifier si au moins un bureau a un email valide avant de faire l'appel API
                                $bureauxWithEmail = array_filter($freeBureauxInBdd, fn($b) => !empty($b->getEmail()));
                                
                                // Si aucun bureau n'a d'email, on considère comme disponible (pas d'appel API)
                                if (empty($bureauxWithEmail)) {
                                    // Pas d'email configuré → on se base uniquement sur la BDD locale
                                    // La vérification Outlook sera faite lors de la finalisation
                                } elseif ($daysFromNow >= 0 && $daysFromNow <= 30) {
                                    // Clé du cache : date + période (matin = avant 13h, après-midi = 13h et après)
                                    $period = (int)$start->format('H') < 13 ? 'morning' : 'afternoon';
                                    $cacheKey = $currentDate->format('Y-m-d') . '_' . $period . '_' . $lieu;
                                    
                                    if (!isset($outlookDayCache[$cacheKey])) {
                                        // Vérifier une seule fois par demi-journée pour toutes les salles libres en BDD
                                        $periodStart = (clone $currentDate)->setTime($period === 'morning' ? 8 : 13, 0, 0);
                                        $periodEnd = (clone $currentDate)->setTime($period === 'morning' ? 13 : 18, 0, 0);
                                        try {
                                            // Limiter le nombre de bureaux vérifiés pour éviter les timeouts (max 5 pour Archamps)
                                            $maxBureaux = ($lieu === 'Cabinet-archamps') ? 5 : 10;
                                            $bureauxToCheck = count($bureauxWithEmail) > $maxBureaux 
                                                ? array_slice($bureauxWithEmail, 0, $maxBureaux) 
                                                : $bureauxWithEmail;
                                            
                                            // Vérifier si au moins un conseiller a une salle disponible
                                            $atLeastOneConseillerHasRoom = false;
                                            foreach ($conseillers as $conseiller) {
                                                try {
                                                    if ($outlookService->hasAtLeastOneFreeRoomOnOutlook($conseiller, $bureauxToCheck, $periodStart, $periodEnd)) {
                                                        $atLeastOneConseillerHasRoom = true;
                                                        break;
                                                    }
                                                } catch (\Exception $e) {
                                                    // En cas d'erreur pour un conseiller, on continue avec les autres
                                                    continue;
                                                }
                                            }
                                            $outlookDayCache[$cacheKey] = $atLeastOneConseillerHasRoom;
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
                                // Pour les jours au-delà de 30 jours, on se base uniquement sur la BDD locale
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

    private function checkDispoWithBuffers(User $user, \DateTime $start, int $duree, $rdvRepo, $outlookService, ?RendezVous $excludeRdv = null): bool
    {
        $slotStart = clone $start;
        $slotEnd = (clone $start)->modify("+$duree minutes");
        
        // Vérifier le quota 3 RDV/jour
        $dayStart = (clone $start)->setTime(0, 0, 0);
        $dayEnd = (clone $start)->setTime(23, 59, 59);
        
        $countQuery = $rdvRepo->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.conseiller = :user')
            ->andWhere('r.dateDebut >= :dayStart')
            ->andWhere('r.dateDebut <= :dayEnd')
            ->setParameter('user', $user)
            ->setParameter('dayStart', $dayStart)
            ->setParameter('dayEnd', $dayEnd);
        
        if ($excludeRdv) {
            $countQuery->andWhere('r.id != :excludeId')
                ->setParameter('excludeId', $excludeRdv->getId());
        }
        
        $count = (int)$countQuery->getQuery()->getSingleScalarResult();
        if ($count >= 3) {
            error_log('ERREUR checkDispoWithBuffers: Quota de 3 RDV/jour atteint pour conseiller ID=' . $user->getId());
            return false;
        }

        // IMPORTANT : Récupérer TOUS les RDV qui chevauchent cette journée
        // (même ceux qui commencent avant ou se terminent après)
        $existingRdvsQuery = $rdvRepo->createQueryBuilder('r')
            ->where('r.conseiller = :user')
            ->andWhere('r.dateDebut < :dayEnd AND r.dateFin > :dayStart')
            ->setParameter('user', $user)
            ->setParameter('dayStart', $dayStart)
            ->setParameter('dayEnd', $dayEnd);
        
        if ($excludeRdv) {
            $existingRdvsQuery->andWhere('r.id != :excludeId')
                ->setParameter('excludeId', $excludeRdv->getId());
        }
        
        $existingRdvs = $existingRdvsQuery->getQuery()->getResult();

        // Vérifier les chevauchements avec tampons
        foreach ($existingRdvs as $rdv) {
            $tAvant = $rdv->getEvenement()->getTamponAvant();
            $tApres = $rdv->getEvenement()->getTamponApres();
            $busyStart = (clone $rdv->getDateDebut())->modify("-{$tAvant} minutes");
            $busyEnd = (clone $rdv->getDateFin())->modify("+{$tApres} minutes");
            if ($slotStart < $busyEnd && $slotEnd > $busyStart) {
                error_log('ERREUR checkDispoWithBuffers: Chevauchement détecté avec RDV ID=' . $rdv->getId() . 
                    ' (RDV: ' . $rdv->getDateDebut()->format('Y-m-d H:i') . ' - ' . $rdv->getDateFin()->format('H:i') . 
                    ', Slot: ' . $slotStart->format('Y-m-d H:i') . ' - ' . $slotEnd->format('H:i') . ')');
                return false;
            }
        }
        
        // Vérifier Outlook (seulement pour les 7 prochains jours pour éviter les timeouts)
        $daysFromNow = (int)(($start->getTimestamp() - (new \DateTime())->getTimestamp()) / 86400);
        if ($daysFromNow >= 0 && $daysFromNow <= 7) {
            try {
                $busyPeriods = $outlookService->getOutlookBusyPeriods($user, $start);
                foreach ($busyPeriods as $period) {
                    if ($slotStart < $period['end'] && $slotEnd > $period['start']) {
                        error_log('ERREUR checkDispoWithBuffers: Période occupée Outlook détectée pour conseiller ID=' . $user->getId());
                        return false;
                    }
                }
            } catch (\Exception $e) {
                error_log('ERREUR checkDispoWithBuffers: Exception Outlook: ' . $e->getMessage());
                // En cas d'erreur Outlook dans les 7 prochains jours, on considère comme non-disponible par sécurité
                return false;
            }
        }
        // Pour les dates au-delà de 7 jours, on se base uniquement sur la BDD locale
        // La vérification Outlook sera faite lors de la finalisation si nécessaire
        
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
        try {
            error_log('INFO sendConfirmationEmails: Génération du contenu ICS pour RDV ID=' . $rdv->getId());
            $icsContent = $this->generateIcsContent($rdv);

            error_log('INFO sendConfirmationEmails: Création de l\'email pour ' . $rdv->getEmail());
            $email = (new TemplatedEmail())
                ->from('automate@planifique.com')
                ->to($rdv->getEmail())
                ->subject('Confirmation de rendez-vous : ' . $rdv->getEvenement()->getTitre())
                ->htmlTemplate('emails/booking_confirmation_client.html.twig')
                ->context(['rdv' => $rdv])
                // ICS public sans bureau interne
                ->attach($icsContent, 'rendez-vous.ics', 'application/octet-stream');

            error_log('INFO sendConfirmationEmails: Envoi de l\'email via MailerInterface...');
            error_log('INFO sendConfirmationEmails: MAILER_DSN configuré: ' . (getenv('MAILER_DSN') ? 'OUI' : 'NON'));
            
            $mailer->send($email);
            
            error_log('INFO sendConfirmationEmails: Email envoyé avec succès à ' . $rdv->getEmail());
        } catch (\Symfony\Component\Mailer\Exception\TransportExceptionInterface $e) {
            error_log('ERREUR sendConfirmationEmails: Erreur de transport SMTP: ' . $e->getMessage());
            error_log('ERREUR sendConfirmationEmails: Code: ' . $e->getCode());
            throw $e;
        } catch (\Exception $e) {
            error_log('ERREUR sendConfirmationEmails: Exception lors de l\'envoi: ' . $e->getMessage());
            error_log('ERREUR sendConfirmationEmails: Type: ' . get_class($e));
            error_log('ERREUR sendConfirmationEmails: Stack trace: ' . $e->getTraceAsString());
            throw $e; // Re-lancer l'exception pour qu'elle soit gérée par l'appelant
        }
    }

    /**
     * Email de confirmation d'annulation
     */
    private function sendCancellationEmail(MailerInterface $mailer, RendezVous $rdv): void
    {
        $email = (new TemplatedEmail())
            ->from('automate@planifique.com')
            ->to($rdv->getEmail())
            ->subject('Annulation de votre rendez-vous : ' . $rdv->getEvenement()->getTitre())
            ->htmlTemplate('emails/booking_cancellation_client.html.twig')
            ->context(['rdv' => $rdv]);

        $mailer->send($email);
    }

    /**
     * Email de confirmation de modification
     */
    private function sendModificationEmail(MailerInterface $mailer, RendezVous $rdv, \DateTime $oldDateDebut, \DateTime $oldDateFin): void
    {
        $icsContent = $this->generateIcsContent($rdv);

        $email = (new TemplatedEmail())
            ->from('automate@planifique.com')
            ->to($rdv->getEmail())
            ->subject('Modification de votre rendez-vous : ' . $rdv->getEvenement()->getTitre())
            ->htmlTemplate('emails/booking_modification_client.html.twig')
            ->context([
                'rdv' => $rdv,
                'oldDateDebut' => $oldDateDebut,
                'oldDateFin' => $oldDateFin
            ])
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

<?php

namespace App\Controller;

use App\Entity\Evenement;
use App\Entity\Groupe;
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
use Psr\Log\LoggerInterface;
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

        // Vérifier que calculationUser est défini
        if (!$calculationUser) {
            return $this->redirectToRoute('app_booking_form', ['eventId' => $eventId]);
        }

        // IMPORTANT : Synchroniser le calendrier Outlook pour supprimer les rendez-vous annulés dans Outlook
        // Cela garantit que les rendez-vous supprimés dans Outlook sont aussi supprimés de la base de données
        // OPTIMISATION : Ne synchroniser qu'une fois par session pour éviter les appels répétés
        // NOTE : synchronizeCalendar() nécessite un User, pas un Groupe
        $userForSync = null;
        if ($calculationUser instanceof User) {
            $userForSync = $calculationUser;
        } elseif ($calculationUser instanceof Groupe) {
            // Pour un groupe, utiliser le premier utilisateur du groupe pour la synchronisation
            $firstUser = $calculationUser->getUsers()->first();
            if ($firstUser) {
                $userForSync = $firstUser;
            }
        }
        
        if ($userForSync) {
            $session = $request->getSession();
            $lastSyncKey = 'outlook_sync_' . $userForSync->getId();
            $lastSync = $session->get($lastSyncKey);
            $now = time();
            
            // Synchroniser au maximum une fois toutes les 5 minutes pour éviter les appels répétés
            if (!$lastSync || ($now - $lastSync) > 300) {
                try {
                    $outlookService->synchronizeCalendar($userForSync);
                    $session->set($lastSyncKey, $now);
                } catch (\Exception $e) {
                    // Ne pas bloquer l'affichage du calendrier si la synchronisation échoue
                    error_log('Erreur lors de la synchronisation Outlook: ' . $e->getMessage());
                }
            }
        }

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
    #[Route('/book/finalize/{eventId}', name: 'app_booking_finalize', methods: ['POST'])]
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
        ValidatorInterface $validator,
        LoggerInterface $logger
    ): Response {
        error_log('=== FINALIZE APPELÉ ===');
        error_log('EventId: ' . $eventId);
        error_log('Request method: ' . $request->getMethod());
        error_log('POST params: ' . json_encode($request->request->all()));
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
        // Lire depuis POST (request) au lieu de GET (query)
        $dateParam = $request->request->get('date');

        if (!$dateParam) {
            error_log('ERREUR finalize: Paramètre date manquant dans POST');
            $this->addFlash('danger', 'Paramètre de date manquant.');
            return $this->redirectToRoute('app_booking_calendar', ['eventId' => $eventId]);
        }

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
        // IMPORTANT : Ne vérifier les salles QUE pour les cabinets physiques (pas pour Visioconférence ou Domicile)
        if (in_array($data['lieu'], ['Cabinet-geneve', 'Cabinet-archamps'], true)) {

            // Fonction helper pour tester un lieu
            $tryLieu = function (string $lieu) use ($bureauRepo, $outlookService, $rendezVous, $conseiller, $event) {
                // 1) On récupère toutes les salles libres en BDD pour ce lieu
                $freeBureaux = $bureauRepo->findAvailableBureaux(
                    $lieu,
                    $rendezVous->getDateDebut(),
                    $rendezVous->getDateFin()
                );

                if (empty($freeBureaux)) {
                    return null;
                }

                // 2) IMPORTANT : Vérifier Outlook pour TOUTES les salles du cabinet (pas seulement celles libres en BDD)
                // Car un conseiller peut avoir réservé directement dans Outlook sans passer par l'application
                // Récupérer toutes les salles du cabinet
                $allBureaux = $bureauRepo->findBy(['lieu' => $lieu]);
                
                // Vérifier si au moins une salle est libre côté Outlook (parmi TOUTES les salles)
                $hasFreeRoom = $outlookService->hasAtLeastOneFreeRoomOnOutlook(
                    $conseiller,
                    $allBureaux, // TOUTES les salles, pas seulement celles libres en BDD
                    $rendezVous->getDateDebut(),
                    $rendezVous->getDateFin()
                );
                
                if (!$hasFreeRoom) {
                    // Toutes les salles sont occupées côté Outlook
                    return null;
                }
                
                // 3) Si au moins une salle est libre côté Outlook, choisir la première parmi celles libres en BDD
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
            $logger->info('Début de l\'envoi de l\'email de confirmation', [
                'email' => $rendezVous->getEmail(),
                'rdv_id' => $rendezVous->getId()
            ]);
            $startTime = microtime(true);
            
            // Vérifier que l'email est valide
            if (empty($rendezVous->getEmail()) || !filter_var($rendezVous->getEmail(), FILTER_VALIDATE_EMAIL)) {
                throw new \Exception('Adresse email invalide: ' . $rendezVous->getEmail());
            }
            
            $this->sendConfirmationEmails($mailer, $rendezVous, $logger);
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $logger->info('Email de confirmation envoyé avec succès', [
                'email' => $rendezVous->getEmail(),
                'duration_ms' => $duration
            ]);
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $logger->error('Erreur lors de l\'envoi de l\'email', [
                'email' => $rendezVous->getEmail(),
                'error' => $errorMessage,
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // En mode DEV, afficher l'erreur directement pour faciliter le débogage
            $env = $this->getParameter('kernel.environment');
            if ($env === 'dev') {
                // DÉBOGAGE : Décommentez la ligne suivante pour voir l'erreur immédiatement
                // dd('❌ ERREUR EMAIL', $errorMessage, $e);
                
                // Afficher un message flash pour informer l'utilisateur
                $this->addFlash('warning', '⚠️ L\'email de confirmation n\'a pas pu être envoyé. Erreur: ' . $errorMessage);
                
                // Afficher aussi dans la console Symfony
                $logger->critical('ERREUR EMAIL EN MODE DEV - Vérifiez les logs ci-dessus pour plus de détails', [
                    'error' => $errorMessage,
                    'code' => $e->getCode()
                ]);
            } else {
                // En production, on continue silencieusement mais on log l'erreur
                // L'utilisateur verra quand même la page de succès
            }
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

        $event = $rendezVous->getEvenement();
        
        // Vérifier le délai de fin de modification (en heures)
        $delaiFinModification = $event->getDelaiFinModification() ?? 24; // Par défaut 24h
        $dateLimiteModification = (clone $rendezVous->getDateDebut())->modify("-{$delaiFinModification} hours");
        $canCancel = new \DateTime() <= $dateLimiteModification;
        
        if (!$canCancel) {
            $this->addFlash('warning', "Vous ne pouvez plus annuler ce rendez-vous. Le délai d'annulation est de {$delaiFinModification} heures avant le rendez-vous.");
        }

        return $this->render('booking/cancel.html.twig', [
            'rendezvous' => $rendezVous,
            'canCancel' => $canCancel
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

        $event = $rendezVous->getEvenement();
        
        // Vérifier le délai de fin de modification (en heures)
        $delaiFinModification = $event->getDelaiFinModification() ?? 24; // Par défaut 24h
        $dateLimiteModification = (clone $rendezVous->getDateDebut())->modify("-{$delaiFinModification} hours");
        if (new \DateTime() > $dateLimiteModification) {
            $this->addFlash('warning', "Vous ne pouvez plus annuler ce rendez-vous. Le délai d'annulation est de {$delaiFinModification} heures avant le rendez-vous.");
            return $this->redirectToRoute('app_booking_cancel', ['token' => $token]);
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
        
        // Vérifier le délai de fin de modification (en heures)
        $delaiFinModification = $event->getDelaiFinModification() ?? 24; // Par défaut 24h
        $dateLimiteModification = (clone $rendezVous->getDateDebut())->modify("-{$delaiFinModification} hours");
        if (new \DateTime() > $dateLimiteModification) {
            $this->addFlash('warning', "Vous ne pouvez plus modifier ce rendez-vous. Le délai de modification est de {$delaiFinModification} heures avant le rendez-vous.");
            return $this->redirectToRoute('app_home');
        }
        $lieuChoisi = $rendezVous->getTypeLieu();

        // OPTIMISATION : Pour la modification, générer les créneaux uniquement pour le conseiller du rendez-vous
        // au lieu de tout le groupe, pour éviter les timeouts
        $conseiller = $rendezVous->getConseiller();
        if (!$conseiller) {
            $this->addFlash('danger', 'Aucun conseiller associé à ce rendez-vous.');
            return $this->redirectToRoute('app_home');
        }

        // OPTIMISATION : Générer les créneaux disponibles uniquement pour ce conseiller
        // Limiter à 1 mois pour la modification (plus rapide que 2 mois)
        $slotsByDay = $this->generateSlots($conseiller, $event, $rdvRepo, $dispoRepo, $bureauRepo, $outlookService, $lieuChoisi, 1);

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
     * MODIFICATION : Charge un mois supplémentaire via AJAX
     */
    #[Route('/book/modify/{token}/month', name: 'app_booking_modify_month', methods: ['GET'])]
    public function modifyLoadMonth(
        string $token,
        Request $request,
        RendezVousRepository $rdvRepo,
        EvenementRepository $eventRepo,
        DisponibiliteHebdomadaireRepository $dispoRepo,
        BureauRepository $bureauRepo,
        OutlookService $outlookService
    ): \Symfony\Component\HttpFoundation\JsonResponse {
        $rendezVous = $rdvRepo->findOneBy(['cancelToken' => $token]);
        
        if (!$rendezVous) {
            return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'Lien invalide'], 404);
        }

        $month = $request->query->get('month'); // Format: YYYY-MM
        if (!$month) {
            return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'Mois manquant'], 400);
        }

        $event = $rendezVous->getEvenement();
        $conseiller = $rendezVous->getConseiller();
        
        if (!$conseiller) {
            return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'Conseiller non trouvé'], 404);
        }

        $lieuChoisi = $rendezVous->getTypeLieu();

        try {
            // Générer les créneaux pour le mois spécifique
            $monthStart = new \DateTime($month . '-01');
            $monthEnd = (clone $monthStart)->modify('last day of this month');
            
            // Vérifier que le mois demandé n'est pas au-delà de la limite
            $now = new \DateTime();
            $maxMonthsAhead = $event->getLimiteMoisReservation() ?? 12;
            $maxDate = (clone $now)->modify("+$maxMonthsAhead months")->modify('last day of this month');
            
            if ($monthStart > $maxDate) {
                $formattedData = [
                    'label' => $this->formatMonthLabel($monthStart),
                    'days' => []
                ];
                return new \Symfony\Component\HttpFoundation\JsonResponse($formattedData);
            }
            
            // Générer les créneaux pour ce conseiller uniquement
            $slots = $this->generateSlotsForMonth(
                $conseiller,
                $event,
                $rdvRepo,
                $dispoRepo,
                $bureauRepo,
                $outlookService,
                $lieuChoisi,
                $monthStart,
                $monthEnd
            );

            // Formater les données pour le JSON
            $monthData = $slots[$month] ?? null;
            if (!$monthData) {
                // Si le mois n'est pas dans les résultats, créer une structure vide
                $formattedData = [
                    'label' => $this->formatMonthLabel($monthStart),
                    'days' => []
                ];
            } else {
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
            }

            return new \Symfony\Component\HttpFoundation\JsonResponse($formattedData);
        } catch (\Exception $e) {
            error_log('Erreur lors du chargement du mois pour modification: ' . $e->getMessage());
            return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'Erreur serveur'], 500);
        }
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

        $event = $rendezVous->getEvenement();
        
        // Vérifier le délai de fin de modification (en heures)
        $delaiFinModification = $event->getDelaiFinModification() ?? 24; // Par défaut 24h
        $dateLimiteModification = (clone $rendezVous->getDateDebut())->modify("-{$delaiFinModification} hours");
        if (new \DateTime() > $dateLimiteModification) {
            $this->addFlash('warning', "Vous ne pouvez plus modifier ce rendez-vous. Le délai de modification est de {$delaiFinModification} heures avant le rendez-vous.");
            return $this->redirectToRoute('app_booking_modify', ['token' => $token]);
        }

        $dateParam = $request->request->get('date');
        if (!$dateParam) {
            $this->addFlash('danger', 'Veuillez sélectionner une nouvelle date.');
            return $this->redirectToRoute('app_booking_modify', ['token' => $token]);
        }
        $oldDateDebut = clone $rendezVous->getDateDebut();
        $oldDateFin = clone $rendezVous->getDateFin();

        // Nouvelle date - gérer le format "Y-m-d H:i" ou "Y-m-d"
        if (strpos($dateParam, ' ') !== false) {
            // Format "2026-01-29 09:30"
            $newDateDebut = \DateTime::createFromFormat('Y-m-d H:i', $dateParam);
            if (!$newDateDebut) {
                // Essayer avec un format plus flexible
                $newDateDebut = new \DateTime($dateParam);
            }
        } else {
            // Format "2026-01-29" seulement
            $newDateDebut = new \DateTime($dateParam);
        }
        
        if (!$newDateDebut) {
            $this->addFlash('danger', 'Format de date invalide.');
            return $this->redirectToRoute('app_booking_modify', ['token' => $token]);
        }
        
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
        if ($groupeOrUser instanceof Groupe) {
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
        $isGroupe = $groupeOrUser instanceof Groupe;
        $conseillers = $isGroupe ? $groupeOrUser->getUsers()->toArray() : [$groupeOrUser];
        
        // IMPORTANT : Pour les cabinets, on doit vérifier TOUS les conseillers pour identifier les conflits de salles
        // Pour "A domicile" ou "Teams", on vérifie uniquement le conseiller concerné
        $isCabinet = in_array($lieu, ['Cabinet-geneve', 'Cabinet-archamps']);
        $tousLesConseillers = null;
        if ($isCabinet) {
            // Récupérer tous les conseillers du groupe de l'événement pour vérifier les conflits de salles
            $tousLesConseillers = $event->getGroupe()->getUsers()->toArray();
        }
        
        if (empty($conseillers)) {
            return [];
        }

        // OPTIMISATION N+1 : Pré-charger les relations Evenement et Conseiller en une seule requête
        // Cela évite des centaines de requêtes supplémentaires dans la boucle
        $allRdvs = $rdvRepo->createQueryBuilder('r')
            ->leftJoin('r.evenement', 'e')
            ->addSelect('e')
            ->leftJoin('r.conseiller', 'c')
            ->addSelect('c')
            ->where('r.conseiller IN (:conseillers)')
            ->andWhere('r.dateDebut BETWEEN :start AND :end')
            ->setParameter('conseillers', $conseillers)
            ->setParameter('start', $startPeriod)
            ->setParameter('end', $endPeriod)
            ->getQuery()->getResult();

        // OPTIMISATION : Pré-charger tous les RDV avec bureau pour ce lieu (une seule requête)
        // IMPORTANT : Ne charger les RDV avec bureau QUE pour les cabinets physiques
        // Pour "Visioconférence" ou "Domicile", pas besoin de vérifier les salles
        // IMPORTANT : Les rendez-vous annulés sont supprimés de la base de données, donc ils ne sont pas dans $allBureauxRdvs
        $allBureauxRdvs = [];
        if (in_array($lieu, ['Cabinet-geneve', 'Cabinet-archamps'])) {
            $entityManager = $rdvRepo->getEntityManager();
            // OPTIMISATION N+1 : Pré-charger aussi Evenement pour éviter les requêtes supplémentaires
            $query = $entityManager->createQuery(
                'SELECT r, b, e
         FROM App\Entity\RendezVous r
         INNER JOIN r.bureau b
         LEFT JOIN r.evenement e
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
        // IMPORTANT : Ne charger les bureaux QUE pour les cabinets physiques
        // Pour "Visioconférence" ou "Domicile", on ne vérifie QUE la disponibilité du conseiller
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
        // Cache pour les vérifications Outlook (actuellement désactivé pour performance)
        // Les vérifications Outlook sont faites uniquement lors de la finalisation
        $outlookDayCache = [];
        $outlookConseillersCache = [];

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
                'hasAvailability' => false,
                'dateValue' => $currentDate->format('Y-m-d') // Format pour JavaScript/Twig
            ];

            if (!$dayData['isPast']) {
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

                // IMPORTANT : Utiliser UNIQUEMENT les disponibilités hebdomadaires configurées sur /mon-agenda/
                // Si aucune disponibilité n'est configurée pour ce jour, ne pas générer de créneaux
                if (!isset($rulesByDay[$dayOfWeek]) || empty($rulesByDay[$dayOfWeek])) {
                    // Aucune disponibilité configurée pour ce jour → pas de créneaux
                    // Le jour sera affiché mais sans horaires disponibles
                } else {
                    // NOUVELLE APPROCHE : Itérer sur les conseillers et générer les créneaux à partir de leurs disponibilités
                    // Cela garantit que tous les créneaux sont générés, même si plusieurs règles existent pour le même jour
                    
                    // Pour chaque conseiller, trouver ses disponibilités pour ce jour
                    foreach ($conseillers as $conseiller) {
                        // Trouver toutes les disponibilités de ce conseiller pour ce jour
                        $conseillerDispos = array_filter($disposHebdo, function($d) use ($conseiller, $dayOfWeek) {
                            return $d->getUser() === $conseiller && $d->getJourSemaine() === $dayOfWeek && !$d->isEstBloque();
                        });
                        
                        if (empty($conseillerDispos)) {
                            // Ce conseiller n'a pas de disponibilité pour ce jour
                            continue;
                        }
                        
                        // Pour chaque disponibilité de ce conseiller, générer les créneaux
                        foreach ($conseillerDispos as $dispo) {
                            $start = (clone $currentDate)->setTime((int)$dispo->getHeureDebut()->format('H'), (int)$dispo->getHeureDebut()->format('i'));
                            $end = (clone $currentDate)->setTime((int)$dispo->getHeureFin()->format('H'), (int)$dispo->getHeureFin()->format('i'));

                            while ($start < $end) {
                                $slotEnd = (clone $start)->modify("+$duration minutes");
                                if ($slotEnd > $end) break;

                                // VÉRIFICATION : Le créneau doit respecter le délai minimum de réservation
                                // IMPORTANT : Cette vérification ne s'applique QUE pour le jour actuel
                                // Pour les jours futurs, on affiche tous les créneaux disponibles selon les disponibilités
                                if ($currentDate->format('Y-m-d') === $now->format('Y-m-d') && $start < $minBookingTime) {
                                    $start->modify("+$increment minutes");
                                    continue;
                                }

                                // Initialiser $isFree selon le type (groupe = false, conseiller spécifique = false)
                                $isFree = false;
                                
                                // OPTIMISATION : Calculer le quota une seule fois par jour/conseiller au lieu de le faire pour chaque créneau
                                static $quotaCache = [];
                                $quotaKey = $conseiller->getId() . '_' . $currentDate->format('Y-m-d');
                                if (!isset($quotaCache[$quotaKey])) {
                                    $quotaCache[$quotaKey] = $rdvRepo->countRendezVousForUserOnDate($conseiller, $currentDate);
                                }
                                $rdvCount = $quotaCache[$quotaKey];
                                
                                // Vérifier le quota 3/jour pour ce conseiller
                                if ($rdvCount >= 3) {
                                    if (!$isGroupe) {
                                        // Si c'est un conseiller spécifique et qu'il a atteint son quota, le créneau n'est pas disponible
                                        $isFree = false;
                                        $start->modify("+$increment minutes");
                                        continue;
                                    }
                                    // Pour un groupe, on continue avec les autres conseillers
                                    $start->modify("+$increment minutes");
                                    continue;
                                }
                                
                                // Le créneau est dans la plage de disponibilité (on itère déjà sur les disponibilités du conseiller)
                                // Pas besoin de vérifier à nouveau, on sait déjà que le créneau est dans la plage
                                
                                // Vérifier si ce conseiller est libre (pas de RDV qui chevauche)
                                // IMPORTANT : Les rendez-vous annulés sont supprimés de la base de données, donc ils ne sont pas dans $rdvsDuJour
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
                                
                                if ($conseillerIsFree) {
                                    $isFree = true;
                                    // Pour un conseiller spécifique, on a trouvé un créneau disponible, on peut continuer
                                    // Pour un groupe, on continue pour vérifier les autres conseillers (mais on a déjà trouvé un disponible)
                                } else {
                                    // Le conseiller n'est pas libre pour ce créneau
                                    // Pour un conseiller spécifique, le créneau n'est pas disponible
                                    if (!$isGroupe) {
                                        $isFree = false;
                                    }
                                    // Pour un groupe, on continue avec les autres conseillers
                                }
                                
                                // OPTIMISATION : Vérification des salles en mémoire (pas de requête SQL)
                                // IMPORTANT : Ne vérifier les salles QUE pour les cabinets physiques
                                // Pour "Visioconférence" ou "Domicile", on ne vérifie QUE la disponibilité du conseiller
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
                                        // IMPORTANT : Vérifier Outlook pour les créneaux libres en BDD locale
                                        // Car un conseiller peut avoir réservé directement dans Outlook sans passer par l'application
                                        // OPTIMISATION : On vérifie Outlook UNIQUEMENT pour les créneaux libres en BDD (réduit les appels API)
                                        // Limité à 7 jours pour équilibrer performance et exactitude
                                        $daysFromNow = (int)(($currentDate->getTimestamp() - $now->getTimestamp()) / 86400);
                                        if ($daysFromNow >= 0 && $daysFromNow <= 7) {
                                            // Pour les cabinets : vérifier TOUTES les salles (pas seulement celles libres en BDD)
                                            // Car un conseiller peut avoir réservé directement dans Outlook
                                            if ($isCabinet && !empty($allBureaux)) {
                                                // Cache en mémoire pour éviter les appels répétés (par jour et heure)
                                                $cacheKey = $currentDate->format('Y-m-d') . '_' . $start->format('H:i') . '_salles';
                                                if (!isset($outlookConseillersCache[$cacheKey])) {
                                                    // Pour les cabinets, on a besoin d'un conseiller pour le token
                                                    $conseillerPourToken = null;
                                                    if ($tousLesConseillers && count($tousLesConseillers) > 0) {
                                                        $conseillerPourToken = $tousLesConseillers[0];
                                                    } elseif ($conseillers && count($conseillers) > 0) {
                                                        $conseillerPourToken = $conseillers[0];
                                                    }
                                                    
                                                    if ($conseillerPourToken) {
                                                        try {
                                                            // Vérifier TOUTES les salles du cabinet (batch API - une seule requête)
                                                            $sallesDisponibles = $outlookService->hasAtLeastOneFreeRoomOnOutlook(
                                                                $conseillerPourToken,
                                                                $allBureaux, // TOUTES les salles, car un conseiller peut avoir réservé directement dans Outlook
                                                                $start,
                                                                $slotEnd
                                                            );
                                                            $outlookConseillersCache[$cacheKey] = $sallesDisponibles;
                                                        } catch (\Exception $e) {
                                                            error_log('[OUTLOOK] Erreur vérification salles: ' . $e->getMessage());
                                                            $outlookConseillersCache[$cacheKey] = false; // En cas d'erreur, on masque (sécurité)
                                                        }
                                                    } else {
                                                        $outlookConseillersCache[$cacheKey] = false; // Pas de conseiller = on masque
                                                    }
                                                }
                                                
                                                // Si toutes les salles sont occupées → masquer le créneau
                                                if (!$outlookConseillersCache[$cacheKey]) {
                                                    $isFree = false;
                                                }
                                            }
                                            
                                            // Pour les groupes (non-cabinet) : vérifier les conseillers
                                            if ($isGroupe && !empty($conseillers)) {
                                                $cacheKey = $currentDate->format('Y-m-d') . '_' . $start->format('H:i') . '_conseillers';
                                                if (!isset($outlookConseillersCache[$cacheKey])) {
                                                    $firstConseiller = $conseillers[0] ?? null;
                                                    if ($firstConseiller) {
                                                        try {
                                                            // Vérifier les conseillers (batch API - une seule requête)
                                                            $conseillersDisponibles = $outlookService->hasAtLeastOneAvailableConseillerOnOutlook(
                                                                $firstConseiller,
                                                                $conseillers,
                                                                $start,
                                                                $slotEnd
                                                            );
                                                            $outlookConseillersCache[$cacheKey] = $conseillersDisponibles;
                                                        } catch (\Exception $e) {
                                                            error_log('[OUTLOOK] Erreur vérification conseillers: ' . $e->getMessage());
                                                            $outlookConseillersCache[$cacheKey] = false; // En cas d'erreur, on masque (sécurité)
                                                        }
                                                    } else {
                                                        $outlookConseillersCache[$cacheKey] = false;
                                                    }
                                                }
                                                
                                                // Si aucun conseiller n'est disponible → masquer le créneau
                                                if (!$outlookConseillersCache[$cacheKey]) {
                                                    $isFree = false;
                                                }
                                            }
                                        }
                                    }
                                }
                                
                                if ($isFree) {
                                    // Éviter les doublons (si plusieurs conseillers ont les mêmes disponibilités)
                                    $slotTime = $start->format('H:i');
                                    if (!in_array($slotTime, $dayData['slots'], true)) {
                                        $dayData['slots'][] = $slotTime;
                                        $dayData['hasAvailability'] = true;
                                    }
                                }
                                
                                // Passer au créneau suivant
                                $start->modify("+$increment minutes");
                            }
                        }
                    }
                }
            }
            // Trier et dédupliquer les créneaux pour ce jour
            if (!empty($dayData['slots'])) {
                $dayData['slots'] = array_unique($dayData['slots']);
                sort($dayData['slots']);
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
            $adresse = 'Chemin du Pavillon 2, 1218 Le Grand-Saconnex, Suisse';
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
    private function sendConfirmationEmails(MailerInterface $mailer, RendezVous $rdv, LoggerInterface $logger): void
    {
        try {
            $logger->info('Génération du contenu ICS', ['rdv_id' => $rdv->getId()]);
        $icsContent = $this->generateIcsContent($rdv);

            $logger->info('Création de l\'email', ['email' => $rdv->getEmail()]);
        $email = (new TemplatedEmail())
            ->from('automate@planifique.com')
            ->to($rdv->getEmail())
            ->subject('Confirmation de rendez-vous : ' . $rdv->getEvenement()->getTitre())
            ->htmlTemplate('emails/booking_confirmation_client.html.twig')
            ->context(['rdv' => $rdv])
            // ICS public sans bureau interne
            ->attach($icsContent, 'rendez-vous.ics', 'application/octet-stream');

            $mailerDsn = $_ENV['MAILER_DSN'] ?? getenv('MAILER_DSN') ?? 'NON CONFIGURÉ';
            $logger->info('Envoi de l\'email via MailerInterface', [
                'email' => $rdv->getEmail(),
                'mailer_dsn' => $mailerDsn ? 'CONFIGURÉ' : 'NON CONFIGURÉ'
            ]);

        $mailer->send($email);
            
            $logger->info('Email envoyé avec succès', ['email' => $rdv->getEmail()]);
        } catch (\Symfony\Component\Mailer\Exception\TransportExceptionInterface $e) {
            $logger->error('Erreur de transport SMTP', [
                'email' => $rdv->getEmail(),
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        } catch (\Exception $e) {
            $logger->error('Exception lors de l\'envoi de l\'email', [
                'email' => $rdv->getEmail(),
                'error' => $e->getMessage(),
                'type' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
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

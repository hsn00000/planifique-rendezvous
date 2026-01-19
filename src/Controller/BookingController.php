<?php

namespace App\Controller;

use App\Entity\Evenement;
use App\Entity\RendezVous;
use App\Entity\User;
use App\Entity\Bureau;
use App\Form\BookingFormType;
use App\Repository\BureauRepository; // NOUVEAU
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
use Symfony\Component\Form\Extension\Core\Type\ChoiceType; // Pour le form étape 1

class BookingController extends AbstractController
{
    /**
     * ÉTAPE 1 : Choix du Lieu (Nouveau Point d'Entrée)
     */
    #[Route('/book/start/{eventId}', name: 'app_booking_start', requirements: ['eventId' => '\d+'])]
    public function start(
        int $eventId,
        Request $request,
        EvenementRepository $eventRepo
    ): Response
    {
        $event = $eventRepo->find($eventId);
        if (!$event) throw $this->createNotFoundException("Événement introuvable.");

        // Formulaire simple pour le choix du lieu
        $form = $this->createFormBuilder()
            ->add('typeLieu', ChoiceType::class, [
                'label' => 'Où souhaitez-vous réaliser ce rendez-vous ?',
                'choices'  => [
                    'Visioconférence (Teams/Zoom)' => 'Visioconférence',
                    'A mon domicile / Bureau' => 'Domicile',
                    'Au cabinet de Genève' => 'Cabinet-geneve',
                    "Au cabinet d'Archamps" => 'Cabinet-archamps',
                ],
                'expanded' => true, // Boutons radios
                'multiple' => false,
                'attr' => ['class' => 'form-select-lg']
            ])
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            // On redirige vers le calendrier en passant le LIEU choisi
            return $this->redirectToRoute('app_booking_calendar', [
                'eventId' => $eventId,
                'lieu' => $data['typeLieu']
            ]);
        }

        return $this->render('booking/start.html.twig', [
            'event' => $event,
            'form' => $form->createView()
        ]);
    }

    /**
     * ÉTAPE 2 : Affichage du calendrier (Filtré par Lieu + Bureau)
     */
    #[Route('/book/calendar/{eventId}/{userId?}', name: 'app_booking_calendar', requirements: ['eventId' => '\d+', 'userId' => '\d+'])]
    public function calendar(
        Request $request,
        int $eventId,
        ?int $userId,
        EvenementRepository $eventRepo,
        UserRepository $userRepo,
        RendezVousRepository $rdvRepo,
        DisponibiliteHebdomadaireRepository $dispoRepo,
        BureauRepository $bureauRepo, // Injection du repo Bureau
        OutlookService $outlookService
    ): Response
    {
        // On récupère le lieu depuis l'URL
        $lieuChoisi = $request->query->get('lieu');

        // Si pas de lieu, on renvoie à l'étape 1
        if (!$lieuChoisi) {
            return $this->redirectToRoute('app_booking_start', ['eventId' => $eventId]);
        }

        $event = $eventRepo->find($eventId);
        if (!$event) throw $this->createNotFoundException("Événement introuvable.");

        $targetUser = $userId ? $userRepo->find($userId) : null;
        $displayUser = $targetUser ?? $event->getGroupe()->getUsers()->first();

        if (!$displayUser) throw $this->createNotFoundException("Aucun conseiller dans ce groupe.");

        $outlookService->synchronizeCalendar($displayUser);

        // On passe le BureauRepo et le Lieu à la fonction de génération
        $slotsByDay = $this->generateSlots($displayUser, $event, $rdvRepo, $dispoRepo, $bureauRepo, $lieuChoisi);

        return $this->render('booking/index.html.twig', [
            'conseiller' => $targetUser,
            'event' => $event,
            'slotsByDay' => $slotsByDay,
            'lieuChoisi' => $lieuChoisi // Important pour les liens "Suivant"
        ]);
    }

    /**
     * ÉTAPE 3 : Confirmation et Finalisation
     */
    #[Route('/book/confirm/{eventId}', name: 'app_booking_confirm')]
    public function confirm(
        Request $request,
        int $eventId,
        EvenementRepository $eventRepo,
        RendezVousRepository $rdvRepo,
        UserRepository $userRepo,
        BureauRepository $bureauRepo, // Injection du repo Bureau
        EntityManagerInterface $em,
        MailerInterface $mailer,
        OutlookService $outlookService
    ): Response
    {
        $event = $eventRepo->find($eventId);
        if (!$event) return $this->redirectToRoute('app_home');

        // Récupération du lieu depuis l'URL
        $lieuChoisi = $request->query->get('lieu');
        if (!$lieuChoisi) return $this->redirectToRoute('app_booking_start', ['eventId' => $eventId]);

        $rendezVous = new RendezVous();
        $rendezVous->setEvenement($event);
        $rendezVous->setTypeLieu($lieuChoisi); // On force le lieu choisi

        // 1. Date
        $dateParam = $request->query->get('date');
        if ($dateParam) {
            try {
                $startDate = new \DateTime($dateParam);
                if ($event->getDateLimite() && $startDate > $event->getDateLimite()) {
                    $this->addFlash('danger', 'La date limite pour cet événement est dépassée.');
                    return $this->redirectToRoute('app_booking_calendar', ['eventId' => $event->getId(), 'lieu' => $lieuChoisi]);
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

            // --- A. GESTION DU CONSEILLER (Logique existante) ---
            $conseillerFinal = $rendezVous->getConseiller();

            if ($conseillerFinal) {
                if (!$this->checkDispoWithBuffers($conseillerFinal, $rendezVous->getDateDebut(), $event->getDuree(), $rdvRepo, $outlookService)) {
                    $this->addFlash('danger', 'Ce conseiller n\'est plus disponible (conflit avec les temps de pause/trajet).');
                    return $this->redirectToRoute('app_booking_calendar', [
                        'eventId' => $eventId,
                        'userId' => $conseillerImpose ? $conseillerImpose->getId() : null,
                        'lieu' => $lieuChoisi
                    ]);
                }
            } else {
                $conseillerTrouve = $this->findAvailableConseiller(
                    $event->getGroupe(),
                    $rendezVous->getDateDebut(),
                    $event->getDuree(),
                    $rdvRepo,
                    $outlookService
                );

                if (!$conseillerTrouve) {
                    $this->addFlash('danger', 'Aucun conseiller n\'est disponible sur ce créneau.');
                    return $this->redirectToRoute('app_booking_calendar', ['eventId' => $eventId, 'lieu' => $lieuChoisi]);
                }
                $rendezVous->setConseiller($conseillerTrouve);
            }

            // --- B. GESTION DU BUREAU (NOUVEAU) ---
            // Si c'est Genève ou Archamps, on DOIT trouver un bureau libre maintenant
            if (in_array($lieuChoisi, ['Cabinet-geneve', 'Cabinet-archamps'])) {

                $bureauLibre = $bureauRepo->findAvailableBureau(
                    $lieuChoisi,
                    $rendezVous->getDateDebut(),
                    $rendezVous->getDateFin()
                );

                if (!$bureauLibre) {
                    // Cas rare : le dernier bureau a été pris pendant que l'user remplissait le form
                    $this->addFlash('danger', 'Désolé, le dernier bureau disponible pour ce lieu vient d\'être réservé. Veuillez choisir un autre horaire.');
                    return $this->redirectToRoute('app_booking_calendar', ['eventId' => $eventId, 'lieu' => $lieuChoisi]);
                }

                // On assigne le bureau trouvé
                $rendezVous->setBureau($bureauLibre);
            }
            // --------------------------------------

            // Enregistrement
            $em->persist($rendezVous);
            $em->flush();

            // Outlook (+ Invitation Salle Exchange automatique via le Service)
            try {
                if ($rendezVous->getConseiller()) {
                    $outlookService->addEventToCalendar($rendezVous->getConseiller(), $rendezVous);
                }
            } catch (\Exception $e) {}

            $this->sendConfirmationEmails($mailer, $rendezVous, $event);

            return $this->redirectToRoute('app_booking_success', ['id' => $rendezVous->getId()]);
        }

        return $this->render('booking/details.html.twig', [
            'form' => $form->createView(),
            'event' => $event,
            'dateChoisie' => $rendezVous->getDateDebut(),
            'conseiller' => $rendezVous->getConseiller(),
        ]);
    }

    // --- LOGIQUE DES TAMPONS (inchangé) ---
    private function checkDispoWithBuffers(User $user, \DateTime $start, int $duree, $rdvRepo, $outlookService): bool
    {
        $slotStart = clone $start;
        $slotEnd = (clone $start)->modify("+$duree minutes");

        // 1. Quota
        if ($rdvRepo->countRendezVousForUserOnDate($user, $start) >= 3) return false;

        // 2. BDD avec Tampons
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
            $tAvant = $rdv->getEvenement()->getTamponAvant();
            $tApres = $rdv->getEvenement()->getTamponApres();
            $busyStart = (clone $rdv->getDateDebut())->modify("-{$tAvant} minutes");
            $busyEnd = (clone $rdv->getDateFin())->modify("+{$tApres} minutes");

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

    // --- CALENDRIER OPTIMISÉ (Avec Filtre Bureau) ---

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
                $rdvsDuJour = array_filter($allRdvs, function($r) use ($dayStartFilter, $dayEndFilter) {
                    return $r->getDateDebut() >= $dayStartFilter && $r->getDateDebut() <= $dayEndFilter;
                });

                foreach ($rulesByDay[$dayOfWeek] as $rule) {
                    if ($rule->isEstBloque()) continue;
                    $start = (clone $currentDate)->setTime((int)$rule->getHeureDebut()->format('H'), (int)$rule->getHeureDebut()->format('i'));
                    $end = (clone $currentDate)->setTime((int)$rule->getHeureFin()->format('H'), (int)$rule->getHeureFin()->format('i'));

                    while ($start < $end) {
                        $slotEnd = (clone $start)->modify("+$duration minutes");
                        if ($slotEnd > $end) break;

                        $isFree = true;

                        // 1. Check Conseiller (Tampons)
                        if ($isFree) {
                            foreach ($rdvsDuJour as $rdv) {
                                $tAvant = $rdv->getEvenement()->getTamponAvant();
                                $tApres = $rdv->getEvenement()->getTamponApres();
                                $busyStart = (clone $rdv->getDateDebut())->modify("-{$tAvant} minutes");
                                $busyEnd = (clone $rdv->getDateFin())->modify("+{$tApres} minutes");

                                if ($start < $busyEnd && $slotEnd > $busyStart) {
                                    $isFree = false;
                                    break;
                                }
                            }
                        }

                        // 2. Check Bureau (SI LIEU PHYSIQUE)
                        // Si le conseiller est libre, on vérifie s'il reste un bureau
                        if ($isFree && in_array($lieu, ['Cabinet-geneve', 'Cabinet-archamps'])) {
                            // On demande au repo : "Y a-t-il au moins UN bureau libre ?"
                            // (Pas besoin de savoir lequel pour l'affichage, juste qu'il y en a un)
                            $bureauDispo = $bureauRepo->findAvailableBureau($lieu, $start, $slotEnd);
                            if (!$bureauDispo) {
                                $isFree = false; // Conseiller dispo mais plus de salle
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

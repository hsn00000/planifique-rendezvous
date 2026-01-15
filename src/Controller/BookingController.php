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
     * ÉTAPE 1 : Confirmation et Enregistrement
     * Doit être placée AVANT la route générique pour éviter les conflits.
     */
    #[Route('/book/confirm/{eventId}/{userId?}', name: 'app_booking_confirm')]
    public function confirm(
        Request $request,
        string $eventId, // On récupère l'ID brut pour éviter le crash 404 automatique
        ?string $userId,
        EvenementRepository $eventRepo,
        UserRepository $userRepo,
        EntityManagerInterface $em,
        MailerInterface $mailer,
        OutlookService $outlookService
    ): Response
    {
        // 1. Recherche manuelle des entités
        $event = $eventRepo->find($eventId);
        $user = $userId ? $userRepo->find($userId) : null;

        // Si l'événement n'existe pas, retour à l'accueil
        if (!$event) {
            return $this->redirectToRoute('app_home');
        }

        // 2. Préparation du Rendez-vous
        $rendezVous = new RendezVous();
        $rendezVous->setEvenement($event);
        if ($user) {
            $rendezVous->setConseiller($user);
        }
        $rendezVous->setTypeLieu('Visioconférence');

        // 3. Gestion de la date depuis l'URL (ex: ?date=2026-05-20 14:00)
        $dateParam = $request->query->get('date');
        if ($dateParam) {
            try {
                $startDate = new \DateTime($dateParam);
                $rendezVous->setDateDebut($startDate);
                $endDate = (clone $startDate)->modify('+' . $event->getDuree() . ' minutes');
                $rendezVous->setDateFin($endDate);
            } catch (\Exception $e) {
                // Si la date est invalide dans l'URL, on redirige
                return $this->redirectToRoute('app_home');
            }
        }

        // 4. Création et traitement du formulaire
        $form = $this->createForm(BookingFormType::class, $rendezVous);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            // Enregistrement en base
            $em->persist($rendezVous);
            $em->flush();

            // Synchro Outlook (Optionnel, on capture les erreurs pour ne pas bloquer)
            try {
                if ($rendezVous->getConseiller()) {
                    $outlookService->addEventToCalendar($rendezVous->getConseiller(), $rendezVous);
                }
            } catch (\Exception $e) {
                // Log l'erreur si besoin
            }

            // Envoi des emails
            $this->sendConfirmationEmails($mailer, $rendezVous, $event);

            // REDIRECTION CRUCIALE (Code 303 pour Turbo)
            return $this->redirectToRoute('app_booking_success', [
                'id' => $rendezVous->getId()
            ], Response::HTTP_SEE_OTHER);
        }

        // Affichage de la vue si pas soumis ou invalide
        return $this->render('booking/confirm.html.twig', [
            'form' => $form->createView(),
            'event' => $event,
            'conseiller' => $user,
            'dateChoisie' => $rendezVous->getDateDebut()
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
            'rendezVous' => $rendezVous
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
        $emailClient = (new TemplatedEmail())
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
                            if (!$rdvRepo->findOneBy(['conseiller' => $user, 'dateDebut' => $start])) {
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
}

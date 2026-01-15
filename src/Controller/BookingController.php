<?php

namespace App\Controller;

use App\Entity\Evenement;
use App\Entity\RendezVous;
use App\Entity\User;
use App\Form\BookingFormType;
use App\Repository\DisponibiliteHebdomadaireRepository;
use App\Repository\RendezVousRepository;
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
    #[Route('/book/{user}/{event}', name: 'app_booking_personal')]
    public function bookPersonal(User $user, Evenement $event, RendezVousRepository $rdvRepo, DisponibiliteHebdomadaireRepository $dispoRepo): Response
    {
        $slotsByDay = $this->generateSlots($user, $event, $rdvRepo, $dispoRepo);

        return $this->render('booking/index.html.twig', [
            'conseiller' => $user,
            'event' => $event,
            'slotsByDay' => $slotsByDay
        ]);
    }

    #[Route('/book/confirm/{event}/{user?}', name: 'app_booking_confirm')]
    public function confirm(
        Request $request,
        Evenement $event,
        ?User $user,
        EntityManagerInterface $em,
        MailerInterface $mailer,
        OutlookService $outlookService
    ): Response
    {
        $rendezVous = new RendezVous();
        $rendezVous->setEvenement($event);
        if ($user) $rendezVous->setConseiller($user);

        // Valeur par défaut obligatoire pour ton entité
        $rendezVous->setTypeLieu('Visioconférence');

        // Gestion de la date depuis l'URL
        $dateParam = $request->query->get('date');
        if ($dateParam) {
            try {
                $startDate = new \DateTime($dateParam);
                $rendezVous->setDateDebut($startDate);

                // CRUCIAL : Calculer la date de fin pour la validation de l'entité
                $endDate = (clone $startDate)->modify('+' . $event->getDuree() . ' minutes');
                $rendezVous->setDateFin($endDate);

            } catch (\Exception $e) {
                // Si la date est invalide, retour à l'accueil
                return $this->redirectToRoute('app_home');
            }
        }

        $form = $this->createForm(BookingFormType::class, $rendezVous);
        $form->handleRequest($request);

        if ($form->isSubmitted() && !$form->isValid()) {
            // Cela affichera toutes les erreurs dans la barre de debug de Symfony (en bas)
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('error', $error->getOrigin()->getName() . ' : ' . $error->getMessage());
            }
        }

        // Si le formulaire est soumis et valide
        if ($form->isSubmitted() && $form->isValid()) {

            // 1. Sauvegarde en Base de Données
            $em->persist($rendezVous);
            $em->flush();

            // 2. Synchronisation Outlook (Sécurisée par un try/catch)
            try {
                if ($rendezVous->getConseiller()) {
                    $outlookService->addEventToCalendar($rendezVous->getConseiller(), $rendezVous);
                }
            } catch (\Exception $e) {
                // On continue même si Outlook échoue
            }

            // 3. Email Client
            $emailClient = new TemplatedEmail()
                ->from('no-reply@planifique.com')
                ->to($rendezVous->getEmail())
                ->subject('Confirmation RDV : ' . $event->getTitre())
                ->htmlTemplate('emails/booking_confirmation_client.html.twig')
                ->context(['rdv' => $rendezVous]);

            try { $mailer->send($emailClient); } catch (\Exception $e) {}

            // 4. Email Conseiller
            if ($rendezVous->getConseiller()) {
                $emailConseiller = new TemplatedEmail()
                    ->from('no-reply@planifique.com')
                    ->to($rendezVous->getConseiller()->getEmail())
                    ->subject('Nouveau RDV : ' . $rendezVous->getNom() . ' ' . $rendezVous->getPrenom())
                    ->htmlTemplate('emails/booking_notification_conseiller.html.twig')
                    ->context(['rdv' => $rendezVous]);

                try { $mailer->send($emailConseiller); } catch (\Exception $e) {}
            }

            return $this->render('booking/success.html.twig', [
                'rendezVous' => $rendezVous
            ]);
        }

        // Affichage du formulaire (si pas soumis ou erreurs)
        return $this->render('booking/confirm.html.twig', [
            'form' => $form->createView(),
            'event' => $event,
            'conseiller' => $user,
            'dateChoisie' => $rendezVous->getDateDebut()
        ]);
    }

    /**
     * Génère les créneaux horaires disponibles
     */
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
        foreach ($disposHebdo as $dispo) { $rulesByDay[$dispo->getJourSemaine()][] = $dispo; }

        $currentDate = clone $startPeriod;
        while ($currentDate <= $endPeriod) {
            $sortKey = $currentDate->format('Y-m');
            if (!isset($calendarData[$sortKey])) {
                $calendarData[$sortKey] = ['label' => clone $currentDate, 'days' => []];
            }
            $dayData = [
                'dateObj' => clone $currentDate,
                'dayNum' => $currentDate->format('d'),
                'isToday' => $currentDate->format('Y-m-d') === (new \DateTime())->format('Y-m-d'),
                'isPast' => $currentDate < new \DateTime('today'),
                'slots' => [],
                'hasAvailability' => false
            ];

            if (!$dayData['isPast']) {
                $dayOfWeek = (int)$currentDate->format('N');
                if (isset($rulesByDay[$dayOfWeek])) {
                    foreach ($rulesByDay[$dayOfWeek] as $rule) {
                        if ($rule->isEstBloque()) continue;
                        $start = (clone $currentDate)->setTime((int)$rule->getHeureDebut()->format('H'), (int)$rule->getHeureDebut()->format('i'));
                        $end = (clone $currentDate)->setTime((int)$rule->getHeureFin()->format('H'), (int)$rule->getHeureFin()->format('i'));
                        while ($start < $end) {
                            $slotEnd = (clone $start)->modify("+$duration minutes");
                            if ($slotEnd > $end) break;
                            $isBusy = $rdvRepo->findOneBy(['conseiller' => $user, 'dateDebut' => $start]);
                            if (!$isBusy) {
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

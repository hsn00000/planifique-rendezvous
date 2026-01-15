<?php

namespace App\Controller;

use App\Entity\Evenement;
use App\Entity\RendezVous;
use App\Entity\User;
use App\Form\BookingFormType;
use App\Repository\DisponibiliteHebdomadaireRepository;
use App\Repository\RendezVousRepository;
use App\Repository\UserRepository; // On ajoute le repository
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

    // On utilise {userId} au lieu de {user} pour éviter que Symfony ne plante s'il ne le trouve pas
    #[Route('/book/confirm/{event}/{userId?}', name: 'app_booking_confirm')]
    public function confirm(
        Request $request,
        Evenement $event,
        ?string $userId, // On récupère l'ID comme une simple chaîne
        UserRepository $userRepo, // On injecte le repo pour chercher l'user nous-mêmes
        EntityManagerInterface $em,
        MailerInterface $mailer,
        OutlookService $outlookService
    ): Response
    {
        // On cherche l'utilisateur. S'il n'existe pas, $user sera null mais le code NE PLANTERA PAS.
        $user = $userId ? $userRepo->find($userId) : null;

        $rendezVous = new RendezVous();
        $rendezVous->setEvenement($event);
        if ($user) $rendezVous->setConseiller($user);
        $rendezVous->setTypeLieu('Visioconférence');

        $dateParam = $request->query->get('date');
        if ($dateParam) {
            try {
                $startDate = new \DateTime($dateParam);
                $rendezVous->setDateDebut($startDate);
                $endDate = (clone $startDate)->modify('+' . $event->getDuree() . ' minutes');
                $rendezVous->setDateFin($endDate);
            } catch (\Exception $e) {
                return $this->redirectToRoute('app_home');
            }
        }

        $form = $this->createForm(BookingFormType::class, $rendezVous);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($rendezVous);
            $em->flush();

            // Synchro Outlook + Emails
            try {
                if ($rendezVous->getConseiller()) {
                    $outlookService->addEventToCalendar($rendezVous->getConseiller(), $rendezVous);
                }
            } catch (\Exception $e) {}

            $this->sendConfirmationEmails($mailer, $rendezVous, $event);

            // REDIRECTION REELLE : On va vers une page de succès
            return $this->redirectToRoute('app_booking_success', ['id' => $rendezVous->getId()]);
        }

        return $this->render('booking/confirm.html.twig', [
            'form' => $form->createView(),
            'event' => $event,
            'conseiller' => $user,
            'dateChoisie' => $rendezVous->getDateDebut()
        ]);
    }

    #[Route('/book/success/{id}', name: 'app_booking_success')]
    public function success(RendezVous $rendezVous): Response
    {
        return $this->render('booking/success.html.twig', [
            'rendezVous' => $rendezVous
        ]);
    }

    private function sendConfirmationEmails($mailer, $rdv, $event): void
    {
        $emailClient = (new TemplatedEmail())
            ->from('no-reply@planifique.com')
            ->to($rdv->getEmail())
            ->subject('Confirmation RDV : ' . $event->getTitre())
            ->htmlTemplate('emails/booking_confirmation_client.html.twig')
            ->context(['rdv' => $rdv]);

        try { $mailer->send($emailClient); } catch (\Exception $e) {}
    }

    // Ta méthode generateSlots (calendrier) reste inchangée ici...
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
            if (!isset($calendarData[$sortKey])) { $calendarData[$sortKey] = ['label' => clone $currentDate, 'days' => []]; }
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

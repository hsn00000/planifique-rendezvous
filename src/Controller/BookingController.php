<?php

namespace App\Controller;

use App\Entity\Evenement;
use App\Entity\RendezVous;
use App\Entity\User;
use App\Form\BookingFormType;
use App\Repository\DisponibiliteHebdomadaireRepository;
use App\Repository\RendezVousRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class BookingController extends AbstractController
{
    // CAS 1 : Lien Personnel (On connaît le conseiller)
    #[Route('/book/{user}/{event}', name: 'app_booking_personal')]
    public function bookPersonal(User $user, Evenement $event, RendezVousRepository $rdvRepo, DisponibiliteHebdomadaireRepository $dispoRepo): Response
    {
        // 👇 On passe le dispoRepo à la fonction
        $availableSlots = $this->generateSlots($user, $event, $rdvRepo, $dispoRepo);

        return $this->render('booking/index.html.twig', [
            'conseiller' => $user,
            'event' => $event,
            'isRoundRobin' => false,
            'slotsByDay' => $availableSlots
        ]);
    }

    // CAS 2 : Lien Round Robin (L'équipe)
    #[Route('/book/team/{event}', name: 'app_booking_roundrobin')]
    public function bookRoundRobin(Evenement $event, RendezVousRepository $rdvRepo): Response
    {
        // Pour le Round Robin, on peut par exemple prendre le premier conseiller du groupe
        // ou adapter la logique de génération pour vérifier la disponibilité globale.
        $user = $event->getGroupe()->getUsers()->first() ?: null;
        $availableSlots = $user ? $this->generateSlots($user, $event, $rdvRepo) : [];

        return $this->render('booking/index.html.twig', [
            'conseiller' => null,
            'event' => $event,
            'isRoundRobin' => true,
            'slotsByDay' => $availableSlots
        ]);
    }

    // ÉTAPE 2 : Formulaire de confirmation
    #[Route('/book/confirm/{event}/{user?}', name: 'app_booking_confirm')]
    public function confirm(Request $request, Evenement $event, ?User $user, EntityManagerInterface $em): Response
    {
        $rendezVous = new RendezVous();
        $rendezVous->setEvenement($event);
        if ($user) $rendezVous->setConseiller($user);

        // Récupération de la date passée en paramètre URL (ex: ?date=2026-01-14 10:30)
        $dateStr = $request->query->get('date');
        if ($dateStr) {
            $rendezVous->setDateDebut(new \DateTime($dateStr));
        }

        $form = $this->createForm(BookingFormType::class, $rendezVous);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($rendezVous);
            $em->flush();

            return $this->render('booking/success.html.twig', [
                'rendezVous' => $rendezVous
            ]);
        }

        return $this->render('booking/confirm.html.twig', [
            'event' => $event,
            'conseiller' => $user,
            'form' => $form->createView(),
            'isRoundRobin' => $event->isRoundRobin()
        ]);
    }

    /**
     * Logique de génération des créneaux de 9h à 18h
     */
    /**
     * Génère les créneaux basés sur le planning HEBDOMADAIRE
     */
    private function generateSlots(User $user, Evenement $event, RendezVousRepository $rdvRepo, DisponibiliteHebdomadaireRepository $dispoRepo): array
    {
        $slotsByDay = [];
        $duration = $event->getDuree();
        $today = new \DateTime();

        // 1. On récupère toutes les règles hebdo du conseiller
        $disposHebdo = $dispoRepo->findBy(['user' => $user]);

        // On les organise par jour de semaine pour un accès rapide (1 => [Dispo1, Dispo2], 2 => ...)
        $rulesByDay = [];
        foreach ($disposHebdo as $dispo) {
            $rulesByDay[$dispo->getJourSemaine()][] = $dispo;
        }

        // 2. On boucle sur les 30 prochains jours
        for ($i = 0; $i < 30; $i++) {
            $date = (clone $today)->modify("+$i days");
            $dayOfWeek = (int)$date->format('N'); // 1 (Lundi) à 7 (Dimanche)
            $dayKey = $date->format('Y-m-d');

            // S'il n'y a aucune règle pour ce jour de la semaine, on passe
            if (!isset($rulesByDay[$dayOfWeek])) {
                continue;
            }

            $slotsByDay[$dayKey] = [];

            // 3. Pour chaque plage horaire définie ce jour-là (ex: 9h-12h et 14h-17h)
            foreach ($rulesByDay[$dayOfWeek] as $rule) {
                // On crée le DateTime de début et fin pour CE jour spécifique
                $start = (clone $date)->setTime(
                    (int)$rule->getHeureDebut()->format('H'),
                    (int)$rule->getHeureDebut()->format('i')
                );
                $end = (clone $date)->setTime(
                    (int)$rule->getHeureFin()->format('H'),
                    (int)$rule->getHeureFin()->format('i')
                );

                // 4. On découpe la plage en créneaux
                while ($start < $end) {
                    $slotStart = clone $start;
                    $slotEnd = (clone $start)->modify("+$duration minutes");

                    // Si le créneau dépasse l'heure de fin, on arrête
                    if ($slotEnd > $end) break;

                    // Vérification : est-ce que ce créneau est déjà pris ?
                    $isBusy = $rdvRepo->findOneBy([
                        'conseiller' => $user,
                        'dateDebut' => $slotStart
                    ]);

                    if (!$isBusy) {
                        $slotsByDay[$dayKey][] = $slotStart->format('H:i');
                    }

                    // On avance au prochain créneau
                    $start = $slotEnd;
                }
            }

            // Si la liste est vide pour ce jour (tout est pris), on supprime la clé
            if (empty($slotsByDay[$dayKey])) {
                unset($slotsByDay[$dayKey]);
            }
        }

        return $slotsByDay;
    }
}

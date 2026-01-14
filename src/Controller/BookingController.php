<?php

namespace App\Controller;

use App\Entity\Evenement;
use App\Entity\RendezVous;
use App\Entity\User;
use App\Form\BookingFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class BookingController extends AbstractController
{
    // CAS 1 : Lien Personnel (On connait le conseiller)
    #[Route('/book/{user}/{event}', name: 'app_booking_personal')]
    public function bookPersonal(User $user, Evenement $event): Response
    {
        return $this->render('booking/index.html.twig', [
            'conseiller' => $user,
            'event' => $event,
            'isRoundRobin' => false
        ]);
    }

    // CAS 2 : Lien Round Robin (On ne connait PAS le conseiller)
    #[Route('/book/team/{event}', name: 'app_booking_roundrobin')]
    public function bookRoundRobin(Evenement $event): Response
    {
        // Ici, on affiche une page générique.
        // Plus tard, quand le client choisira une date, ton algorithme choisira le conseiller.

        return $this->render('booking/index.html.twig', [
            'conseiller' => null, // Pas de conseiller affiché
            'event' => $event,
            'isRoundRobin' => true
        ]);
    }

    #[Route('/book/confirm/{event}/{user?}', name: 'app_booking_confirm')]
    public function confirm(Request $request, Evenement $event, ?User $user, EntityManagerInterface $em): Response
    {
        $rendezVous = new RendezVous();
        $rendezVous->setEvenement($event);
        if ($user) $rendezVous->setConseiller($user);

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
        ]);
    }
}

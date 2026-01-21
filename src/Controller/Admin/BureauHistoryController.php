<?php

namespace App\Controller\Admin;

use App\Entity\Bureau;
use App\Repository\RendezVousRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/bureau-history')]
class BureauHistoryController extends AbstractController
{
    // C'EST CETTE ROUTE QUE SYMFONY NE TROUVAIT PAS
    #[Route('/view/{id}', name: 'admin_bureau_history_view')]
    public function view(Bureau $bureau): Response
    {
        // On affiche le template (que vous devez aussi avoir créé)
        return $this->render('admin/bureau/history.html.twig', [
            'bureau' => $bureau
        ]);
    }

    // API pour le calendrier
    #[Route('/api/{id}', name: 'admin_bureau_history_api')]
    public function api(Bureau $bureau, Request $request, RendezVousRepository $rdvRepo): JsonResponse
    {
        $start = new \DateTime($request->query->get('start'));
        $end = new \DateTime($request->query->get('end'));

        $rdvs = $rdvRepo->createQueryBuilder('r')
            ->where('r.bureau = :bureau')
            ->andWhere('r.dateDebut >= :start')
            ->andWhere('r.dateFin <= :end')
            ->setParameter('bureau', $bureau)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getResult();

        $events = [];
        foreach ($rdvs as $rdv) {
            $events[] = [
                'id' => $rdv->getId(),
                'title' => $rdv->getPrenom() . ' ' . $rdv->getNom(),
                'start' => $rdv->getDateDebut()->format('Y-m-d\TH:i:s'),
                'end' => $rdv->getDateFin()->format('Y-m-d\TH:i:s'),
                'backgroundColor' => $rdv->getDateFin() < new \DateTime() ? '#6c757d' : '#3788d8',
                'borderColor' => $rdv->getDateFin() < new \DateTime() ? '#6c757d' : '#3788d8',
            ];
        }

        return new JsonResponse($events);
    }
}

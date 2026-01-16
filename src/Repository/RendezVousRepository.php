<?php

namespace App\Repository;

use App\Entity\RendezVous;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RendezVous>
 */
class RendezVousRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RendezVous::class);
    }

    /**
     * Utilisé par le VALIDATEUR (Entité)
     * Vérifie si un RDV chevauche, en excluant le RDV lui-même s'il est déjà en base (modification).
     */
    public function countOverlapping(RendezVous $rdv): int
    {
        $qb = $this->createQueryBuilder('r')
            ->select('count(r.id)')
            ->where('r.conseiller = :conseiller')
            ->andWhere('r.dateDebut < :fin')  // Le RDV en base commence AVANT la fin du nouveau
            ->andWhere('r.dateFin > :debut')  // Le RDV en base finit APRÈS le début du nouveau
            ->setParameter('conseiller', $rdv->getConseiller())
            ->setParameter('debut', $rdv->getDateDebut())
            ->setParameter('fin', $rdv->getDateFin());

        // Si c'est une modification, on exclut l'ID actuel pour ne pas qu'il se bloque lui-même
        if ($rdv->getId()) {
            $qb->andWhere('r.id != :id')
                ->setParameter('id', $rdv->getId());
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Utilisé par le CALENDRIER (Controller)
     * Vérifie si une plage horaire brute est libre pour un conseiller.
     */
    public function isSlotAvailable(User $conseiller, \DateTimeInterface $start, \DateTimeInterface $end): bool
    {
        $count = $this->createQueryBuilder('r')
            ->select('count(r.id)')
            ->where('r.conseiller = :conseiller')
            ->andWhere('r.dateDebut < :end')
            ->andWhere('r.dateFin > :start')
            ->setParameter('conseiller', $conseiller)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult();

        return $count == 0;
    }

    public function countRendezVousForUserOnDate(User $user, \DateTimeInterface $date): int
    {
        // On définit le début et la fin de la journée (00:00:00 à 23:59:59)
        $start = (clone $date)->setTime(0, 0, 0);
        $end = (clone $date)->setTime(23, 59, 59);

        return $this->createQueryBuilder('r')
            ->select('count(r.id)')
            ->where('r.conseiller = :user')
            ->andWhere('r.dateDebut BETWEEN :start AND :end')
            ->setParameter('user', $user)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult();
    }
}

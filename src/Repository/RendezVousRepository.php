<?php

namespace App\Repository;

use App\Entity\RendezVous;
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
     * Vérifie s'il existe un chevauchement pour ce conseiller sur cette plage horaire.
     * Exclut le rendez-vous actuel (en cas de modification).
     */
    public function countOverlapping(RendezVous $rdv): int
    {
        $qb = $this->createQueryBuilder('r')
            ->select('count(r.id)')
            ->where('r.conseiller = :conseiller')
            ->andWhere('r.dateDebut < :fin')  // Commence avant la fin du nouveau
            ->andWhere('r.dateFin > :debut')  // Finit après le début du nouveau
            ->setParameter('conseiller', $rdv->getConseiller())
            ->setParameter('debut', $rdv->getDateDebut())
            ->setParameter('fin', $rdv->getDateFin());

        // Si le RDV existe déjà (il a un ID), on l'exclut de la recherche pour ne pas qu'il se bloque lui-même
        if ($rdv->getId()) {
            $qb->andWhere('r.id != :id')
                ->setParameter('id', $rdv->getId());
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    //    /**
    //     * @return RendezVous[] Returns an array of RendezVous objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('r')
    //            ->andWhere('r.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('r.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?RendezVous
    //    {
    //        return $this->createQueryBuilder('r')
    //            ->andWhere('r.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}

<?php

namespace App\Repository;

use App\Entity\Bureau;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class BureauRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Bureau::class);
    }

    public function findAvailableBureau(string $lieu, \DateTimeInterface $start, \DateTimeInterface $end): ?Bureau
    {
        $allBureaux = $this->findBy(['lieu' => $lieu]);
        if (empty($allBureaux)) return null;

        $entityManager = $this->getEntityManager();
        $query = $entityManager->createQuery(
            'SELECT IDENTITY(r.bureau)
             FROM App\Entity\RendezVous r
             WHERE r.bureau IS NOT NULL
             AND r.dateDebut < :end
             AND r.dateFin > :start'
        )->setParameters(['start' => $start, 'end' => $end]);

        $occupiedIds = array_column($query->getScalarResult(), 1);

        foreach ($allBureaux as $bureau) {
            if (!in_array($bureau->getId(), $occupiedIds)) {
                return $bureau;
            }
        }
        return null;
    }
}

<?php

namespace App\Repository;

use App\Entity\Bureau;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Bureau>
 */
class BureauRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Bureau::class);
    }

    /**
     * Trouve un bureau disponible dans un lieu donné pour un créneau donné
     */
    public function findAvailableBureau(string $lieu, \DateTimeInterface $start, \DateTimeInterface $end): ?Bureau
    {
        // 1. On récupère tous les bureaux du lieu demandé
        $allBureaux = $this->findBy(['lieu' => $lieu]);

        if (empty($allBureaux)) {
            return null; // Aucun bureau n'existe à cet endroit
        }

        // 2. On cherche les ID des bureaux occupés sur ce créneau
        $entityManager = $this->getEntityManager();
        $query = $entityManager->createQuery(
            'SELECT IDENTITY(r.bureau)
             FROM App\Entity\RendezVous r
             WHERE r.bureau IS NOT NULL
             AND r.dateDebut < :end
             AND r.dateFin > :start' // Chevauchement temporel
        )->setParameters([
            'start' => $start,
            'end' => $end
        ]);

        $occupiedIds = array_column($query->getScalarResult(), 1);

        // 3. On retourne le premier bureau qui n'est PAS dans la liste des occupés
        foreach ($allBureaux as $bureau) {
            if (!in_array($bureau->getId(), $occupiedIds)) {
                return $bureau;
            }
        }

        return null; // Tous les bureaux sont pris
    }

    /**
     * Retourne tous les bureaux disponibles dans un lieu donné pour un créneau donné
     * (retourne un tableau, pas juste le premier)
     */
    public function findAvailableBureaux(string $lieu, \DateTimeInterface $start, \DateTimeInterface $end): array
    {
        // 1. On récupère tous les bureaux du lieu demandé
        $allBureaux = $this->findBy(['lieu' => $lieu]);

        if (empty($allBureaux)) {
            return [];
        }

        // 2. On cherche les ID des bureaux occupés sur ce créneau (en BDD locale)
        $entityManager = $this->getEntityManager();
        $query = $entityManager->createQuery(
            'SELECT IDENTITY(r.bureau)
         FROM App\Entity\RendezVous r
         WHERE r.bureau IS NOT NULL
         AND r.dateDebut < :end
         AND r.dateFin > :start'
        )->setParameters([
            'start' => $start,
            'end' => $end
        ]);

        $occupiedIds = array_column($query->getScalarResult(), 1);

        // 3. On retourne tous les bureaux qui ne sont PAS dans la liste des occupés
        $free = [];
        foreach ($allBureaux as $bureau) {
            if (!in_array($bureau->getId(), $occupiedIds, true)) {
                $free[] = $bureau;
            }
        }

        return $free;
    }
}

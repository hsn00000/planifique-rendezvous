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

        // 2. On récupère tous les rendez-vous qui chevauchent ce créneau
        $entityManager = $this->getEntityManager();
        $query = $entityManager->createQuery(
            'SELECT r
             FROM App\Entity\RendezVous r
             JOIN r.bureau b
             WHERE b.lieu = :lieu
             AND r.dateDebut < :end
             AND r.dateFin > :start'
        )->setParameters([
            'lieu' => $lieu,
            'start' => $start,
            'end' => $end
        ]);

        $rdvsOccupes = $query->getResult();

        // 3. On extrait les IDs des bureaux occupés
        $occupiedIds = [];
        foreach ($rdvsOccupes as $rdv) {
            $bureau = $rdv->getBureau();
            if ($bureau) {
                $occupiedIds[] = $bureau->getId();
            }
        }
        $occupiedIds = array_unique($occupiedIds);

        // 4. On retourne le premier bureau qui n'est PAS dans la liste des occupés
        foreach ($allBureaux as $bureau) {
            if (!in_array($bureau->getId(), $occupiedIds, true)) {
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

        // 2. On récupère tous les rendez-vous qui chevauchent ce créneau
        $entityManager = $this->getEntityManager();
        $query = $entityManager->createQuery(
            'SELECT r
             FROM App\Entity\RendezVous r
             JOIN r.bureau b
             WHERE b.lieu = :lieu
             AND r.dateDebut < :end
             AND r.dateFin > :start'
        )->setParameters([
            'lieu' => $lieu,
            'start' => $start,
            'end' => $end
        ]);

        $rdvsOccupes = $query->getResult();

        // 3. On extrait les IDs des bureaux occupés
        $occupiedIds = [];
        foreach ($rdvsOccupes as $rdv) {
            $bureau = $rdv->getBureau();
            if ($bureau) {
                $occupiedIds[] = $bureau->getId();
            }
        }
        $occupiedIds = array_unique($occupiedIds);

        // 4. On retourne tous les bureaux qui ne sont PAS dans la liste des occupés
        $free = [];
        foreach ($allBureaux as $bureau) {
            if (!in_array($bureau->getId(), $occupiedIds, true)) {
                $free[] = $bureau;
            }
        }

        return $free;
    }
}

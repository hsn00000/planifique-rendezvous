<?php

namespace App\Entity;

use App\Repository\DisponibiliteHebdomadaireRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DisponibiliteHebdomadaireRepository::class)]
class DisponibiliteHebdomadaire
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private ?int $jourSemaine = null;

    #[ORM\Column(type: Types::TIME_MUTABLE)]
    private ?\DateTimeInterface $heureDebut = null;

    #[ORM\Column(type: Types::TIME_MUTABLE)]
    private ?\DateTimeInterface $heureFin = null;

    #[ORM\ManyToOne(inversedBy: 'disponibiliteHebdomadaires')]
    private ?User $user = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJourSemaine(): ?int
    {
        return $this->jourSemaine;
    }

    public function setJourSemaine(int $jourSemaine): static
    {
        $this->jourSemaine = $jourSemaine;

        return $this;
    }

    public function getHeureDebut(): ?\DateTimeInterface { return $this->heureDebut; }

    public function setHeureDebut(\DateTimeInterface $heureDebut): static { $this->heureDebut = $heureDebut; return $this; }

    public function getHeureFin(): ?\DateTimeInterface { return $this->heureFin; }

    public function setHeureFin(\DateTimeInterface $heureFin): static { $this->heureFin = $heureFin; return $this; }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }
}

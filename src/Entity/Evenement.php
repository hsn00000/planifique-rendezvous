<?php

namespace App\Entity;

use App\Repository\EvenementRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EvenementRepository::class)]
class Evenement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $titre = null;

    #[ORM\Column(length: 255)]
    private ?string $slug = null;

    #[ORM\Column]
    private ?int $duree = null;

    #[ORM\Column(length: 7, nullable: true)]
    private ?string $couleur = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private ?bool $isRoundRobin = false;

    #[ORM\Column(options: ['default' => 0])]
    private ?int $tamponAvant = 0; // En minutes (ex: 30)

    #[ORM\Column(options: ['default' => 0])]
    private ?int $tamponApres = 0; // En minutes (ex: 60)

    #[ORM\Column(options: ['default' => 0])]
    private ?int $delaiMinimumReservation = 0; // Délai minimum en minutes avant de pouvoir réserver (ex: 120 = 2h)

    #[ORM\Column(options: ['default' => 12])]
    private ?int $limiteMoisReservation = 12; // Limite en mois pour les réservations futures (ex: 12 = 1 an maximum)

    #[ORM\ManyToOne(inversedBy: 'evenements')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Groupe $groupe = null;

    /**
     * @var Collection<int, RendezVous>
     */
    #[ORM\OneToMany(targetEntity: RendezVous::class, mappedBy: 'evenement')]
    private Collection $rendezVouses;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitre(): ?string
    {
        return $this->titre;
    }

    public function setTitre(string $titre): static
    {
        $this->titre = $titre;
        // On génère le slug automatiquement si non défini
        if (!$this->slug) {
            $this->slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $titre)));
        }
        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;
        return $this;
    }

    public function getDuree(): ?int
    {
        return $this->duree;
    }

    public function setDuree(int $duree): static
    {
        $this->duree = $duree;
        return $this;
    }

    public function getCouleur(): ?string
    {
        return $this->couleur;
    }

    public function setCouleur(?string $couleur): static
    {
        $this->couleur = $couleur;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function isRoundRobin(): ?bool
    {
        return $this->isRoundRobin;
    }

    public function setIsRoundRobin(bool $isRoundRobin): static
    {
        $this->isRoundRobin = $isRoundRobin;
        return $this;
    }

    public function getGroupe(): ?Groupe
    {
        return $this->groupe;
    }

    public function setGroupe(?Groupe $groupe): static
    {
        $this->groupe = $groupe;
        return $this;
    }

    public function getTamponAvant(): ?int
    {
        return $this->tamponAvant;
    }

    public function setTamponAvant(int $tamponAvant): static
    {
        $this->tamponAvant = $tamponAvant;
        return $this;
    }

    public function getTamponApres(): ?int
    {
        return $this->tamponApres;
    }

    public function setTamponApres(int $tamponApres): static
    {
        $this->tamponApres = $tamponApres;
        return $this;
    }

    public function getDelaiMinimumReservation(): ?int
    {
        return $this->delaiMinimumReservation;
    }

    public function setDelaiMinimumReservation(int $delaiMinimumReservation): static
    {
        $this->delaiMinimumReservation = $delaiMinimumReservation;
        return $this;
    }

    public function getLimiteMoisReservation(): ?int
    {
        return $this->limiteMoisReservation;
    }

    public function setLimiteMoisReservation(int $limiteMoisReservation): static
    {
        $this->limiteMoisReservation = $limiteMoisReservation;
        return $this;
    }

    public function __construct()
    {
        $this->rendezVouses = new ArrayCollection();
    }

    /**
     * @return Collection<int, RendezVous>
     */
    public function getRendezVouses(): Collection
    {
        return $this->rendezVouses;
    }

    public function addRendezVouse(RendezVous $rendezVouse): static
    {
        if (!$this->rendezVouses->contains($rendezVouse)) {
            $this->rendezVouses->add($rendezVouse);
            $rendezVouse->setEvenement($this);
        }

        return $this;
    }

    public function removeRendezVouse(RendezVous $rendezVouse): static
    {
        if ($this->rendezVouses->removeElement($rendezVouse)) {
            // set the owning side to null (unless already changed)
            if ($rendezVouse->getEvenement() === $this) {
                $rendezVouse->setEvenement(null);
            }
        }

        return $this;
    }

    public function __toString(): string
    {
        return $this->titre ?? 'Événement';
    }
}

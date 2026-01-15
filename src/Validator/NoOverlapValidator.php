<?php

namespace App\Validator;

use App\Entity\RendezVous;
use App\Repository\RendezVousRepository;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class NoOverlapValidator extends ConstraintValidator
{
    public function __construct(
        private RendezVousRepository $rdvRepository
    ) {}

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof NoOverlap) {
            throw new UnexpectedTypeException($constraint, NoOverlap::class);
        }

        // $value est ici l'objet RendezVous complet
        if (!$value instanceof RendezVous) {
            return;
        }

        // On ne valide pas si les données sont incomplètes (dateDebut ou Conseiller manquants)
        if (!$value->getDateDebut() || !$value->getDateFin() || !$value->getConseiller()) {
            return;
        }

        // Appel au Repository
        $nbChevauchements = $this->rdvRepository->countOverlapping($value);

        if ($nbChevauchements > 0) {
            // On attache l'erreur au champ "dateDebut" pour qu'elle s'affiche au bon endroit dans le formulaire
            $this->context->buildViolation($constraint->message)
                ->atPath('dateDebut')
                ->addViolation();
        }
    }
}

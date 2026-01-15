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

        if (!$value instanceof RendezVous) {
            return;
        }

        // On ne valide pas si les données essentielles manquent
        if (!$value->getDateDebut() || !$value->getDateFin() || !$value->getConseiller()) {
            return;
        }

        // On demande au repository s'il y a un conflit
        $nbChevauchements = $this->rdvRepository->countOverlapping($value);

        if ($nbChevauchements > 0) {
            // On attache l'erreur au champ "dateDebut" pour qu'elle soit visible
            $this->context->buildViolation($constraint->message)
                ->atPath('dateDebut')
                ->addViolation();
        }
    }
}

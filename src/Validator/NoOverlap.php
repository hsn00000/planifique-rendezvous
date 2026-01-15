<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute]
class NoOverlap extends Constraint
{
    public string $message = 'Ce créneau horaire est déjà réservé (chevauchement détecté).';

    // Cette option permet d'appliquer la contrainte sur la classe entière, pas juste une propriété
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}

<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute]
class NoOverlap extends Constraint
{
    public string $message = 'Ce créneau horaire est déjà réservé par un autre client.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}

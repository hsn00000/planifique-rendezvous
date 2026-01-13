<?php

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    public function __construct(
        private UserPasswordHasherInterface $passwordHasher
    ) {}

    public function load(ObjectManager $manager): void
    {
        // 1. VOTRE COMPTE CONSEILLER (Celui qui bloquait !)
        $user = new User();
        // METTEZ VOTRE VRAI EMAIL MICROSOFT ICI 👇
        $user->setEmail('automate@planifique.com');
        $user->setFirstName('Moi');
        $user->setLastName('Conseiller');
        $user->setRoles(['ROLE_USER']); // Les conseillers ont un rôle normal
        $user->setPassword(null); // Pas besoin de mot de passe, Microsoft gère ça

        $manager->persist($user);

        // 2. VOTRE COMPTE ADMIN (Pour l'accès technique du bas)
        $admin = new User();
        $admin->setEmail('admin@planifique.com');
        $admin->setFirstName('Admin');
        $admin->setLastName('Technique');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($this->passwordHasher->hashPassword($admin, 'admin123'));

        $manager->persist($admin);

        $manager->flush();
    }
}

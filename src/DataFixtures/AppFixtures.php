<?php

namespace App\DataFixtures;

use App\Entity\User;
use App\Entity\MicrosoftAccount;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    private UserPasswordHasherInterface $passwordHasher;

    public function __construct(UserPasswordHasherInterface $passwordHasher)
    {
        $this->passwordHasher = $passwordHasher;
    }

    public function load(ObjectManager $manager): void
    {
        // 1. Création d'un utilisateur classique
        $user = new User();
        $user->setEmail('user@exemple.com');
        $user->setFirstName('Jean');
        $user->setLastName('Dupont');
        $user->setRoles(['ROLE_USER']);

        // Hachage du mot de passe
        $hashedPassword = $this->passwordHasher->hashPassword(
            $user,
            'password123'
        );
        $user->setPassword($hashedPassword);

        $manager->persist($user);

        // 2. Création d'un compte Microsoft lié (optionnel)
        $microsoftAccount = new MicrosoftAccount();
        $microsoftAccount->setUser($user);
        $microsoftAccount->setMicrosoftId('ms-id-123456');
        $microsoftAccount->setMicrosoftEmail('jean.dupont@outlook.com');
        // $microsoftAccount->setAccessToken('...'); // Si besoin

        $manager->persist($microsoftAccount);

        // 3. Création d'un administrateur
        $admin = new User();
        $admin->setEmail('admin@exemple.com');
        $admin->setFirstName('Admin');
        $admin->setLastName('System');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($this->passwordHasher->hashPassword($admin, 'admin123'));

        $manager->persist($admin);

        // Enregistrement en base de données
        $manager->flush();
    }
}

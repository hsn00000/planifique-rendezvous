<?php

namespace App\DataFixtures;

use App\Entity\Bureau;
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
        // --- 1. UTILISATEURS (Garde tes users actuels) ---
        // Je remets tes users par défaut pour que tu puisses te connecter

        $admin = new User();
        $admin->setEmail('admin@planifique.com');
        $admin->setFirstName('Admin');
        $admin->setLastName('Technique');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($this->passwordHasher->hashPassword($admin, 'admin123'));
        $manager->persist($admin);

        // --- 2. BUREAUX DE GENÈVE (Ta liste réelle) ---
        $bureauxGeneve = [
            'Genève - Bureau 1' => 'geneve.bureau1@maison-finance.ch',
            'Genève - Bureau 2' => 'geneve.bureau2@maison-finance.ch',
            'Genève - Bureau 3' => 'geneve.bureau3@maison-finance.ch',
            'Genève - Bureau 4' => 'geneve.bureau4@maison-finance.ch',
            'Genève - Bureau 5' => 'geneve.bureau5@maison-finance.ch',
            'Genève - Bureau 6' => 'geneve.bureau6@maison-finance.ch',
            'Genève - Bureau 7' => 'geneve.bureau7@maison-finance.ch',
            'Genève - Bureau 8' => 'geneve.bureau8@maison-finance.ch',
            'Genève - Bureau 9' => 'geneve.bureau9@maison-finance.ch',
            'Genève - Bureau 10' => 'geneve.bureau10@maison-finance.ch',
            'Genève - Salle de conférence' => 'geneve.salleconference@maison-finance.ch',
        ];

        foreach ($bureauxGeneve as $nom => $email) {
            $bureau = new Bureau();
            $bureau->setNom($nom);
            $bureau->setEmail($email);
            $bureau->setLieu('Cabinet-geneve'); // Important : Doit correspondre à ton formulaire
            $manager->persist($bureau);
        }

        // --- 3. BUREAUX D'ARCHAMPS (Exemple en attendant) ---
        $bureauxArchamps = [
            'Archamps - Bureau A' => 'archamps.bureauA@maison-finance.ch',
            'Archamps - Bureau B' => 'archamps.bureauB@maison-finance.ch',
        ];

        foreach ($bureauxArchamps as $nom => $email) {
            $bureau = new Bureau();
            $bureau->setNom($nom);
            $bureau->setEmail($email);
            $bureau->setLieu('Cabinet-archamps'); // Important
            $manager->persist($bureau);
        }

        $manager->flush();
    }
}

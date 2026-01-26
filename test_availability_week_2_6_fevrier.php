<?php

/**
 * Script de test pour vérifier la disponibilité des salles et conseillers
 * pour la semaine du 2-6 février 2026
 * 
 * Usage: php bin/console test_availability_week_2_6_fevrier.php
 * ou: php test_availability_week_2_6_fevrier.php
 */

require __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;

// Charger les variables d'environnement
$dotenv = new Dotenv();
$dotenv->loadEnv(__DIR__ . '/.env');

use Symfony\Component\HttpKernel\KernelInterface;
use App\Kernel;

// Initialiser le kernel Symfony
$kernel = new Kernel($_ENV['APP_ENV'] ?? 'dev', (bool) ($_ENV['APP_DEBUG'] ?? false));
$kernel->boot();
$container = $kernel->getContainer();

// Récupérer les services nécessaires
$entityManager = $container->get('doctrine.orm.entity_manager');
$outlookService = $container->get(App\Service\OutlookService::class);
$bureauRepo = $entityManager->getRepository(App\Entity\Bureau::class);
$userRepo = $entityManager->getRepository(App\Entity\User::class);
$rdvRepo = $entityManager->getRepository(App\Entity\RendezVous::class);

echo "═══════════════════════════════════════════════════════════════\n";
echo "  TEST DE DISPONIBILITÉ - Semaine du 2-6 février 2026\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// Dates de test (semaine du 2-6 février 2026)
$testDates = [
    '2026-02-02' => ['10:00', '11:00', '12:30', '14:00', '15:00'], // Lundi
    '2026-02-03' => ['10:00', '11:30', '13:00', '14:30'], // Mardi
    '2026-02-04' => ['09:30', '11:00', '11:30', '13:00', '14:00'], // Mercredi
    '2026-02-05' => ['09:30', '11:30', '12:30', '14:00', '15:00'], // Jeudi
    '2026-02-06' => ['09:30', '10:30', '11:30', '14:00', '15:00'], // Vendredi
];

// Récupérer tous les bureaux de Genève
$bureauxGeneve = $bureauRepo->findBy(['lieu' => 'Cabinet-geneve']);
echo "📋 Bureaux trouvés à Genève: " . count($bureauxGeneve) . "\n";
foreach ($bureauxGeneve as $bureau) {
    echo "   - {$bureau->getNom()} (Email: " . ($bureau->getEmail() ?: 'N/A') . ")\n";
}
echo "\n";

// Récupérer un conseiller pour les tests (le premier avec un compte Microsoft)
$conseillers = $userRepo->createQueryBuilder('u')
    ->innerJoin('u.microsoftAccount', 'm')
    ->where('u.email LIKE :email')
    ->setParameter('email', '%@planifique.com')
    ->setMaxResults(1)
    ->getQuery()
    ->getResult();

if (empty($conseillers)) {
    echo "❌ ERREUR: Aucun conseiller avec compte Microsoft trouvé\n";
    exit(1);
}

$testConseiller = $conseillers[0];
echo "👤 Conseiller de test: {$testConseiller->getFirstName()} {$testConseiller->getLastName()} ({$testConseiller->getEmail()})\n\n";

// Fonction pour tester la disponibilité d'un créneau
function testCreneau($date, $heure, $bureauxGeneve, $testConseiller, $outlookService, $bureauRepo, $rdvRepo) {
    $start = new \DateTime("$date $heure:00");
    $end = (clone $start)->modify('+60 minutes'); // Test avec 60 minutes
    
    echo "  🕐 Créneau: " . $start->format('d/m/Y H:i') . " - " . $end->format('H:i') . "\n";
    
    // 1. Vérifier les salles libres en BDD locale
    $freeBureauxBdd = $bureauRepo->findAvailableBureaux('Cabinet-geneve', $start, $end);
    echo "     📊 BDD locale: " . count($freeBureauxBdd) . " salle(s) libre(s)\n";
    
    if (empty($freeBureauxBdd)) {
        echo "     ⚠️  Aucune salle libre en BDD locale\n";
        return false;
    }
    
    // 2. Vérifier les salles libres côté Outlook
    $sallesLibresOutlook = [];
    foreach ($bureauxGeneve as $bureau) {
        if (!$bureau->getEmail()) {
            continue;
        }
        
        try {
            // Utiliser la méthode privée via réflexion ou créer une méthode publique
            // Pour simplifier, on utilise hasAtLeastOneFreeRoomOnOutlook
            $isFree = $outlookService->hasAtLeastOneFreeRoomOnOutlook(
                $testConseiller,
                [$bureau],
                $start,
                $end
            );
            
            if ($isFree) {
                $sallesLibresOutlook[] = $bureau;
            }
        } catch (\Exception $e) {
            echo "     ⚠️  Erreur vérification Outlook pour {$bureau->getNom()}: " . $e->getMessage() . "\n";
        }
    }
    
    echo "     📊 Outlook: " . count($sallesLibresOutlook) . " salle(s) libre(s)\n";
    
    // 3. Vérifier les conseillers disponibles
    // Récupérer tous les conseillers du groupe
    $groupe = $testConseiller->getGroupe();
    if ($groupe) {
        $tousLesConseillers = $groupe->getUsers()->toArray();
        try {
            $conseillerDisponible = $outlookService->hasAtLeastOneAvailableConseillerOnOutlook(
                $testConseiller,
                $tousLesConseillers,
                $start,
                $end
            );
            echo "     👥 Conseillers: " . ($conseillerDisponible ? "✅ Au moins un disponible" : "❌ Tous occupés") . "\n";
        } catch (\Exception $e) {
            echo "     ⚠️  Erreur vérification conseillers: " . $e->getMessage() . "\n";
        }
    }
    
    // Résultat final
    if (count($sallesLibresOutlook) > 0) {
        echo "     ✅ RÉSULTAT: Créneau DISPONIBLE (salle(s) libre(s): " . implode(', ', array_map(fn($b) => $b->getNom(), $sallesLibresOutlook)) . ")\n";
        return true;
    } else {
        echo "     ❌ RÉSULTAT: Créneau OCCUPÉ (toutes les salles sont réservées)\n";
        return false;
    }
}

// Exécuter les tests
$results = [];
foreach ($testDates as $date => $heures) {
    $dateObj = new \DateTime($date);
    echo "\n📅 " . $dateObj->format('l d/m/Y') . " (" . $dateObj->format('D') . ")\n";
    echo str_repeat('-', 60) . "\n";
    
    foreach ($heures as $heure) {
        $result = testCreneau($date, $heure, $bureauxGeneve, $testConseiller, $outlookService, $bureauRepo, $rdvRepo);
        $results[] = [
            'date' => $date,
            'heure' => $heure,
            'disponible' => $result
        ];
        echo "\n";
    }
}

// Résumé
echo "\n═══════════════════════════════════════════════════════════════\n";
echo "  RÉSUMÉ DES TESTS\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

$disponibles = array_filter($results, fn($r) => $r['disponible']);
$occupes = array_filter($results, fn($r) => !$r['disponible']);

echo "✅ Créneaux disponibles: " . count($disponibles) . "\n";
echo "❌ Créneaux occupés: " . count($occupes) . "\n";
echo "📊 Total testé: " . count($results) . "\n\n";

if (count($disponibles) > 0) {
    echo "Créneaux disponibles:\n";
    foreach ($disponibles as $result) {
        echo "  - {$result['date']} {$result['heure']}:00\n";
    }
}

echo "\n✅ Tests terminés!\n";

<?php

namespace App\Command;

use App\Entity\Bureau;
use App\Entity\User;
use App\Repository\BureauRepository;
use App\Repository\RendezVousRepository;
use App\Repository\UserRepository;
use App\Service\OutlookService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-availability-week',
    description: 'Teste la disponibilité des salles et conseillers pour la semaine du 2-6 février 2026'
)]
class TestAvailabilityWeekCommand extends Command
{
    public function __construct(
        private BureauRepository $bureauRepo,
        private UserRepository $userRepo,
        private RendezVousRepository $rdvRepo,
        private OutlookService $outlookService
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $io->title('Test de Disponibilité - Semaine du 2-6 février 2026');

        // Récupérer tous les bureaux de Genève
        $bureauxGeneve = $this->bureauRepo->findBy(['lieu' => 'Cabinet-geneve']);
        
        if (empty($bureauxGeneve)) {
            $io->error('Aucun bureau trouvé pour Cabinet-geneve');
            return Command::FAILURE;
        }

        $io->section('Bureaux trouvés à Genève');
        $io->table(
            ['Nom', 'Email'],
            array_map(fn(Bureau $b) => [$b->getNom(), $b->getEmail() ?: 'N/A'], $bureauxGeneve)
        );

        // Récupérer un conseiller pour les tests
        $conseillers = $this->userRepo->createQueryBuilder('u')
            ->innerJoin('u.microsoftAccount', 'm')
            ->where('u.email LIKE :email')
            ->setParameter('email', '%@planifique.com')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        if (empty($conseillers)) {
            $io->error('Aucun conseiller avec compte Microsoft trouvé');
            return Command::FAILURE;
        }

        $testConseiller = $conseillers[0];
        $io->section('Conseiller de test');
        $io->text([
            "Nom: {$testConseiller->getFirstName()} {$testConseiller->getLastName()}",
            "Email: {$testConseiller->getEmail()}"
        ]);

        // Dates de test
        $testDates = [
            '2026-02-02' => ['10:00', '11:00', '12:30', '14:00', '15:00'], // Lundi
            '2026-02-03' => ['10:00', '11:30', '13:00', '14:30'], // Mardi
            '2026-02-04' => ['09:30', '11:00', '11:30', '13:00', '14:00'], // Mercredi
            '2026-02-05' => ['09:30', '11:30', '12:30', '14:00', '15:00'], // Jeudi
            '2026-02-06' => ['09:30', '10:30', '11:30', '14:00', '15:00'], // Vendredi
        ];

        $results = [];
        $io->section('Tests de disponibilité');

        foreach ($testDates as $date => $heures) {
            $dateObj = new \DateTime($date);
            $io->text("<info>📅 {$dateObj->format('l d/m/Y')}</info>");

            foreach ($heures as $heure) {
                $result = $this->testCreneau(
                    $date,
                    $heure,
                    $bureauxGeneve,
                    $testConseiller,
                    $io
                );
                $results[] = [
                    'date' => $date,
                    'heure' => $heure,
                    'disponible' => $result['disponible'],
                    'salles_libres_bdd' => $result['salles_libres_bdd'],
                    'salles_libres_outlook' => $result['salles_libres_outlook'],
                    'conseiller_disponible' => $result['conseiller_disponible']
                ];
            }
        }

        // Résumé
        $io->section('Résumé des tests');
        
        $disponibles = array_filter($results, fn($r) => $r['disponible']);
        $occupes = array_filter($results, fn($r) => !$r['disponible']);

        $io->table(
            ['Statut', 'Nombre'],
            [
                ['✅ Disponibles', count($disponibles)],
                ['❌ Occupés', count($occupes)],
                ['📊 Total', count($results)]
            ]
        );

        if (count($disponibles) > 0) {
            $io->section('Créneaux disponibles');
            foreach ($disponibles as $result) {
                $io->text("  - {$result['date']} {$result['heure']}:00");
            }
        }

        $io->success('Tests terminés!');

        return Command::SUCCESS;
    }

    private function testCreneau(
        string $date,
        string $heure,
        array $bureauxGeneve,
        User $testConseiller,
        SymfonyStyle $io
    ): array {
        $start = new \DateTime("$date $heure:00");
        $end = (clone $start)->modify('+60 minutes');

        // 1. Vérifier les salles libres en BDD locale
        $freeBureauxBdd = $this->bureauRepo->findAvailableBureaux('Cabinet-geneve', $start, $end);
        $sallesLibresBdd = count($freeBureauxBdd);

        // 2. Vérifier les salles libres côté Outlook
        $sallesLibresOutlook = 0;
        $sallesLibresNoms = [];
        
        if (!empty($freeBureauxBdd)) {
            try {
                $hasFreeRoom = $this->outlookService->hasAtLeastOneFreeRoomOnOutlook(
                    $testConseiller,
                    $bureauxGeneve, // Vérifier toutes les salles, pas seulement celles libres en BDD
                    $start,
                    $end
                );
                
                if ($hasFreeRoom) {
                    // Compter les salles réellement libres
                    foreach ($bureauxGeneve as $bureau) {
                        if (!$bureau->getEmail()) {
                            continue;
                        }
                        
                        try {
                            $isFree = $this->outlookService->hasAtLeastOneFreeRoomOnOutlook(
                                $testConseiller,
                                [$bureau],
                                $start,
                                $end
                            );
                            
                            if ($isFree) {
                                $sallesLibresOutlook++;
                                $sallesLibresNoms[] = $bureau->getNom();
                            }
                        } catch (\Exception $e) {
                            // Ignorer les erreurs individuelles
                        }
                    }
                }
            } catch (\Exception $e) {
                $io->warning("Erreur vérification Outlook: " . $e->getMessage());
            }
        }

        // 3. Vérifier les conseillers disponibles
        $conseillerDisponible = false;
        $groupe = $testConseiller->getGroupe();
        if ($groupe) {
            $tousLesConseillers = $groupe->getUsers()->toArray();
            try {
                $conseillerDisponible = $this->outlookService->hasAtLeastOneAvailableConseillerOnOutlook(
                    $testConseiller,
                    $tousLesConseillers,
                    $start,
                    $end
                );
            } catch (\Exception $e) {
                // Ignorer les erreurs
            }
        }

        // Afficher le résultat
        $status = ($sallesLibresOutlook > 0 && $conseillerDisponible) ? '✅' : '❌';
        $io->text("  {$status} {$start->format('H:i')} - BDD: {$sallesLibresBdd} salle(s) | Outlook: {$sallesLibresOutlook} salle(s) | Conseiller: " . ($conseillerDisponible ? 'Disponible' : 'Occupé'));
        
        if ($sallesLibresOutlook > 0 && !empty($sallesLibresNoms)) {
            $io->text("     Salles libres: " . implode(', ', array_slice($sallesLibresNoms, 0, 3)) . (count($sallesLibresNoms) > 3 ? '...' : ''));
        }

        return [
            'disponible' => $sallesLibresOutlook > 0 && $conseillerDisponible,
            'salles_libres_bdd' => $sallesLibresBdd,
            'salles_libres_outlook' => $sallesLibresOutlook,
            'conseiller_disponible' => $conseillerDisponible
        ];
    }
}

<?php

namespace App\Service;

use App\Entity\RendezVous;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OutlookService
{
    public function __construct(
        private ClientRegistry $clientRegistry,
        private EntityManagerInterface $em,
        private HttpClientInterface $httpClient
    ) {}

    /**
     * Ajoute l'événement au calendrier Outlook et sauvegarde l'ID pour la synchro future.
     */
    public function addEventToCalendar(User $conseiller, RendezVous $rdv): void
    {
        // 1. Récupération du compte Microsoft
        $microsoftAccount = $conseiller->getMicrosoftAccount();
        if (!$microsoftAccount) return;

        // 2. Token valide
        $accessToken = $this->getValidAccessToken($microsoftAccount);
        if (!$accessToken) return;

        // 3. Préparation des données
        $eventData = [
            'subject' => 'RDV Client : ' . $rdv->getPrenom() . ' ' . $rdv->getNom(),
            'body' => [
                'contentType' => 'HTML',
                'content' => sprintf(
                    "<b>Client :</b> %s %s<br><b>Tel :</b> %s<br><b>Email :</b> %s<br><b>Lieu :</b> %s<br><b>Adresse :</b> %s",
                    $rdv->getPrenom(),
                    $rdv->getNom(),
                    $rdv->getTelephone(),
                    $rdv->getEmail(),
                    $rdv->getTypeLieu(),
                    $rdv->getAdresse() ?? 'Non précisée'
                )
            ],
            'start' => [
                'dateTime' => $rdv->getDateDebut()->format('Y-m-d\TH:i:s'),
                'timeZone' => 'Europe/Paris'
            ],
            'end' => [
                'dateTime' => $rdv->getDateFin()->format('Y-m-d\TH:i:s'),
                'timeZone' => 'Europe/Paris'
            ],
            'location' => [
                'displayName' => $rdv->getTypeLieu()
            ],
            'attendees' => [
                [
                    'emailAddress' => [
                        'address' => $rdv->getEmail(),
                        'name' => $rdv->getPrenom() . ' ' . $rdv->getNom()
                    ],
                    'type' => 'required'
                ]
            ],
            'allowNewTimeProposals' => false,
            'showAs' => 'busy'
        ];

        // 4. Envoi à l'API
        try {
            $response = $this->httpClient->request('POST', 'https://graph.microsoft.com/v1.0/me/events', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json'
                ],
                'json' => $eventData
            ]);

            // --- MODIFICATION ICI : ON SAUVEGARDE L'ID OUTLOOK ---
            if ($response->getStatusCode() === 201) {
                $data = $response->toArray();

                // On récupère l'ID unique généré par Microsoft
                if (isset($data['id'])) {
                    $rdv->setOutlookId($data['id']);
                    $this->em->flush(); // Mise à jour en base de données
                }
            }
            // -----------------------------------------------------

        } catch (\Exception $e) {
            // Log erreur
        }
    }

    /**
     * NOUVELLE MÉTHODE : Vérifie si les RDV locaux existent toujours dans Outlook.
     * Si un RDV a été supprimé dans Outlook, il sera supprimé de l'application.
     */
    public function synchronizeCalendar(User $conseiller): void
    {
        $microsoftAccount = $conseiller->getMicrosoftAccount();
        if (!$microsoftAccount) return;

        $accessToken = $this->getValidAccessToken($microsoftAccount);
        if (!$accessToken) return;

        // 1. On récupère les RDV locaux FUTURS qui sont liés à Outlook (ont un outlookId)
        $now = new \DateTime();
        $localRdvs = $this->em->getRepository(RendezVous::class)->createQueryBuilder('r')
            ->where('r.conseiller = :user')
            ->andWhere('r.dateDebut >= :now')
            ->andWhere('r.outlookId IS NOT NULL')
            ->setParameter('user', $conseiller)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();

        if (empty($localRdvs)) return;

        // 2. On récupère la liste des événements Outlook pour les 3 prochains mois
        $startStr = $now->format('Y-m-d\T00:00:00');
        $endStr = (clone $now)->modify('+3 months')->format('Y-m-d\T23:59:59');

        try {
            // On demande uniquement les IDs ($select=id) pour aller vite + pagination max ($top=500)
            $url = "https://graph.microsoft.com/v1.0/me/calendarView?startDateTime=$startStr&endDateTime=$endStr&\$select=id&\$top=500";

            $response = $this->httpClient->request('GET', $url, [
                'headers' => ['Authorization' => 'Bearer ' . $accessToken]
            ]);

            $data = $response->toArray();

            // On extrait tous les IDs existants chez Microsoft
            $outlookIds = array_map(fn($e) => $e['id'], $data['value']);

            // 3. Comparaison et Nettoyage
            $deletedCount = 0;
            foreach ($localRdvs as $rdv) {
                // Si l'ID du RDV local n'est PAS dans la liste reçue de Microsoft...
                if (!in_array($rdv->getOutlookId(), $outlookIds)) {
                    // ... c'est qu'il a été supprimé manuellement dans Outlook.
                    // On le supprime donc de notre base.
                    $this->em->remove($rdv);
                    $deletedCount++;
                }
            }

            if ($deletedCount > 0) {
                $this->em->flush();
            }

        } catch (\Exception $e) {
            // Si l'API échoue, on ne fait rien pour ne pas supprimer de RDV par erreur
        }
    }

    /**
     * Gestion du Refresh Token
     */
    private function getValidAccessToken($microsoftAccount): ?string
    {
        $token = $microsoftAccount->getAccessToken();
        $expiresAt = $microsoftAccount->getExpiresAt();

        // Conversion timestamp si nécessaire
        $expirationTimestamp = ($expiresAt instanceof \DateTimeInterface) ? $expiresAt->getTimestamp() : $expiresAt;

        // Marge de 5 minutes (300s)
        if ($expirationTimestamp && $expirationTimestamp > (time() + 300)) {
            return $token;
        }

        // Refresh
        $refreshToken = $microsoftAccount->getRefreshToken();
        if (!$refreshToken) {
            return null;
        }

        try {
            $oauthClient = $this->clientRegistry->getClient('azure');

            $newToken = $oauthClient->getOAuth2Provider()->getAccessToken('refresh_token', [
                'refresh_token' => $refreshToken
            ]);

            $microsoftAccount->setAccessToken($newToken->getToken());
            $microsoftAccount->setRefreshToken($newToken->getRefreshToken());
            $microsoftAccount->setExpiresAt($newToken->getExpires());

            $this->em->flush();

            return $newToken->getToken();

        } catch (IdentityProviderException $e) {
            return null;
        }
    }

    /**
     * Récupère les périodes occupées pour le calcul des dispos
     */
    public function getOutlookBusyPeriods(User $conseiller, \DateTimeInterface $date): array
    {
        $microsoftAccount = $conseiller->getMicrosoftAccount();
        if (!$microsoftAccount) return [];

        $accessToken = $this->getValidAccessToken($microsoftAccount);
        if (!$accessToken) return [];

        $startStr = $date->format('Y-m-d') . 'T00:00:00';
        $endStr = $date->format('Y-m-d') . 'T23:59:59';

        try {
            $response = $this->httpClient->request('GET', "https://graph.microsoft.com/v1.0/me/calendarView?startDateTime=$startStr&endDateTime=$endStr", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Prefer' => 'outlook.timezone="Europe/Paris"'
                ]
            ]);

            $data = $response->toArray();
            $busySlots = [];

            foreach ($data['value'] as $event) {
                $busySlots[] = [
                    'start' => new \DateTime($event['start']['dateTime']),
                    'end' => new \DateTime($event['end']['dateTime'])
                ];
            }

            return $busySlots;

        } catch (\Exception $e) {
            return [];
        }
    }
}

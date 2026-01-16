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
     * Adapté de la méthode Approve() de ton collègue
     */
    public function addEventToCalendar(User $conseiller, RendezVous $rdv): void
    {
        // 1. Récupération du compte Microsoft du conseiller
        $microsoftAccount = $conseiller->getMicrosoftAccount();

        // Sécurité : Si le conseiller n'a jamais connecté son compte, on arrête (comme le "return NotFound" en C#)
        if (!$microsoftAccount) {
            // Optionnel : Logger cette erreur ou envoyer une alerte admin
            return;
        }

        // 2. Obtention d'un Token valide (Refresh automatique si besoin)
        $accessToken = $this->getValidAccessToken($microsoftAccount);
        if (!$accessToken) {
            return; // Impossible d'agir sans token valide
        }

        // 3. Préparation des données pour Microsoft Graph
        // Correspond à "ComputeEventUtcRange" et la création de l'objet événement en C#
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
            // On force le fuseau horaire pour éviter les décalages (Europe/Paris)
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
            // On invite le client pour qu'il ait aussi l'event (optionnel mais pro)
            'attendees' => [
                [
                    'emailAddress' => [
                        'address' => $rdv->getEmail(),
                        'name' => $rdv->getPrenom() . ' ' . $rdv->getNom()
                    ],
                    'type' => 'required'
                ]
            ],
            'allowNewTimeProposals' => false, // Comme c'est un RDV ferme
            'showAs' => 'busy' // Marque le créneau comme "Occupé"
        ];

        // 4. Envoi à l'API (L'équivalent de _graph.CreateOutOfOfficeEventAsync)
        try {
            $response = $this->httpClient->request('POST', 'https://graph.microsoft.com/v1.0/me/events', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json'
                ],
                'json' => $eventData
            ]);

            // Si ça marche (Code 201 Created), on peut récupérer l'ID de l'event Outlook si on veut le stocker
            // $data = $response->toArray();
            // $outlookId = $data['id'];
            // $rdv->setOutlookId($outlookId); $this->em->flush();

        } catch (\Exception $e) {
            // Log l'erreur mais ne plante pas l'application pour le client
            // error_log("Erreur Outlook : " . $e->getMessage());
        }
    }

    /**
     * Méthode technique pour gérer le Token (Refresh Token)
     * C'est la mécanique invisible que la librairie C# gérait toute seule.
     */
    private function getValidAccessToken($microsoftAccount): ?string
    {
        $token = $microsoftAccount->getAccessToken();
        $expiresAt = $microsoftAccount->getExpiresAt(); // Timestamp ou DateTime

        // Conversion si nécessaire (selon ton entité, si c'est un timestamp int)
        $expirationTimestamp = ($expiresAt instanceof \DateTimeInterface) ? $expiresAt->getTimestamp() : $expiresAt;

        // Si le token est encore valide (avec une marge de 5 min), on l'utilise
        if ($expirationTimestamp && $expirationTimestamp > (time() + 300)) {
            return $token;
        }

        // Sinon, on utilise le Refresh Token pour en avoir un nouveau
        $refreshToken = $microsoftAccount->getRefreshToken();
        if (!$refreshToken) {
            return null; // Plus de refresh token, connexion manuelle requise
        }

        try {
            // Attention : 'azure' doit matcher le nom dans knpu_oauth2_client.yaml
            $oauthClient = $this->clientRegistry->getClient('azure');

            $newToken = $oauthClient->getOAuth2Provider()->getAccessToken('refresh_token', [
                'refresh_token' => $refreshToken
            ]);

            // Mise à jour en base
            $microsoftAccount->setAccessToken($newToken->getToken());
            $microsoftAccount->setRefreshToken($newToken->getRefreshToken());

            // CORRECTION ICI : On passe directement l'entier (timestamp)
            // car votre entité attend un ?int
            $microsoftAccount->setExpiresAt($newToken->getExpires());

            $this->em->flush();

            return $newToken->getToken();

        } catch (IdentityProviderException $e) {
            return null;
        }
    }

    public function getOutlookBusyPeriods(User $conseiller, \DateTimeInterface $date): array
    {
        $microsoftAccount = $conseiller->getMicrosoftAccount();
        if (!$microsoftAccount) return [];

        $accessToken = $this->getValidAccessToken($microsoftAccount);
        if (!$accessToken) return [];

        // On regarde la journée entière
        $startStr = $date->format('Y-m-d') . 'T00:00:00';
        $endStr = $date->format('Y-m-d') . 'T23:59:59';

        try {
            // On demande à Microsoft : "Donne-moi tous les événements de cette journée"
            // On force le TimeZone Europe/Paris pour être synchro
            $response = $this->httpClient->request('GET', "https://graph.microsoft.com/v1.0/me/calendarView?startDateTime=$startStr&endDateTime=$endStr", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Prefer' => 'outlook.timezone="Europe/Paris"'
                ]
            ]);

            $data = $response->toArray();
            $busySlots = [];

            foreach ($data['value'] as $event) {
                // On stocke les périodes occupées
                $busySlots[] = [
                    'start' => new \DateTime($event['start']['dateTime']),
                    'end' => new \DateTime($event['end']['dateTime'])
                ];
            }

            return $busySlots;

        } catch (\Exception $e) {
            return []; // En cas d'erreur, on considère que c'est libre (ou bloqué par sécurité, à toi de voir)
        }
    }
}

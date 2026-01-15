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
     * Ajoute l'événement directement dans le calendrier Outlook du Conseiller
     */
    public function addEventToCalendar(User $conseiller, RendezVous $rdv): void
    {
        $microsoftAccount = $conseiller->getMicrosoftAccount();

        // Si le conseiller n'a pas lié son compte Microsoft, on ne peut rien faire
        if (!$microsoftAccount) {
            return;
        }

        // 1. Vérifier et rafraîchir le token si nécessaire
        $accessToken = $this->getValidAccessToken($microsoftAccount);
        if (!$accessToken) {
            return; // Impossible d'obtenir un token valide
        }

        // 2. Préparer les données pour l'API Microsoft Graph
        $eventData = [
            'subject' => 'RDV Planifique : ' . $rdv->getEvenement()->getTitre(),
            'body' => [
                'contentType' => 'HTML',
                'content' => "Rendez-vous avec " . $rdv->getPrenom() . " " . $rdv->getNom() . "<br>Tel: " . $rdv->getTelephone()
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
            // On ajoute le client comme "invité" pour qu'il reçoive aussi l'invit Outlook (Optionnel)
            'attendees' => [
                [
                    'emailAddress' => [
                        'address' => $rdv->getEmail(),
                        'name' => $rdv->getPrenom() . ' ' . $rdv->getNom()
                    ],
                    'type' => 'required'
                ]
            ]
        ];

        // 3. Envoyer la requête à Microsoft
        try {
            $this->httpClient->request('POST', 'https://graph.microsoft.com/v1.0/me/events', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json'
                ],
                'json' => $eventData
            ]);
        } catch (\Exception $e) {
            // Tu pourrais logger l'erreur ici
        }
    }

    /**
     * Gère la magie du Refresh Token
     */
    private function getValidAccessToken($microsoftAccount): ?string
    {
        $token = $microsoftAccount->getAccessToken();
        $expiresAt = $microsoftAccount->getExpiresAt(); // Assure-toi d'avoir ce champ en base (timestamp)

        // Si le token est encore valide (avec une marge de 5 min), on l'utilise
        if ($expiresAt && $expiresAt > (time() + 300)) {
            return $token;
        }

        // Sinon, on essaie de le rafraîchir
        $refreshToken = $microsoftAccount->getRefreshToken();
        if (!$refreshToken) {
            return null; // Pas de refresh token, l'utilisateur doit se reconnecter manuellement
        }

        try {
            // On appelle le client OAuth pour rafraîchir
            $oauthClient = $this->clientRegistry->getClient('microsoft');
            $newToken = $oauthClient->getOAuth2Provider()->getAccessToken('refresh_token', [
                'refresh_token' => $refreshToken
            ]);

            // On sauvegarde le nouveau token en base
            $microsoftAccount->setAccessToken($newToken->getToken());
            $microsoftAccount->setRefreshToken($newToken->getRefreshToken());
            $microsoftAccount->setExpiresAt($newToken->getExpires());

            $this->em->flush();

            return $newToken->getToken();

        } catch (IdentityProviderException $e) {
            return null;
        }
    }
}

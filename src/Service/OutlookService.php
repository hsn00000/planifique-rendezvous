<?php

namespace App\Service;

use App\Entity\RendezVous;
use App\Entity\User;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OutlookService
{
    public function __construct(private HttpClientInterface $httpClient) {}

    public function addEventToCalendar(User $conseiller, RendezVous $rdv): bool
    {
        $msAccount = $conseiller->getMicrosoftAccount();
        if (!$msAccount || !$msAccount->getAccessToken()) {
            return false;
        }

        $token = $msAccount->getAccessToken();

        // Construction du body avec les VRAIS champs de ton entité
        $body = [
            'subject' => 'RDV : ' . $rdv->getEvenement()->getTitre(),
            'body' => [
                'contentType' => 'HTML',
                'content' => sprintf(
                    'Rendez-vous avec <b>%s %s</b><br>Email: %s<br>Téléphone: %s<br>Lieu: %s',
                    $rdv->getPrenom(),      // <-- getPrenom
                    $rdv->getNom(),         // <-- getNom
                    $rdv->getEmail(),       // <-- getEmail
                    $rdv->getTelephone(),   // <-- getTelephone
                    $rdv->getTypeLieu()
                )
            ],
            'start' => [
                'dateTime' => $rdv->getDateDebut()->format('Y-m-d\TH:i:s'),
                'timeZone' => 'Europe/Paris'
            ],
            'end' => [
                'dateTime' => $rdv->getDateDebut()
                    ->modify('+' . $rdv->getEvenement()->getDuree() . ' minutes')
                    ->format('Y-m-d\TH:i:s'),
                'timeZone' => 'Europe/Paris'
            ],
            'location' => [
                'displayName' => $rdv->getTypeLieu() // Ex: Visioconférence
            ]
        ];

        try {
            $response = $this->httpClient->request('POST', 'https://graph.microsoft.com/v1.0/me/events', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json'
                ],
                'json' => $body
            ]);

            return $response->getStatusCode() === 201;
        } catch (\Exception $e) {
            return false;
        }
    }
}

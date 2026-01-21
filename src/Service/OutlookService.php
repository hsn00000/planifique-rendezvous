<?php

namespace App\Service;

use App\Entity\RendezVous;
use App\Entity\User;
use App\Repository\MicrosoftAccountRepository;
use App\Repository\RendezVousRepository; // Ajout du repo
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Doctrine\ORM\EntityManagerInterface; // Nécessaire pour flush et remove

class OutlookService
{
    private $oauthProvider;
    private $accountRepo;
    private $rdvRepo; // Pour la synchro
    private $em;      // Pour la synchro
    private $router;

    public function __construct(
        MicrosoftAccountRepository $accountRepo,
        RendezVousRepository $rdvRepo,
        EntityManagerInterface $em,
        UrlGeneratorInterface $router,
        string $clientId,
        string $clientSecret,
        string $tenantId
    ) {
        $this->accountRepo = $accountRepo;
        $this->rdvRepo = $rdvRepo;
        $this->em = $em;
        $this->router = $router;

        $this->oauthProvider = new GenericProvider([
            'clientId'                => $clientId,
            'clientSecret'            => $clientSecret,
            'redirectUri'             => $this->router->generate('connect_microsoft_check', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'urlAuthorize'            => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
            'urlAccessToken'          => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            'urlResourceOwnerDetails' => '',
            'scopes'                  => 'openid profile offline_access User.Read Calendars.ReadWrite'
        ]);
    }

    public function addEventToCalendar(User $user, RendezVous $rendezVous): void
    {
        $account = $this->accountRepo->findOneBy(['user' => $user]);
        if (!$account || !$account->getAccessToken()) return;

        $this->refreshAccessTokenIfExpired($account);

        // --- Construction des participants (Client + Salle) ---
        $attendees = [];

        // 1. Le Client
        $attendees[] = [
            'emailAddress' => ['address' => $rendezVous->getEmail(), 'name' => $rendezVous->getPrenom() . ' ' . $rendezVous->getNom()],
            'type' => 'required'
        ];

        // 2. La Salle (si elle a un email)
        if ($rendezVous->getBureau() && $rendezVous->getBureau()->getEmail()) {
            $attendees[] = [
                'emailAddress' => [
                    'address' => $rendezVous->getBureau()->getEmail(),
                    'name' => $rendezVous->getBureau()->getNom()
                ],
                'type' => 'resource'
            ];
        }

        $eventData = [
            'subject' => 'RDV: ' . $rendezVous->getEvenement()->getTitre() . ' - ' . $rendezVous->getPrenom() . ' ' . $rendezVous->getNom(),
            'body' => [
                'contentType' => 'HTML',
                'content' => "Rendez-vous planifié via Planifique.<br>Client: {$rendezVous->getTelephone()}"
            ],
            'start' => [
                'dateTime' => $rendezVous->getDateDebut()->format('Y-m-d\TH:i:s'),
                'timeZone' => 'Europe/Paris'
            ],
            'end' => [
                'dateTime' => $rendezVous->getDateFin()->format('Y-m-d\TH:i:s'),
                'timeZone' => 'Europe/Paris'
            ],
            'location' => [
                'displayName' => $rendezVous->getTypeLieu() . ($rendezVous->getBureau() ? ' - ' . $rendezVous->getBureau()->getNom() : '')
            ],
            'attendees' => $attendees
        ];

        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->post('https://graph.microsoft.com/v1.0/me/events', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $account->getAccessToken(),
                    'Content-Type' => 'application/json'
                ],
                'json' => $eventData
            ]);

            // --- NOUVEAU : Sauvegarde de l'ID Outlook ---
            $responseData = json_decode($response->getBody(), true);
            if (isset($responseData['id'])) {
                $rendezVous->setOutlookId($responseData['id']);
                // On sauvegarde immédiatement pour ne pas perdre le lien
                $this->em->persist($rendezVous);
                $this->em->flush();
            }

        } catch (\Exception $e) {
            // Loguer l'erreur
        }
    }

    /**
     * Synchronise le calendrier : Supprime les RDV locaux si absents d'Outlook
     */
    public function synchronizeCalendar(User $user): void
    {
        $account = $this->accountRepo->findOneBy(['user' => $user]);
        if (!$account) return;

        $this->refreshAccessTokenIfExpired($account);

        // 1. Récupérer les RDV futurs de ce user qui ont un ID Outlook
        $futureRdvs = $this->rdvRepo->createQueryBuilder('r')
            ->where('r.conseiller = :user')
            ->andWhere('r.dateDebut > :now')
            ->andWhere('r.outlookId IS NOT NULL')
            ->setParameter('user', $user)
            ->setParameter('now', new \DateTime())
            ->getQuery()->getResult();

        if (empty($futureRdvs)) return;

        // 2. Récupérer la liste des IDs Outlook actuels (pour les 3 prochains mois)
        $startStr = (new \DateTime())->format('Y-m-d\TH:i:s');
        $endStr = (new \DateTime('+3 months'))->format('Y-m-d\TH:i:s');

        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->get('https://graph.microsoft.com/v1.0/me/calendarView', [
                'headers' => ['Authorization' => 'Bearer ' . $account->getAccessToken()],
                'query' => [
                    'startDateTime' => $startStr,
                    'endDateTime' => $endStr,
                    '$select' => 'id', // On veut juste les IDs pour comparer
                    '$top' => 999 // Pagination large
                ]
            ]);

            $data = json_decode($response->getBody(), true);
            $outlookIds = array_column($data['value'] ?? [], 'id');

            // 3. Comparaison : Si un RDV local n'est pas dans la liste Outlook -> Suppression
            foreach ($futureRdvs as $rdv) {
                if (!in_array($rdv->getOutlookId(), $outlookIds)) {
                    // Le RDV a été supprimé dans Outlook, on le supprime de Planifique
                    $this->em->remove($rdv);
                }
            }
            $this->em->flush();

        } catch (\Exception $e) {}
    }

    public function getOutlookBusyPeriods(User $user, \DateTime $date): array
    {
        $account = $this->accountRepo->findOneBy(['user' => $user]);
        if (!$account) return [];

        $this->refreshAccessTokenIfExpired($account);

        $startDateTime = (clone $date)->setTime(0, 0, 0)->format('Y-m-d\TH:i:s');
        $endDateTime = (clone $date)->setTime(23, 59, 59)->format('Y-m-d\TH:i:s');

        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->get('https://graph.microsoft.com/v1.0/me/calendarView', [
                'headers' => ['Authorization' => 'Bearer ' . $account->getAccessToken()],
                'query' => [
                    'startDateTime' => $startDateTime,
                    'endDateTime' => $endDateTime,
                    '$select' => 'start,end'
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $busySlots = [];

            foreach ($data['value'] ?? [] as $event) {
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

    private function refreshAccessTokenIfExpired($account): void
    {
        // On récupère la date d'expiration
        $expiresAt = $account->getExpiresAt();

        // Si c'est null, on force le refresh par sécurité
        if (!$expiresAt) {
            // Logique de refresh...
        }

        // On s'assure d'avoir un timestamp pour comparer
        $expiryTimestamp = ($expiresAt instanceof \DateTimeInterface) ? $expiresAt->getTimestamp() : (int) $expiresAt;
        $nowTimestamp = time();

        // On ajoute une marge de sécurité de 5 minutes (300s) pour ne pas être pris de court
        if ($expiryTimestamp < ($nowTimestamp + 300)) {
            try {
                $newAccessToken = $this->oauthProvider->getAccessToken('refresh_token', [
                    'refresh_token' => $account->getRefreshToken()
                ]);

                // ... reste du code (mise à jour du token) ...

                // Pensez à mettre à jour l'entité avec le nouveau token !
                $account->setAccessToken($newAccessToken->getToken());
                $account->setRefreshToken($newAccessToken->getRefreshToken());

                // Si la librairie retourne un timestamp, on le convertit en DateTime pour Doctrine
                if ($newAccessToken->getExpires()) {
                    $dt = new \DateTime();
                    $dt->setTimestamp($newAccessToken->getExpires());
                    $account->setExpiresAt($dt);
                }

                $this->entityManager->flush();

            } catch (\Exception $e) {
                // Gérer l'erreur ou la logger
            }
        }
    }
}

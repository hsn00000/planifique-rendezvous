<?php

namespace App\Service;

use App\Entity\RendezVous;
use App\Entity\User;
use App\Repository\MicrosoftAccountRepository;
use App\Repository\RendezVousRepository;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Doctrine\ORM\EntityManagerInterface;

class OutlookService
{
    private $oauthProvider;
    private $accountRepo;
    private $rdvRepo;
    private $em;
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

    /**
     * Crée l'événement Outlook pour le conseiller + ressource, sans inviter le client
     */
    public function addEventToCalendar(User $user, RendezVous $rendezVous): void
    {
        $account = $this->accountRepo->findOneBy(['user' => $user]);
        if (!$account || !$account->getAccessToken()) {
            return;
        }

        $this->refreshAccessTokenIfExpired($account);

        // Participants : uniquement la ressource (salle) si email
        $attendees = [];
        if ($rendezVous->getBureau() && $rendezVous->getBureau()->getEmail()) {
            $attendees[] = [
                'emailAddress' => [
                    'address' => $rendezVous->getBureau()->getEmail(),
                    'name'    => $rendezVous->getBureau()->getNom(), // visible côté conseiller
                ],
                'type' => 'resource',
            ];
        }

        // Lieu + adresse (sans nom de bureau interne dans displayName)
        $lieuTexte = $rendezVous->getTypeLieu();
        $adresseTexte = $rendezVous->getAdresse() ?: '';
        if (strcasecmp($lieuTexte, 'Cabinet-geneve') === 0) {
            $adresseTexte = 'Chemin du Pavillon 2, 1218 Le Grand-Saconnex';
        } elseif (strcasecmp($lieuTexte, 'Cabinet-archamps') === 0) {
            $adresseTexte = '160 Rue Georges de Mestral, 74160 Archamps, France';
        }
        $lieuComplet = $lieuTexte . ($adresseTexte ? ' - ' . $adresseTexte : '');
        $bureauNom   = $rendezVous->getBureau() ? $rendezVous->getBureau()->getNom() : 'N/A';

        $eventData = [
            'subject' => 'RDV: ' . $rendezVous->getEvenement()->getTitre() . ' - ' .
                $rendezVous->getPrenom() . ' ' . $rendezVous->getNom(),

            'hideAttendees' => true,

            'body' => [
                'contentType' => 'HTML',
                'content' => sprintf(
                    '<div style="font-family:Segoe UI,Arial,sans-serif;">
                        <h2 style="margin:0 0 12px 0;color:#0b4db7;">Rendez-vous confirmé</h2>
                        <p style="margin:0 0 12px 0;">Bonjour %s %s,</p>
                        <p style="margin:0 0 12px 0;">Récapitulatif (interne) :</p>
                        <table style="border-collapse:collapse;width:100%%;max-width:520px;">
                            <tr><td style="padding:6px 0;color:#666;">Type</td><td style="padding:6px 0;font-weight:600;color:#111;text-align:right;">%s</td></tr>
                            <tr><td style="padding:6px 0;color:#666;">Date</td><td style="padding:6px 0;font-weight:600;color:#111;text-align:right;">%s</td></tr>
                            <tr><td style="padding:6px 0;color:#666;">Horaire</td><td style="padding:6px 0;font-weight:600;color:#111;text-align:right;">%s - %s</td></tr>
                            <tr><td style="padding:6px 0;color:#666;">Conseiller</td><td style="padding:6px 0;font-weight:600;color:#111;text-align:right;">%s %s</td></tr>
                            <tr><td style="padding:6px 0;color:#666;">Lieu</td><td style="padding:6px 0;font-weight:600;color:#111;text-align:right;">%s</td></tr>
                            <tr><td style="padding:6px 0;color:#666;">Bureau</td><td style="padding:6px 0;font-weight:600;color:#111;text-align:right;">%s</td></tr>
                        </table>
                    </div>',
                    $rendezVous->getPrenom(),
                    $rendezVous->getNom(),
                    $rendezVous->getEvenement()->getTitre(),
                    $rendezVous->getDateDebut()->format('d/m/Y'),
                    $rendezVous->getDateDebut()->format('H:i'),
                    $rendezVous->getDateFin()->format('H:i'),
                    $rendezVous->getConseiller()->getFirstName(),
                    $rendezVous->getConseiller()->getLastName(),
                    $lieuComplet,
                    $bureauNom
                ),
            ],

            'start' => [
                'dateTime' => $rendezVous->getDateDebut()->format('Y-m-d\TH:i:s'),
                'timeZone' => 'Europe/Paris',
            ],
            'end' => [
                'dateTime' => $rendezVous->getDateFin()->format('Y-m-d\TH:i:s'),
                'timeZone' => 'Europe/Paris',
            ],
            'location' => [
                'displayName' => $lieuComplet,
            ],
            'attendees' => $attendees,
        ];

        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->post('https://graph.microsoft.com/v1.0/me/events', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $account->getAccessToken(),
                    'Content-Type'  => 'application/json',
                ],
                'json' => $eventData,
            ]);

            $responseData = json_decode($response->getBody(), true);
            if (isset($responseData['id'])) {
                $rendezVous->setOutlookId($responseData['id']);
                $this->em->persist($rendezVous);
                $this->em->flush();
            }
        } catch (\Exception $e) {
            // TODO: logger l'erreur si besoin
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

        $futureRdvs = $this->rdvRepo->createQueryBuilder('r')
            ->where('r.conseiller = :user')
            ->andWhere('r.dateDebut > :now')
            ->andWhere('r.outlookId IS NOT NULL')
            ->setParameter('user', $user)
            ->setParameter('now', new \DateTime())
            ->getQuery()->getResult();

        if (empty($futureRdvs)) return;

        $startStr = (new \DateTime())->format('Y-m-d\TH:i:s');
        $endStr = (new \DateTime('+3 months'))->format('Y-m-d\TH:i:s');

        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->get('https://graph.microsoft.com/v1.0/me/calendarView', [
                'headers' => ['Authorization' => 'Bearer ' . $account->getAccessToken()],
                'query' => [
                    'startDateTime' => $startStr,
                    'endDateTime' => $endStr,
                    '$select' => 'id',
                    '$top' => 999
                ]
            ]);

            $data = json_decode($response->getBody(), true);
            $outlookIds = array_column($data['value'] ?? [], 'id');

            foreach ($futureRdvs as $rdv) {
                if (!in_array($rdv->getOutlookId(), $outlookIds)) {
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

    /**
     * Vérifie si une salle (room) est libre côté Outlook/Exchange pour un créneau donné
     */
    private function isRoomFreeOnOutlook(string $accessToken, string $roomEmail, \DateTimeInterface $start, \DateTimeInterface $end): bool
    {
        try {
            $client = new \GuzzleHttp\Client();

            $payload = [
                'schedules' => [$roomEmail],
                'startTime' => [
                    'dateTime' => $start->format('Y-m-d\TH:i:s'),
                    'timeZone' => 'Europe/Paris',
                ],
                'endTime' => [
                    'dateTime' => $end->format('Y-m-d\TH:i:s'),
                    'timeZone' => 'Europe/Paris',
                ],
                'availabilityViewInterval' => 30, // résolution 30 minutes
            ];

            $response = $client->post('https://graph.microsoft.com/v1.0/me/calendar/getSchedule', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type'  => 'application/json',
                ],
                'json' => $payload,
                'timeout' => 2
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $schedule = $data['value'][0] ?? null;

            if (!$schedule) {
                // Si Outlook ne renvoie rien, on considère comme non-fiable => on bloque (évite faux positifs)
                return false;
            }

            // availabilityView : string de 0/1/2/3/4
            // 0 = Free, 1 = Tentative, 2 = Busy, 3 = OOF, 4 = WorkingElsewhere
            // Si un seul créneau n'est pas free (0) => pas libre
            $view = $schedule['availabilityView'] ?? '';
            if (!is_string($view) || $view === '') {
                return false;
            }

            // Si un seul caractère n'est pas '0', la salle est occupée
            return !preg_match('/[1-9]/', $view);

        } catch (\Exception $e) {
            // Si erreur Graph, on considère comme non-libre (sécurité)
            return false;
        }
    }

    /**
     * Choisit la première salle vraiment libre côté Outlook parmi une liste de bureaux libres en BDD
     */
    public function pickAvailableBureauOnOutlook(User $user, array $bureaux, \DateTimeInterface $start, \DateTimeInterface $end): ?\App\Entity\Bureau
    {
        $account = $this->accountRepo->findOneBy(['user' => $user]);
        if (!$account || !$account->getAccessToken()) {
            return null;
        }

        $this->refreshAccessTokenIfExpired($account);
        $token = $account->getAccessToken();

        // On teste chaque bureau pour trouver le premier vraiment libre côté Outlook
        foreach ($bureaux as $bureau) {
            if (!$bureau->getEmail()) {
                // Une salle sans email ne peut pas être vérifiée ni réservée côté Outlook
                continue;
            }

            try {
                if ($this->isRoomFreeOnOutlook($token, $bureau->getEmail(), $start, $end)) {
                    // Cette salle est libre côté Outlook → on la prend
                    return $bureau;
                }
            } catch (\Exception $e) {
                // Si erreur Graph, on skip cette salle et on continue avec la suivante
                continue;
            }
        }

        // Aucune salle libre trouvée
        return null;
    }

    private function refreshAccessTokenIfExpired($account): void
    {
        $expiresAt = $account->getExpiresAt();
        $expiryTimestamp = (int) $expiresAt;
        $nowTimestamp = time();

        if ($expiryTimestamp < ($nowTimestamp + 300)) {
            try {
                $newAccessToken = $this->oauthProvider->getAccessToken('refresh_token', [
                    'refresh_token' => $account->getRefreshToken()
                ]);

                $account->setAccessToken($newAccessToken->getToken());
                $account->setRefreshToken($newAccessToken->getRefreshToken());

                if ($newAccessToken->getExpires()) {
                    $account->setExpiresAt($newAccessToken->getExpires());
                }

                $this->em->flush();

            } catch (\Exception $e) {
                // Optionnel: log
            }
        }
    }

    /**
     * Vérifie rapidement si au moins une salle est libre côté Outlook (optimisé avec batch API)
     * Retourne true si au moins une salle est libre, false sinon
     */
    public function hasAtLeastOneFreeRoomOnOutlook(User $user, array $bureaux, \DateTimeInterface $start, \DateTimeInterface $end): bool
    {
        $account = $this->accountRepo->findOneBy(['user' => $user]);
        if (!$account || !$account->getAccessToken()) {
            // Si pas de token, on considère comme non-disponible (sécurité)
            return false;
        }

        $this->refreshAccessTokenIfExpired($account);
        $token = $account->getAccessToken();

        // Filtrer les bureaux avec email
        $bureauxWithEmail = array_filter($bureaux, fn($b) => !empty($b->getEmail()));

        // Si aucun bureau n'a d'email, on considère comme disponible
        if (empty($bureauxWithEmail)) {
            return true;
        }

        // OPTIMISATION : Utiliser l'API batch pour vérifier toutes les salles en une seule requête
        try {
            $roomEmails = array_map(fn($b) => $b->getEmail(), $bureauxWithEmail);

            $client = new \GuzzleHttp\Client();
            $payload = [
                'schedules' => $roomEmails,
                'startTime' => [
                    'dateTime' => $start->format('Y-m-d\TH:i:s'),
                    'timeZone' => 'Europe/Paris',
                ],
                'endTime' => [
                    'dateTime' => $end->format('Y-m-d\TH:i:s'),
                    'timeZone' => 'Europe/Paris',
                ],
                'availabilityViewInterval' => 30,
            ];

            $response = $client->post('https://graph.microsoft.com/v1.0/me/calendar/getSchedule', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                ],
                'json' => $payload,
                'timeout' => 3, // Timeout de 3 secondes
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $schedules = $data['value'] ?? [];

            // Vérifier si au moins une salle est libre
            foreach ($schedules as $schedule) {
                $view = $schedule['availabilityView'] ?? '';
                if (is_string($view) && $view !== '' && !preg_match('/[1-9]/', $view)) {
                    // Cette salle est libre (tous les créneaux sont '0')
                    return true;
                }
            }

            // Aucune salle libre trouvée
            return false;

        } catch (\Exception $e) {
            // En cas d'erreur, on retourne false pour sécurité
            return false;
        }
    }
}

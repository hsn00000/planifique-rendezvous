<?php

namespace App\Security;

use App\Entity\User;
use App\Entity\Groupe;
use App\Entity\MicrosoftAccount;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use JetBrains\PhpStorm\NoReturn;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use TheNetworg\OAuth2\Client\Provider\AzureResourceOwner;

class MicrosoftAuthenticator extends OAuth2Authenticator
{
    private ClientRegistry $clientRegistry;
    private EntityManagerInterface $entityManager;
    private RouterInterface $router;
    private UserRepository $userRepository;

    public function __construct(
        ClientRegistry $clientRegistry,
        EntityManagerInterface $entityManager,
        RouterInterface $router,
        UserRepository $userRepository
    ) {
        $this->clientRegistry = $clientRegistry;
        $this->entityManager = $entityManager;
        $this->router = $router;
        $this->userRepository = $userRepository;
    }

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === 'connect_microsoft_check';
    }

    public function authenticate(Request $request): Passport
    {
        $client = $this->clientRegistry->getClient('azure');
        $accessToken = $this->fetchAccessToken($client);

        // 1. Récupération des infos Microsoft AVANT de créer le Badge
        // (Pour corriger l'erreur "Username too long")
        /** @var AzureResourceOwner $microsoftUser */
        $microsoftUser = $client->fetchUserFromToken($accessToken);

        // 2. Récupération de l'Email (Mail ou UPN)
        $email = $microsoftUser->getUpn();
        if (empty($email) && method_exists($microsoftUser, 'getMail')) {
            $email = $microsoftUser->getMail();
        }
        if (empty($email)) {
            $userData = $microsoftUser->toArray();
            $email = $userData['email'] ?? $userData['userPrincipalName'] ?? null;
        }

        if (empty($email)) {
            throw new CustomUserMessageAuthenticationException('Email introuvable dans le compte Microsoft.');
        }

        // 3. Création du passeport
        return new SelfValidatingPassport(
            new UserBadge($email, function () use ($email, $microsoftUser, $accessToken, $client) {

                // Recherche de l'utilisateur
                $user = $this->userRepository->findOneBy(['email' => $email]);

                // Création si inexistant
                if (!$user) {
                    $user = new User();
                    $user->setEmail($email);
                    $this->entityManager->persist($user);
                }

                // --- TA DEMANDE SPECIFIQUE ---
                // Si Microsoft ne donne pas de nom (cas Admin), on met "Turgay Demirtas".
                // Sinon, on prend le vrai nom de la personne.
                $user->setFirstName($microsoftUser->getFirstName() ?? 'Turgay');
                $user->setLastName($microsoftUser->getLastName() ?? 'Demirtas');

                // --- RETOUR DE LA LOGIQUE GROUPE (SUPERBE !) ---
                try {
                    // On appelle l'API Graph pour avoir le département
                    $provider = $client->getOAuth2Provider();
                    $requestGraph = $provider->getAuthenticatedRequest(
                        'GET',
                        'https://graph.microsoft.com/v1.0/me?$select=department',
                        $accessToken
                    );
                    $response = $provider->getResponse($requestGraph);
                    $data = json_decode($response->getBody()->getContents(), true);

                    $departmentName = $data['department'] ?? null;

                    if ($departmentName) {
                        // On cherche si le groupe existe déjà
                        $groupe = $this->entityManager->getRepository(Groupe::class)->findOneBy(['nom' => $departmentName]);

                        // S'il n'existe pas, on le crée automatiquement
                        if (!$groupe) {
                            $groupe = new Groupe();
                            $groupe->setNom($departmentName);
                            // Petit nettoyage pour le slug (Immobilier & Co -> immobilier-co)
                            $groupe->setSlug(strtolower(str_replace([' ', '&'], '-', $departmentName)));
                            $groupe->setMicrosoftIdGroupe('AUTO_' . strtoupper(substr(md5($departmentName), 0, 10)));

                            $this->entityManager->persist($groupe);
                            $this->entityManager->flush(); // Important pour avoir l'ID tout de suite
                        }

                        // On assigne l'utilisateur au groupe
                        $user->setGroupe($groupe);
                    }
                } catch (\Exception $e) {
                    // Si ça rate (pas de département, erreur réseau), on ne bloque pas la connexion.
                    // L'utilisateur pourra se connecter sans groupe.
                }

                // --- Mise à jour des Tokens Microsoft ---
                $microsoftAccount = $user->getMicrosoftAccount();
                if (!$microsoftAccount) {
                    $microsoftAccount = new MicrosoftAccount();
                    $microsoftAccount->setUser($user);
                }

                $microsoftAccount->setMicrosoftId($microsoftUser->getId());
                $microsoftAccount->setMicrosoftEmail($email);
                $microsoftAccount->setAccessToken($accessToken->getToken());
                $microsoftAccount->setRefreshToken($accessToken->getRefreshToken());

                if ($accessToken->getExpires()) {
                    $microsoftAccount->setExpiresAt($accessToken->getExpires());
                }

                $this->entityManager->persist($microsoftAccount);
                $this->entityManager->flush();

                return $user;
            })
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Plus aucune logique de token ici, on redirige simplement !
        return new RedirectResponse($this->router->generate('app_home'));
    }
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        // REMISE AU PROPRE : Plus de dd(), on redirige vers le login avec l'erreur
        if ($request->hasSession()) {
            $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);
        }

        return new RedirectResponse($this->router->generate('app_login'));
    }
}

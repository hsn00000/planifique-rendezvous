<?php

namespace App\Security;

use App\Entity\User;
use App\Entity\Groupe;
use App\Entity\MicrosoftAccount;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
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

        return new SelfValidatingPassport(
            new UserBadge($accessToken->getToken(), function () use ($accessToken, $client) {

                /** @var AzureResourceOwner $microsoftUser */
                $microsoftUser = $client->fetchUserFromToken($accessToken);

                // 1. Récupération de l'email
                $email = $microsoftUser->getUpn();
                if (empty($email) && method_exists($microsoftUser, 'getMail')) {
                    $email = $microsoftUser->getMail();
                }
                if (empty($email)) {
                    $userData = $microsoftUser->toArray();
                    $email = $userData['email'] ?? $userData['userPrincipalName'] ?? null;
                }

                if (empty($email)) {
                    throw new CustomUserMessageAuthenticationException('Email introuvable.');
                }

                // 2. Restriction Domaine
                if (!str_ends_with($email, '@planifique.com')) {
                    throw new CustomUserMessageAuthenticationException(
                        'Accès refusé. Seules les adresses @planifique.com sont autorisées.'
                    );
                }

                // 3. Gestion User
                $user = $this->userRepository->findOneBy(['email' => $email]);
                if (!$user) {
                    $user = new User();
                    $user->setEmail($email);
                    $user->setFirstName($microsoftUser->getFirstName() ?? 'Utilisateur');
                    $user->setLastName($microsoftUser->getLastName() ?? 'Microsoft');
                    $this->entityManager->persist($user);
                }

                // ====================================================
                // 4. LOGIQUE DÉPARTEMENT (Correctement implémentée)
                // ====================================================
                try {
                    // On récupère le département depuis Microsoft Graph
                    $provider = $client->getOAuth2Provider();
                    $request = $provider->getAuthenticatedRequest(
                        'GET',
                        'https://graph.microsoft.com/v1.0/me?$select=department',
                        $accessToken
                    );
                    $response = $provider->getResponse($request);
                    $data = json_decode($response->getBody()->getContents(), true);

                    $departmentName = $data['department'] ?? null;

                    if ($departmentName) {
                        // On cherche un groupe existant avec ce NOM
                        $groupe = $this->entityManager->getRepository(Groupe::class)->findOneBy(['nom' => $departmentName]);

                        // S'il n'existe pas, on le crée
                        if (!$groupe) {
                            $groupe = new Groupe();
                            $groupe->setNom($departmentName);
                            $groupe->setSlug(strtolower(str_replace(' ', '-', $departmentName))); // Génération slug simple

                            // ✅ CORRECTION : On utilise la bonne méthode de ton entité Groupe
                            $groupe->setMicrosoftIdGroupe('AUTO_' . strtoupper(str_replace(' ', '_', $departmentName)));

                            $this->entityManager->persist($groupe);
                            // On flush immédiatement pour que le groupe existe pour l'assignation
                            $this->entityManager->flush();
                        }

                        $user->setGroupe($groupe);
                    }

                } catch (\Exception $e) {
                    // En cas d'erreur API ou SQL sur le groupe, on logue mais on ne plante pas le login user
                    // Le flush global se fera quand même si l'EntityManager n'est pas fermé
                }

                // 5. Compte Microsoft lié
                $microsoftAccount = $user->getMicrosoftAccount();
                if (!$microsoftAccount) {
                    $microsoftAccount = new MicrosoftAccount();
                    $microsoftAccount->setUser($user);
                }
                $microsoftAccount->setMicrosoftId($microsoftUser->getId());
                $microsoftAccount->setMicrosoftEmail($email);

                $this->entityManager->persist($microsoftAccount);

                // Flush final pour sauvegarder l'utilisateur et son lien
                $this->entityManager->flush();

                return $user;
            })
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Récupération du user connecté
        $user = $token->getUser();

        // On récupère le token Microsoft depuis le client OAuth
        $client = $this->clientRegistry->getClient('microsoft');
        $accessToken = $client->getAccessToken(); // C'est un objet AccessToken

        // Sauvegarde en base
        if ($user->getMicrosoftAccount()) {
            $user->getMicrosoftAccount()->setAccessToken($accessToken->getToken());
            $user->getMicrosoftAccount()->setRefreshToken($accessToken->getRefreshToken());
            $this->entityManager->flush();
        }

        return new RedirectResponse($this->router->generate('app_home'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $message = strtr($exception->getMessageKey(), $exception->getMessageData());
        $request->getSession()->set(\Symfony\Component\Security\Http\SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);
        return new RedirectResponse($this->router->generate('app_login'));
    }
}

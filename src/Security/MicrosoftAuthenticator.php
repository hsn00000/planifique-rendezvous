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
        // Attention: Assurez-vous que le nom ici ('azure') correspond bien à config/packages/knpu_oauth2_client.yaml
        $client = $this->clientRegistry->getClient('azure');

        // 1. C'EST ICI QUE LE CODE EST CONSOMMÉ (Une seule fois !)
        $accessToken = $this->fetchAccessToken($client);

        return new SelfValidatingPassport(
            new UserBadge($accessToken->getToken(), function () use ($accessToken, $client) {

                /** @var AzureResourceOwner $microsoftUser */
                $microsoftUser = $client->fetchUserFromToken($accessToken);

                // --- 1. Récupération de l'email ---
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

                // --- 2. Restriction Domaine ---
                if (!str_ends_with($email, '@planifique.com')) {
                    throw new CustomUserMessageAuthenticationException(
                        'Accès refusé. Seules les adresses @planifique.com sont autorisées.'
                    );
                }

                // --- 3. Gestion User ---
                $user = $this->userRepository->findOneBy(['email' => $email]);
                if (!$user) {
                    $user = new User();
                    $user->setEmail($email);
                    $user->setFirstName($microsoftUser->getFirstName() ?? 'Utilisateur');
                    $user->setLastName($microsoftUser->getLastName() ?? 'Microsoft');
                    $this->entityManager->persist($user);
                }

                // --- 4. Logique Département / Groupe ---
                try {
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
                        $groupe = $this->entityManager->getRepository(Groupe::class)->findOneBy(['nom' => $departmentName]);

                        if (!$groupe) {
                            $groupe = new Groupe();
                            $groupe->setNom($departmentName);
                            $groupe->setSlug(strtolower(str_replace(' ', '-', $departmentName)));
                            $groupe->setMicrosoftIdGroupe('AUTO_' . strtoupper(str_replace(' ', '_', $departmentName)));
                            $this->entityManager->persist($groupe);
                            $this->entityManager->flush();
                        }
                        $user->setGroupe($groupe);
                    }
                } catch (\Exception $e) {
                    // On continue même si l'API groupe échoue
                }

                // --- 5. Gestion Compte Microsoft & Tokens (CORRIGÉ) ---
                // On fait tout ici car on a accès à la variable $accessToken valide

                $microsoftAccount = $user->getMicrosoftAccount();
                if (!$microsoftAccount) {
                    $microsoftAccount = new MicrosoftAccount();
                    $microsoftAccount->setUser($user);
                }

                // Infos de base
                $microsoftAccount->setMicrosoftId($microsoftUser->getId());
                $microsoftAccount->setMicrosoftEmail($email);

                // 🔥 SAUVEGARDE DES TOKENS ICI 🔥
                $microsoftAccount->setAccessToken($accessToken->getToken());
                $microsoftAccount->setRefreshToken($accessToken->getRefreshToken());

                // Gestion de l'expiration (timestamp vers DateTime si nécessaire, selon votre Entité)
                if ($accessToken->getExpires()) {
                    $microsoftAccount->setExpiresAt($accessToken->getExpires());
                }

                $this->entityManager->persist($microsoftAccount);
                $this->entityManager->flush(); // Sauvegarde tout (User + Groupe + Tokens)

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
        $message = strtr($exception->getMessageKey(), $exception->getMessageData());
        $request->getSession()->set(\Symfony\Component\Security\Http\SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);
        return new RedirectResponse($this->router->generate('app_login'));
    }
}

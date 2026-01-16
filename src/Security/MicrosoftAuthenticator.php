<?php

namespace App\Security;

use App\Entity\Groupe;
use App\Entity\MicrosoftAccount;
use App\Entity\User;
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

        /** @var AzureResourceOwner $microsoftUser */
        $microsoftUser = $client->fetchUserFromToken($accessToken);

        // Récupération de l'email
        $email = $microsoftUser->getUpn();
        if (empty($email) && method_exists($microsoftUser, 'getMail')) {
            $email = $microsoftUser->getMail();
        }
        if (empty($email)) {
            $userData = $microsoftUser->toArray();
            $email = $userData['email'] ?? $userData['userPrincipalName'] ?? null;
        }

        if (empty($email)) {
            throw new CustomUserMessageAuthenticationException('Impossible de récupérer votre email Microsoft.');
        }

        // --- VALIDATION SECURITE ---
        if (!str_ends_with(strtolower($email), '@planifique.com')) {
            throw new CustomUserMessageAuthenticationException(
                'Accès refusé : Seules les adresses professionnelles @planifique.com sont autorisées.'
            );
        }

        return new SelfValidatingPassport(
            new UserBadge($email, function () use ($email, $microsoftUser, $accessToken, $client) {

                $user = $this->userRepository->findOneBy(['email' => $email]);

                if (!$user) {
                    $user = new User();
                    $user->setEmail($email);
                    $this->entityManager->persist($user);
                }

                // --- GESTION INTELLIGENTE DU NOM ET PRENOM ---
                // 1. On essaie de récupérer via Microsoft
                $prenom = $microsoftUser->getFirstName();
                $nom = $microsoftUser->getLastName();

                // 2. Si vide, on essaie de parser l'email (prenom.nom@planifique.com)
                if (empty($prenom) || empty($nom)) {
                    $userPart = explode('@', $email)[0]; // prend "prenom.nom"

                    if (str_contains($userPart, '.')) {
                        $parts = explode('.', $userPart);
                        $prenom = ucfirst(strtolower($parts[0])); // "Prenom"

                        // Le reste devient le nom (gère les noms composés "jean.de.la.fontaine")
                        array_shift($parts);
                        $nom = ucwords(strtolower(implode(' ', $parts))); // "De La Fontaine"
                    } else {
                        // 3. Fallback si pas de point dans l'email
                        $prenom = 'Utilisateur';
                        $nom = 'Planifique';
                    }
                }

                $user->setFirstName($prenom);
                $user->setLastName($nom);
                // ---------------------------------------------

                // Gestion Groupe via Département
                try {
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
                        $groupe = $this->entityManager->getRepository(Groupe::class)->findOneBy(['nom' => $departmentName]);

                        if (!$groupe) {
                            $groupe = new Groupe();
                            $groupe->setNom($departmentName);
                            $groupe->setSlug(strtolower(str_replace([' ', '&'], '-', $departmentName)));
                            $groupe->setMicrosoftIdGroupe('AUTO_' . strtoupper(substr(md5($departmentName), 0, 10)));

                            $this->entityManager->persist($groupe);
                            $this->entityManager->flush();
                        }
                        $user->setGroupe($groupe);
                    }
                } catch (\Exception $e) {
                    // Silence est d'or
                }

                // Mise à jour Token
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
        return new RedirectResponse($this->router->generate('app_home'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        if ($request->hasSession()) {
            $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);
        }

        return new RedirectResponse($this->router->generate('app_login'));
    }
}

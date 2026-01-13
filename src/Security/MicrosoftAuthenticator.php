<?php

namespace App\Security;

use App\Entity\User;
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
        $clientRegistry->getClient('azure');
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

                // --- CORRECTION DE LA RÉCUPÉRATION DE L'EMAIL ---

                // 1. Essai via UPN (Standard Pro)
                $email = $microsoftUser->getUpn();

                // 2. Si vide, essai via Mail (Parfois utilisé)
                if (empty($email) && method_exists($microsoftUser, 'getMail')) {
                    $email = $microsoftUser->getMail();
                }

                // 3. Si toujours vide, on regarde les données brutes (Standard Perso)
                if (empty($email)) {
                    $userData = $microsoftUser->toArray();
                    $email = $userData['email'] ?? $userData['userPrincipalName'] ?? null;
                }

                // --- FIN CORRECTION ---

                if (empty($email)) {
                    throw new CustomUserMessageAuthenticationException(
                        'Impossible de récupérer votre adresse email depuis Microsoft. Merci de contacter le support.'
                    );
                }

                // On cherche l'utilisateur dans notre base
                $user = $this->userRepository->findOneBy(['email' => $email]);

                if (!$user) {
                    throw new CustomUserMessageAuthenticationException(
                        sprintf('Aucun compte trouvé pour l\'email "%s". Contactez l\'administrateur.', $email)
                    );
                }

                // Mise à jour ou création du lien MicrosoftAccount
                $microsoftAccount = $user->getMicrosoftAccount();
                if (!$microsoftAccount) {
                    $microsoftAccount = new MicrosoftAccount();
                    $microsoftAccount->setUser($user);
                }

                $microsoftAccount->setMicrosoftId($microsoftUser->getId());
                $microsoftAccount->setMicrosoftEmail($email);
                $microsoftAccount->setAccessToken($accessToken->getToken());
                $microsoftAccount->setRefreshToken($accessToken->getRefreshToken());
                $microsoftAccount->setExpiresAt($accessToken->getExpires());

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
        $message = strtr($exception->getMessageKey(), $exception->getMessageData());
        $request->getSession()->set(\Symfony\Component\Security\Http\SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);

        return new RedirectResponse($this->router->generate('app_login'));
    }
}

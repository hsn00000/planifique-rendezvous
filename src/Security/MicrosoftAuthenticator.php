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
        $clientRegistry->getClient('azure'); // Vérifie que le client existe
        $this->clientRegistry = $clientRegistry;
        $this->entityManager = $entityManager;
        $this->router = $router;
        $this->userRepository = $userRepository;
    }

    // Définit quand cet authenticator se déclenche (sur la route /check)
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

                $email = $microsoftUser->getUpn(); // Souvent l'email principal dans Azure AD

                // 1. On cherche l'utilisateur dans notre base
                $user = $this->userRepository->findOneBy(['email' => $email]);

                if (!$user) {
                    // Refus si l'utilisateur n'est pas déjà créé par un admin
                    throw new CustomUserMessageAuthenticationException(
                        sprintf('Aucun compte trouvé pour l\'email "%s". Contactez l\'administrateur.', $email)
                    );
                }

                // 2. On met à jour ou on crée le lien MicrosoftAccount
                $microsoftAccount = $user->getMicrosoftAccount();
                if (!$microsoftAccount) {
                    $microsoftAccount = new MicrosoftAccount();
                    $microsoftAccount->setUser($user);
                }

                // 3. On sauvegarde les nouveaux tokens
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
        // Succès : on redirige vers le tableau de bord
        return new RedirectResponse($this->router->generate('app_home'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        // Échec : on renvoie au login avec un message d'erreur
        $message = strtr($exception->getMessageKey(), $exception->getMessageData());
        $request->getSession()->set(\Symfony\Component\Security\Http\SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);

        return new RedirectResponse($this->router->generate('app_login'));
    }
}

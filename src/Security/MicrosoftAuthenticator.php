<?php

namespace App\Security;

use App\Entity\User;
use App\Entity\Groupe;
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

class MicrosoftAuthenticator extends OAuth2Authenticator
{
    private $clientRegistry;
    private $entityManager;
    private $router;

    public function __construct(ClientRegistry $clientRegistry, EntityManagerInterface $entityManager, RouterInterface $router)
    {
        $this->clientRegistry = $clientRegistry;
        $this->entityManager = $entityManager;
        $this->router = $router;
    }

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === 'connect_microsoft_check';
    }

    public function authenticate(Request $request): Passport
    {
        // On utilise le client 'azure' configuré dans knpu_oauth2_client.yaml
        $client = $this->clientRegistry->getClient('azure');
        $accessToken = $this->fetchAccessToken($client);

        return new SelfValidatingPassport(
            new UserBadge($accessToken->getToken(), function() use ($accessToken, $client) {

                /** @var \TheNetworg\OAuth2\Client\Provider\AzureResourceOwner $microsoftUser */
                $microsoftUser = $client->fetchUserFromToken($accessToken);

                // --- 🛠 CORRECTION DE L'ERREUR "undefined method getEmail" 🛠 ---

                // 1. On essaie d'abord le "User Principal Name" (Standard Azure)
                $email = $microsoftUser->getUpn();

                // 2. Si c'est vide, on cherche dans les données brutes (mail ou email)
                if (!$email) {
                    $data = $microsoftUser->toArray();
                    $email = $data['mail'] ?? $data['email'] ?? null;
                }

                // Si vraiment on ne trouve rien, on bloque (sécurité)
                if (!$email) {
                    throw new CustomUserMessageAuthenticationException('Impossible de récupérer l\'adresse email depuis Microsoft.');
                }
                // ------------------------------------------------------------------

                // 🔒 RESTRICTION DOMAINE
                if (!str_ends_with($email, '@planifique.com')) {
                    throw new CustomUserMessageAuthenticationException(
                        'Accès refusé. Seules les adresses professionnelles @planifique.com sont autorisées.'
                    );
                }

                // Recherche ou Création du User
                $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

                if (!$user) {
                    $user = new User();
                    $user->setEmail($email);
                    $user->setFirstName($microsoftUser->getFirstName() ?? 'Utilisateur');
                    $user->setLastName($microsoftUser->getLastName() ?? 'Microsoft');
                    $user->setPassword(''); // Pas de mot de passe requis
                    $this->entityManager->persist($user);
                }

                // 🔄 SYNCHRONISATION DES GROUPES
                try {
                    $provider = $client->getOAuth2Provider();
                    // Requête API Graph pour avoir les groupes
                    $request = $provider->getAuthenticatedRequest(
                        'GET',
                        'https://graph.microsoft.com/v1.0/me/transitiveMemberOf?$select=id,displayName',
                        $accessToken
                    );

                    $response = $provider->getResponse($request);
                    $data = json_decode($response->getBody()->getContents(), true);
                    $microsoftGroups = $data['value'] ?? [];

                    $groupeTrouve = null;
                    foreach ($microsoftGroups as $msGroup) {
                        // On cherche si l'ID du groupe Microsoft existe dans notre table Groupe
                        $groupeLocal = $this->entityManager->getRepository(Groupe::class)->findOneBy(['microsoftId' => $msGroup['id']]);

                        if ($groupeLocal) {
                            $groupeTrouve = $groupeLocal;
                            break;
                        }
                    }

                    if ($groupeTrouve) {
                        $user->setGroupe($groupeTrouve);
                    }

                } catch (\Exception $e) {
                    // On ne fait rien si la synchro échoue, pour ne pas bloquer le login
                }

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
        // On affiche l'erreur à l'utilisateur (ex: email non autorisé)
        $request->getSession()->set(\Symfony\Component\Security\Http\SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);

        return new RedirectResponse($this->router->generate('app_login'));
    }
}

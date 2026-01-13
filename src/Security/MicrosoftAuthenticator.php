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
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException; // Important pour le message d'erreur
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
        // ✅ CORRECTION ICI : On utilise bien 'azure' comme dans ton fichier YAML
        $client = $this->clientRegistry->getClient('azure');
        $accessToken = $this->fetchAccessToken($client);

        return new SelfValidatingPassport(
            new UserBadge($accessToken->getToken(), function() use ($accessToken, $client) {

                $microsoftUser = $client->fetchUserFromToken($accessToken);
                $email = $microsoftUser->getUpn() ?? $microsoftUser->getEmail();

                // ====================================================
                // 🔒 RESTRICTION DE DOMAINE (@planifique.com)
                // ====================================================
                if (!str_ends_with($email, '@planifique.com')) {
                    // Si l'email n'est pas bon, on stoppe tout ici.
                    throw new CustomUserMessageAuthenticationException(
                        'Accès refusé. Seules les adresses professionnelles @planifique.com sont autorisées.'
                    );
                }

                // 1. Trouver ou créer l'utilisateur
                $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

                if (!$user) {
                    $user = new User();
                    $user->setEmail($email);
                    $user->setFirstName($microsoftUser->getFirstName());
                    $user->setLastName($microsoftUser->getLastName());
                    $this->entityManager->persist($user);
                }

                // ====================================================
                // 🔄 SYNCHRONISATION DES GROUPES
                // ====================================================
                try {
                    $provider = $client->getOAuth2Provider();
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
                        // On compare l'ID Azure avec le microsoftId de nos Groupes en base
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
                    // On ne bloque pas la connexion si la synchro groupe échoue
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
        return new Response($message, Response::HTTP_FORBIDDEN);
    }
}

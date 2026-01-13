<?php

namespace App\Controller;

use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

class MicrosoftAuthController extends AbstractController
{
    // Etape 1 : Le bouton "Se connecter avec Microsoft" pointe ici
    #[Route('/connect/microsoft', name: 'connect_microsoft_start')]
    public function connectAction(ClientRegistry $clientRegistry): RedirectResponse
    {
        // "azure" est la clé définie dans knpu_oauth2_client.yaml
        return $clientRegistry
            ->getClient('azure')
            ->redirect(
            // Les permissions demandées à Microsoft
                ['openid', 'profile', 'email', 'offline_access', 'https://graph.microsoft.com/Calendars.ReadWrite'],
                []
            );
    }

    // Etape 2 : Microsoft renvoie l'utilisateur ici après succès
    #[Route('/connect/microsoft/check', name: 'connect_microsoft_check')]
    public function connectCheckAction()
    {
        // ON LAISSE VIDE ! C'est l'Authenticator qui va intercepter cette route.
    }
}

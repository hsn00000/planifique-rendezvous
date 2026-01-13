<?php

namespace App\Controller;

use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

class MicrosoftAuthController extends AbstractController
{
    // C'est ici que j'ai corrigé le nom de la route : 'connect_microsoft_start'
    #[Route('/connect/microsoft', name: 'connect_microsoft_start')]
    public function connectAction(ClientRegistry $clientRegistry): RedirectResponse
    {
        // On récupère le client "microsoft" défini dans config/packages/knpu_oauth2_client.yaml
        $client = $clientRegistry->getClient('azure');

        // On redirige vers Microsoft avec les permissions nécessaires
        // 'GroupMember.Read.All' est CRUCIAL pour la synchronisation des groupes
        return $client->redirect([
            'openid',
            'profile',
            'email',
            'offline_access',
            'GroupMember.Read.All'
        ]);
    }

    #[Route('/connect/microsoft/check', name: 'connect_microsoft_check')]
    public function connectCheckAction(): void
    {
        // Cette méthode reste vide car l'Authenticator intercepte la requête avant.
    }
}

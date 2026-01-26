<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class AppExtension extends AbstractExtension
{
    public function __construct(
        private readonly string $projectDir
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('image_to_base64', [$this, 'imageToBase64']),
        ];
    }

    /**
     * Convertit une image en base64 pour l'intégration dans les emails
     * Fonctionne en production et en développement, indépendamment de l'URL du serveur
     */
    public function imageToBase64(string $imagePath): string
    {
        // Nettoyer le chemin
        $imagePath = ltrim($imagePath, '/');
        
        // Essayer plusieurs chemins possibles
        $possiblePaths = [
            $this->projectDir . '/public/' . $imagePath,
            $this->projectDir . '/' . $imagePath,
            __DIR__ . '/../../public/' . $imagePath,
        ];
        
        $fullPath = null;
        foreach ($possiblePaths as $path) {
            if (file_exists($path) && is_readable($path)) {
                $fullPath = $path;
                break;
            }
        }
        
        if (!$fullPath || !file_exists($fullPath) || !is_readable($fullPath)) {
            error_log("[AppExtension] Logo non trouvé ou non lisible. Chemins testés: " . implode(', ', $possiblePaths));
            return '';
        }

        try {
            $imageData = @file_get_contents($fullPath);
            if ($imageData === false || empty($imageData)) {
                error_log("[AppExtension] Impossible de lire le logo: " . $fullPath);
                return '';
            }
            
            $imageInfo = @getimagesize($fullPath);
            if ($imageInfo === false) {
                // Si getimagesize échoue, on assume PNG
                $mimeType = 'image/png';
            } else {
                $mimeType = $imageInfo['mime'] ?? 'image/png';
            }
            
            $base64 = base64_encode($imageData);
            $result = 'data:' . $mimeType . ';base64,' . $base64;
            
            // Vérifier que le base64 n'est pas vide
            if (strlen($base64) < 100) {
                error_log("[AppExtension] Base64 du logo trop court, possible erreur");
                return '';
            }
            
            return $result;
        } catch (\Exception $e) {
            error_log("[AppExtension] Erreur lors de la conversion du logo: " . $e->getMessage());
            return '';
        }
    }
}

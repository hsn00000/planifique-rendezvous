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
     */
    public function imageToBase64(string $imagePath): string
    {
        $fullPath = $this->projectDir . '/public/' . ltrim($imagePath, '/');
        
        if (!file_exists($fullPath)) {
            return '';
        }

        $imageData = file_get_contents($fullPath);
        $imageInfo = getimagesize($fullPath);
        $mimeType = $imageInfo['mime'] ?? 'image/png';
        
        return 'data:' . $mimeType . ';base64,' . base64_encode($imageData);
    }
}

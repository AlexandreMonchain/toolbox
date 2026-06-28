<?php

namespace App\Security;

/**
 * Fournit un nonce CSP unique par requête, partagé entre le rendu Twig
 * (<script nonce="...">) et l'en-tête Content-Security-Policy.
 *
 * Le service est partagé : sur Apache/PHP-FPM (un process = une requête),
 * la mémoïsation garantit un nonce unique et cohérent pour toute la requête.
 */
class CspNonceProvider
{
    private ?string $nonce = null;

    public function getNonce(): string
    {
        return $this->nonce ??= bin2hex(random_bytes(16));
    }
}

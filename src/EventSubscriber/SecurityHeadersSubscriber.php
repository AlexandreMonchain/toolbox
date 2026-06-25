<?php

namespace App\EventSubscriber;

use App\Security\CspNonceProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly CspNonceProvider $nonceProvider) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onKernelResponse'];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        $headers  = $response->headers;
        $nonce    = $this->nonceProvider->getNonce();

        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        // HSTS uniquement sur une connexion HTTPS effective (ignoré sinon par les navigateurs,
        // mais on évite de l'émettre en clair en dev/HTTP).
        if ($event->getRequest()->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // CSP stricte : plus de 'unsafe-inline' sur les scripts — chaque <script> porte le nonce.
        $headers->set('Content-Security-Policy',
            "default-src 'self'; " .
            "base-uri 'self'; " .
            "form-action 'self'; " .
            "frame-ancestors 'none'; " .
            "object-src 'none'; " .
            "script-src 'self' 'nonce-{$nonce}'; " .
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; " .
            "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; " .
            "img-src 'self' data:; " .
            "connect-src 'self' https://passphrase.fr;"
        );
    }
}

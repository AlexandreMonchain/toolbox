<?php

namespace App\Twig;

use App\Security\CspNonceProvider;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class CspExtension extends AbstractExtension
{
    public function __construct(private readonly CspNonceProvider $nonceProvider) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('csp_nonce', [$this->nonceProvider, 'getNonce']),
        ];
    }
}

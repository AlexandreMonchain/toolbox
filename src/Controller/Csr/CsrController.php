<?php

namespace App\Controller\Csr;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/csr', name: 'csr_')]
class CsrController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('csr/index.html.twig');
    }

    #[Route('/generate', name: 'generate', methods: ['POST'])]
    public function generate(
        Request $request,
        #[\Symfony\Component\DependencyInjection\Attribute\Target('csrGenerateLimiter')] RateLimiterFactory $limiter,
    ): JsonResponse {
        $limit = $limiter->create($request->getClientIp())->consume();
        if (!$limit->isAccepted()) {
            return $this->json(['error' => 'Trop de requêtes. Réessayez dans une minute.'], 429);
        }

        $token = $request->headers->get('X-CSRF-Token', '');
        if (!$this->isCsrfTokenValid('csr_generate', $token)) {
            return $this->json(['error' => 'Token CSRF invalide.'], 403);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Données invalides.'], 400);
        }

        $cn    = trim($data['cn']    ?? '');
        $o     = trim($data['o']     ?? '');
        $ou    = trim($data['ou']    ?? '');
        $l     = trim($data['l']     ?? '');
        $st    = trim($data['st']    ?? '');
        $c     = strtoupper(trim($data['c'] ?? ''));
        $email = trim($data['email'] ?? '');
        $sans  = array_values(array_filter(
            array_map('trim', (array) ($data['sans'] ?? [])),
            static fn(string $s): bool => $s !== ''
        ));

        if (!$cn || !$o || !$l || !$st || !$c) {
            return $this->json(['error' => 'Champs obligatoires manquants.'], 422);
        }
        if (strlen($c) !== 2 || !ctype_alpha($c)) {
            return $this->json(['error' => 'Le pays doit être un code ISO à 2 lettres (ex : FR).'], 422);
        }

        $hostnameRe = '/^(\*\.)?[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*$/';
        if (!preg_match($hostnameRe, $cn)) {
            return $this->json(['error' => 'Common Name invalide.'], 422);
        }
        foreach ($sans as $san) {
            if (!preg_match($hostnameRe, $san)) {
                return $this->json(['error' => sprintf('Nom DNS invalide : %s', $san)], 422);
            }
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'Adresse email invalide.'], 422);
        }

        if (empty($sans)) {
            $sans = [$cn];
        }

        $dn = [
            'countryName'         => $c,
            'stateOrProvinceName' => $st,
            'localityName'        => $l,
            'organizationName'    => $o,
            'commonName'          => $cn,
        ];
        if ($ou !== '')    $dn['organizationalUnitName'] = $ou;
        if ($email !== '') $dn['emailAddress']           = $email;

        $sanLines = implode("\n", array_map(
            static fn(string $dns, int $i): string => sprintf('DNS.%d = %s', $i, $dns),
            $sans,
            range(1, count($sans))
        ));

        $configContent =
            "[req]\n"
            . "default_bits = 2048\n"
            . "prompt = no\n"
            . "default_md = sha256\n"
            . "req_extensions = req_ext\n"
            . "distinguished_name = dn\n\n"
            . "[dn]\n\n"
            . "[req_ext]\n"
            . "subjectAltName = @alt_names\n\n"
            . "[alt_names]\n"
            . $sanLines . "\n";

        $configFile = tempnam(sys_get_temp_dir(), 'csr_');
        if ($configFile === false) {
            return $this->json(['error' => 'Erreur système.'], 500);
        }
        file_put_contents($configFile, $configContent);

        try {
            $privateKey = openssl_pkey_new([
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
                'config'           => $configFile,
            ]);
            if ($privateKey === false) {
                return $this->json(['error' => 'Erreur lors de la génération de la clé privée.'], 500);
            }

            $csr = openssl_csr_new($dn, $privateKey, [
                'digest_alg' => 'sha256',
                'config'     => $configFile,
            ]);
            if ($csr === false) {
                return $this->json(['error' => 'Erreur lors de la génération du CSR.'], 500);
            }

            if (!openssl_csr_export($csr, $csrPem)) {
                return $this->json(['error' => 'Erreur lors de l\'export du CSR.'], 500);
            }
            if (!openssl_pkey_export($privateKey, $keyPem, null, ['config' => $configFile])) {
                return $this->json(['error' => 'Erreur lors de l\'export de la clé privée.'], 500);
            }
        } finally {
            @unlink($configFile);
        }

        $response = $this->json(['csr' => $csrPem, 'key' => $keyPem]);
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');

        return $response;
    }
}

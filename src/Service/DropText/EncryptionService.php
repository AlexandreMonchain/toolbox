<?php

namespace App\Service\DropText;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

class EncryptionService
{
    private const CIPHER    = 'aes-256-gcm';
    private const NONCE_LEN = 12;
    private const TAG_LEN   = 16;

    private string $key;

    public function __construct(
        #[Autowire(env: 'APP_ENCRYPTION_KEY')]
        string $hexKey,
    ) {
        if (strlen($hexKey) !== 64 || !ctype_xdigit($hexKey)) {
            throw new \InvalidArgumentException(
                'APP_ENCRYPTION_KEY doit être une chaîne hexadécimale de 64 caractères. '
                . 'Générez-en une avec : php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"'
            );
        }
        $this->key = hex2bin($hexKey);
    }

    public function encrypt(string $plaintext): array
    {
        $nonce = random_bytes(self::NONCE_LEN);
        $tag   = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            self::TAG_LEN
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Échec du chiffrement AES-256-GCM.');
        }

        return [
            'payload' => base64_encode($ciphertext . $tag),
            'nonce'   => base64_encode($nonce),
        ];
    }

    public function decrypt(string $payload, string $nonce): string
    {
        $raw        = base64_decode($payload);
        $ciphertext = substr($raw, 0, -self::TAG_LEN);
        $tag        = substr($raw, -self::TAG_LEN);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            base64_decode($nonce),
            $tag
        );

        if ($plaintext === false) {
            throw new \RuntimeException('Échec du déchiffrement — clé invalide ou données corrompues.');
        }

        return $plaintext;
    }
}

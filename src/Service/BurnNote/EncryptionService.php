<?php

namespace App\Service\BurnNote;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

class EncryptionService
{
    private const CIPHER    = 'aes-256-gcm';
    private const NONCE_LEN = 12;
    private const TAG_LEN   = 16;

    private string $masterKey;

    /** @var string[] Anciennes clés pour la rotation */
    private array $previousKeys;

    public function __construct(
        #[Autowire(env: 'APP_ENCRYPTION_KEY')]
        string $masterKey,
        #[Autowire(env: 'default::APP_ENCRYPTION_KEY_PREVIOUS')]
        ?string $previousKeys = null,
    ) {
        $this->masterKey    = $this->decodeKey($masterKey);
        $this->previousKeys = array_filter(
            array_map(
                fn(string $k) => $this->decodeKey(trim($k)),
                $previousKeys ? explode(',', $previousKeys) : [],
            )
        );
    }

    public function encrypt(string $plaintext): array
    {
        $nonce = random_bytes(self::NONCE_LEN);
        $tag   = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->masterKey,
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
        $nonce      = base64_decode($nonce);
        $raw        = base64_decode($payload);
        $ciphertext = substr($raw, 0, -self::TAG_LEN);
        $tag        = substr($raw, -self::TAG_LEN);

        // Essai avec la clé courante
        $plaintext = $this->tryDecrypt($ciphertext, $nonce, $tag, $this->masterKey);

        // Rotation : essai avec les anciennes clés si la courante échoue
        if ($plaintext === false) {
            foreach ($this->previousKeys as $oldKey) {
                $plaintext = $this->tryDecrypt($ciphertext, $nonce, $tag, $oldKey);
                if ($plaintext !== false) {
                    break;
                }
            }
        }

        if ($plaintext === false) {
            throw new \RuntimeException('Échec du déchiffrement — clé invalide ou données corrompues.');
        }

        return $plaintext;
    }

    private function tryDecrypt(string $ciphertext, string $nonce, string $tag, string $key): string|false
    {
        return openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );
    }

    private function decodeKey(string $hexKey): string
    {
        if (strlen($hexKey) !== 64 || !ctype_xdigit($hexKey)) {
            throw new \InvalidArgumentException(
                'APP_ENCRYPTION_KEY doit être une chaîne hexadécimale de 64 caractères (32 octets). '
                . 'Générez-en une avec : php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"'
            );
        }

        return hex2bin($hexKey);
    }
}

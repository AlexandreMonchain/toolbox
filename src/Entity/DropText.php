<?php

namespace App\Entity;

use App\Repository\DropTextRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: DropTextRepository::class)]
class DropText
{
    public const MAX_CONTENT_BYTES  = 524288; // 512 Ko
    public const MAX_PASSPHRASE_LEN = 128;

    public const TTL_OPTIONS = [
        3600   => '1 heure',
        86400  => '24 heures',
        604800 => '7 jours',
    ];

    public const MAX_READS_OPTIONS = [
        0  => 'Illimité',
        1  => '1 lecture',
        5  => '5 lectures',
        10 => '10 lectures',
        25 => '25 lectures',
        50 => '50 lectures',
    ];

    public const LANGUAGES = [
        'plaintext'  => 'Texte brut',
        'bash'       => 'Bash / Shell',
        'powershell' => 'PowerShell',
        'python'     => 'Python',
        'json'       => 'JSON',
        'xml'        => 'XML / HTML',
        'php'        => 'PHP',
        'sql'        => 'SQL',
        'yaml'       => 'YAML',
        'nginx'      => 'Nginx',
        'javascript' => 'JavaScript',
        'css'        => 'CSS',
        'ini'        => 'INI / Config',
    ];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 64, unique: true)]
    private string $token;

    #[ORM\Column(type: 'text')]
    private string $payload;

    #[ORM\Column(length: 32)]
    private string $nonce;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $passphraseHash = null;

    #[ORM\Column(length: 32)]
    private string $language = 'plaintext';

    #[ORM\Column(nullable: true)]
    private ?int $maxReads = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $readCount = 0;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id        = Uuid::v4();
        $this->token     = bin2hex(random_bytes(32));
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getToken(): string { return $this->token; }

    public function getPayload(): string { return $this->payload; }
    public function setPayload(string $payload): static { $this->payload = $payload; return $this; }

    public function getNonce(): string { return $this->nonce; }
    public function setNonce(string $nonce): static { $this->nonce = $nonce; return $this; }

    public function getPassphraseHash(): ?string { return $this->passphraseHash; }
    public function setPassphraseHash(?string $hash): static { $this->passphraseHash = $hash; return $this; }
    public function hasPassphrase(): bool { return $this->passphraseHash !== null; }

    public function getLanguage(): string { return $this->language; }
    public function setLanguage(string $language): static { $this->language = $language; return $this; }

    public function getMaxReads(): ?int { return $this->maxReads; }
    public function setMaxReads(?int $maxReads): static { $this->maxReads = $maxReads; return $this; }

    public function getReadCount(): int { return $this->readCount; }

    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(\DateTimeImmutable $expiresAt): static { $this->expiresAt = $expiresAt; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function isExpired(): bool
    {
        return $this->expiresAt <= new \DateTimeImmutable();
    }

    public function readsRemaining(): ?int
    {
        if ($this->maxReads === null) {
            return null;
        }
        return max(0, $this->maxReads - $this->readCount);
    }
}

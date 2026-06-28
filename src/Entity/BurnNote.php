<?php

namespace App\Entity;

use App\Repository\BurnNoteRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: BurnNoteRepository::class)]
class BurnNote
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 32, unique: true)]
    private string $token;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $payload = null;

    #[ORM\Column(length: 32)]
    private string $nonce;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $passphraseHash = null;

    #[ORM\Column]
    private int $viewsRemaining;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column]
    private bool $expired = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id        = Uuid::v4();
        $this->token     = bin2hex(random_bytes(16));
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }

    public function getToken(): string { return $this->token; }

    public function getPayload(): ?string { return $this->payload; }
    public function setPayload(?string $payload): static { $this->payload = $payload; return $this; }

    public function getNonce(): string { return $this->nonce; }
    public function setNonce(string $nonce): static { $this->nonce = $nonce; return $this; }

    public function getPassphraseHash(): ?string { return $this->passphraseHash; }
    public function setPassphraseHash(?string $hash): static { $this->passphraseHash = $hash; return $this; }
    public function hasPassphrase(): bool { return $this->passphraseHash !== null; }

    public function getViewsRemaining(): int { return $this->viewsRemaining; }
    public function setViewsRemaining(int $viewsRemaining): static { $this->viewsRemaining = $viewsRemaining; return $this; }

    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(\DateTimeImmutable $expiresAt): static { $this->expiresAt = $expiresAt; return $this; }

    public function isExpired(): bool { return $this->expired; }
    public function setExpired(bool $expired): static { $this->expired = $expired; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function isAccessible(): bool
    {
        return !$this->expired
            && $this->payload !== null
            && $this->viewsRemaining > 0
            && $this->expiresAt > new \DateTimeImmutable();
    }

    public function burn(): void
    {
        $this->payload        = null;
        $this->nonce          = '';
        $this->viewsRemaining = 0;
        $this->expired        = true;
    }
}

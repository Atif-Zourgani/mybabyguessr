<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'app_error_log')]
#[ORM\Index(columns: ['created_at'], name: 'idx_error_log_created')]
#[ORM\Index(columns: ['status_code'], name: 'idx_error_log_status')]
class AppErrorLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'smallint')]
    private int $statusCode;

    #[ORM\Column(length: 500)]
    private string $url;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $message = null;

    // Pas de FK — on stocke l'ID en valeur brute pour survivre à la suppression d'un compte
    #[ORM\Column(nullable: true)]
    private ?int $userId = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $userEmail = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getStatusCode(): int { return $this->statusCode; }
    public function setStatusCode(int $statusCode): static { $this->statusCode = $statusCode; return $this; }

    public function getUrl(): string { return $this->url; }
    public function setUrl(string $url): static { $this->url = $url; return $this; }

    public function getMessage(): ?string { return $this->message; }
    public function setMessage(?string $message): static { $this->message = $message; return $this; }

    public function getUserId(): ?int { return $this->userId; }
    public function setUserId(?int $userId): static { $this->userId = $userId; return $this; }

    public function getUserEmail(): ?string { return $this->userEmail; }
    public function setUserEmail(?string $userEmail): static { $this->userEmail = $userEmail; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}

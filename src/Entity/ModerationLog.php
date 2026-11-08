<?php

namespace App\Entity;

use App\Enum\ModerationAction;
use App\Repository\ModerationLogRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Trace d'une action de modération. Pas de clé étrangère : on garde des instantanés
 * (email, libellé) pour que l'historique survive à la suppression d'un compte ou d'un jeu.
 */
#[ORM\Entity(repositoryClass: ModerationLogRepository::class)]
#[ORM\Table(name: 'moderation_log')]
#[ORM\Index(columns: ['created_at'], name: 'idx_moderation_log_created')]
#[ORM\Index(columns: ['target_type', 'target_id'], name: 'idx_moderation_log_target')]
class ModerationLog
{
    public const TARGET_USER = 'user';
    public const TARGET_GAME = 'game';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(nullable: true)]
    private ?int $adminId = null;

    #[ORM\Column(length: 180)]
    private string $adminEmail;

    #[ORM\Column(enumType: ModerationAction::class)]
    private ModerationAction $action;

    #[ORM\Column(length: 20)]
    private string $targetType;

    #[ORM\Column(nullable: true)]
    private ?int $targetId = null;

    #[ORM\Column(length: 255)]
    private string $targetLabel;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        ?User $admin,
        ModerationAction $action,
        string $targetType,
        ?int $targetId,
        string $targetLabel,
        ?string $reason = null,
    ) {
        $this->adminId     = $admin?->getId();
        $this->adminEmail  = mb_substr((string) $admin?->getEmail(), 0, 180);
        $this->action      = $action;
        $this->targetType  = $targetType;
        $this->targetId    = $targetId;
        $this->targetLabel = mb_substr($targetLabel, 0, 255);
        $this->reason      = $reason !== null && $reason !== '' ? mb_substr($reason, 0, 500) : null;
        $this->createdAt   = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getAdminId(): ?int { return $this->adminId; }
    public function getAdminEmail(): string { return $this->adminEmail; }
    public function getAction(): ModerationAction { return $this->action; }
    public function getTargetType(): string { return $this->targetType; }
    public function getTargetId(): ?int { return $this->targetId; }
    public function getTargetLabel(): string { return $this->targetLabel; }
    public function getReason(): ?string { return $this->reason; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}

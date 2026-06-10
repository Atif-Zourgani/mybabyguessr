<?php

namespace App\Entity;

use App\Enum\AnswerGender;
use App\Enum\GameStatus;
use App\Enum\NameMode;
use App\Repository\GameRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GameRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(columns: ['slug'], name: 'idx_game_slug')]
#[ORM\Index(columns: ['status'], name: 'idx_game_status')]
#[ORM\Index(columns: ['created_at'], name: 'idx_game_created_at')]
#[ORM\Index(columns: ['updated_at'], name: 'idx_game_updated_at')]
#[ORM\Index(columns: ['user_id', 'status'], name: 'idx_game_user_status')]
class Game
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'games')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $token;

    #[ORM\Column(length: 100)]
    private string $slug = 'baby';

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $image = null;

    #[ORM\Column(enumType: GameStatus::class)]
    private GameStatus $status = GameStatus::Open;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dueDate = null;

    // ── Catégories activées ────────────────────────────────────────

    #[ORM\Column]
    private bool $guessGender = false;

    #[ORM\Column]
    private bool $guessName = false;

    #[ORM\Column]
    private bool $guessDate = false;

    #[ORM\Column]
    private bool $guessWeight = false;

    #[ORM\Column]
    private bool $guessHeight = false;

    #[ORM\Column(enumType: NameMode::class, nullable: true)]
    private ?NameMode $nameMode = null;

    // ── Réponses réelles (renseignées à la révélation) ────────────

    #[ORM\Column(enumType: AnswerGender::class, nullable: true)]
    private ?AnswerGender $answerGender = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $answerName = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $answerDate = null;

    #[ORM\Column(nullable: true)]
    private ?int $answerWeight = null;

    #[ORM\Column(nullable: true)]
    private ?int $answerHeight = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $showGuesses = false;

    #[ORM\OneToMany(targetEntity: Guess::class, mappedBy: 'game', orphanRemoval: true)]
    private Collection $guesses;

    // ── Timestamps ────────────────────────────────────────────────

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->token     = bin2hex(random_bytes(16));
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->guesses   = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // ── Getters / Setters ─────────────────────────────────────────

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getDisplayTitle(): string
    {
        return $this->title ?? 'Baby ' . ($this->user?->getLastName() ?? 'Guessr');
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function setImage(?string $image): static
    {
        $this->image = $image;

        return $this;
    }

    public function getStatus(): GameStatus
    {
        return $this->status;
    }

    public function setStatus(GameStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isOpen(): bool
    {
        return $this->status === GameStatus::Open;
    }

    public function isRevealed(): bool
    {
        return $this->status === GameStatus::Revealed;
    }

    public function getDueDate(): ?\DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function setDueDate(?\DateTimeImmutable $dueDate): static
    {
        $this->dueDate = $dueDate;

        return $this;
    }

    public function isGuessGender(): bool
    {
        return $this->guessGender;
    }

    public function setGuessGender(bool $guessGender): static
    {
        $this->guessGender = $guessGender;

        return $this;
    }

    public function isGuessName(): bool
    {
        return $this->guessName;
    }

    public function setGuessName(bool $guessName): static
    {
        $this->guessName = $guessName;

        return $this;
    }

    public function isGuessDate(): bool
    {
        return $this->guessDate;
    }

    public function setGuessDate(bool $guessDate): static
    {
        $this->guessDate = $guessDate;

        return $this;
    }

    public function isGuessWeight(): bool
    {
        return $this->guessWeight;
    }

    public function setGuessWeight(bool $guessWeight): static
    {
        $this->guessWeight = $guessWeight;

        return $this;
    }

    public function isGuessHeight(): bool
    {
        return $this->guessHeight;
    }

    public function setGuessHeight(bool $guessHeight): static
    {
        $this->guessHeight = $guessHeight;

        return $this;
    }

    public function getNameMode(): ?NameMode
    {
        return $this->nameMode;
    }

    public function setNameMode(?NameMode $nameMode): static
    {
        $this->nameMode = $nameMode;

        return $this;
    }

    public function getAnswerGender(): ?AnswerGender
    {
        return $this->answerGender;
    }

    public function setAnswerGender(?AnswerGender $answerGender): static
    {
        $this->answerGender = $answerGender;

        return $this;
    }

    public function getAnswerName(): ?string
    {
        return $this->answerName;
    }

    public function setAnswerName(?string $answerName): static
    {
        $this->answerName = $answerName;

        return $this;
    }

    public function getAnswerDate(): ?\DateTimeImmutable
    {
        return $this->answerDate;
    }

    public function setAnswerDate(?\DateTimeImmutable $answerDate): static
    {
        $this->answerDate = $answerDate;

        return $this;
    }

    public function getAnswerWeight(): ?int
    {
        return $this->answerWeight;
    }

    public function setAnswerWeight(?int $answerWeight): static
    {
        $this->answerWeight = $answerWeight;

        return $this;
    }

    public function getAnswerHeight(): ?int
    {
        return $this->answerHeight;
    }

    public function setAnswerHeight(?int $answerHeight): static
    {
        $this->answerHeight = $answerHeight;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isShowGuesses(): bool
    {
        return $this->showGuesses;
    }

    public function setShowGuesses(bool $showGuesses): static
    {
        $this->showGuesses = $showGuesses;

        return $this;
    }

    public function getGuesses(): Collection
    {
        return $this->guesses;
    }

    public function hasAtLeastOneCategory(): bool
    {
        return $this->guessGender
            || $this->guessName
            || $this->guessDate
            || $this->guessWeight
            || $this->guessHeight;
    }
}

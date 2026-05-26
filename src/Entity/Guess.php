<?php

namespace App\Entity;

use App\Enum\AnswerGender;
use App\Repository\GuessRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GuessRepository::class)]
class Guess
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'guesses')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Game $game = null;

    #[ORM\Column(length: 100)]
    private string $playerName;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $playerEmail = null;

    #[ORM\Column(enumType: AnswerGender::class, nullable: true)]
    private ?AnswerGender $guessGender = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $guessName = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $guessDate = null;

    #[ORM\Column(nullable: true)]
    private ?int $guessWeight = null;

    #[ORM\Column(nullable: true)]
    private ?int $guessHeight = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $nameAttempt1 = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $nameAttempt2 = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $nameAttempt3 = null;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $nameHintsUsed = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGame(): ?Game
    {
        return $this->game;
    }

    public function setGame(?Game $game): static
    {
        $this->game = $game;

        return $this;
    }

    public function getPlayerName(): string
    {
        return $this->playerName;
    }

    public function setPlayerName(string $playerName): static
    {
        $this->playerName = $playerName;

        return $this;
    }

    public function getPlayerEmail(): ?string
    {
        return $this->playerEmail;
    }

    public function setPlayerEmail(?string $playerEmail): static
    {
        $this->playerEmail = $playerEmail;

        return $this;
    }

    public function getGuessGender(): ?AnswerGender
    {
        return $this->guessGender;
    }

    public function setGuessGender(?AnswerGender $guessGender): static
    {
        $this->guessGender = $guessGender;

        return $this;
    }

    public function getGuessName(): ?string
    {
        return $this->guessName;
    }

    public function setGuessName(?string $guessName): static
    {
        $this->guessName = $guessName;

        return $this;
    }

    public function getGuessDate(): ?\DateTimeImmutable
    {
        return $this->guessDate;
    }

    public function setGuessDate(?\DateTimeImmutable $guessDate): static
    {
        $this->guessDate = $guessDate;

        return $this;
    }

    public function getGuessWeight(): ?int
    {
        return $this->guessWeight;
    }

    public function setGuessWeight(?int $guessWeight): static
    {
        $this->guessWeight = $guessWeight;

        return $this;
    }

    public function getGuessHeight(): ?int
    {
        return $this->guessHeight;
    }

    public function setGuessHeight(?int $guessHeight): static
    {
        $this->guessHeight = $guessHeight;

        return $this;
    }

    public function getNameAttempt1(): ?string { return $this->nameAttempt1; }
    public function setNameAttempt1(?string $v): static { $this->nameAttempt1 = $v; return $this; }

    public function getNameAttempt2(): ?string { return $this->nameAttempt2; }
    public function setNameAttempt2(?string $v): static { $this->nameAttempt2 = $v; return $this; }

    public function getNameAttempt3(): ?string { return $this->nameAttempt3; }
    public function setNameAttempt3(?string $v): static { $this->nameAttempt3 = $v; return $this; }

    public function getNameHintsUsed(): int
    {
        return $this->nameHintsUsed;
    }

    public function setNameHintsUsed(int $nameHintsUsed): static
    {
        $this->nameHintsUsed = $nameHintsUsed;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

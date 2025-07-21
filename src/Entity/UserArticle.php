<?php

namespace App\Entity;

use App\Repository\UserArticleRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserArticleRepository::class)]
class UserArticle
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'userArticles')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\ManyToOne(inversedBy: 'userArticles')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Article $article = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isRead = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isStarred = false;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $readAt = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $personalizationScore = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $interactionData = null;

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

    public function getArticle(): ?Article
    {
        return $this->article;
    }

    public function setArticle(?Article $article): static
    {
        $this->article = $article;
        return $this;
    }

    public function isRead(): bool
    {
        return $this->isRead;
    }

    public function setIsRead(bool $isRead): static
    {
        $this->isRead = $isRead;
        if ($isRead && $this->readAt === null) {
            $this->readAt = new \DateTime();
        }
        return $this;
    }

    public function isStarred(): bool
    {
        return $this->isStarred;
    }

    public function setIsStarred(bool $isStarred): static
    {
        $this->isStarred = $isStarred;
        return $this;
    }

    public function getReadAt(): ?\DateTimeInterface
    {
        return $this->readAt;
    }

    public function setReadAt(?\DateTimeInterface $readAt): static
    {
        $this->readAt = $readAt;
        return $this;
    }

    public function getPersonalizationScore(): ?float
    {
        return $this->personalizationScore;
    }

    public function setPersonalizationScore(?float $personalizationScore): static
    {
        $this->personalizationScore = $personalizationScore;
        return $this;
    }

    public function getInteractionData(): ?array
    {
        return $this->interactionData;
    }

    public function setInteractionData(?array $interactionData): static
    {
        $this->interactionData = $interactionData;
        return $this;
    }
}
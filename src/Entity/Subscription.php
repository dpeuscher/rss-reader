<?php

namespace App\Entity;

use App\Repository\SubscriptionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SubscriptionRepository::class)]
class Subscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'subscriptions')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\ManyToOne(inversedBy: 'subscriptions')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Feed $feed = null;

    #[ORM\ManyToOne(inversedBy: 'subscriptions')]
    private ?Category $category = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $subscribedAt = null;

    #[ORM\Column(type: 'json')]
    private array $preferences = [];

    public function __construct()
    {
        $this->subscribedAt = new \DateTime();
    }

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

    public function getFeed(): ?Feed
    {
        return $this->feed;
    }

    public function setFeed(?Feed $feed): static
    {
        $this->feed = $feed;
        return $this;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): static
    {
        $this->category = $category;
        return $this;
    }

    public function getSubscribedAt(): ?\DateTimeInterface
    {
        return $this->subscribedAt;
    }

    public function setSubscribedAt(\DateTimeInterface $subscribedAt): static
    {
        $this->subscribedAt = $subscribedAt;
        return $this;
    }

    public function getPreferences(): array
    {
        return $this->preferences;
    }

    public function setPreferences(array $preferences): static
    {
        $this->preferences = $preferences;
        return $this;
    }

    public function getEntryLimit(): ?int
    {
        return $this->preferences['entry_limit'] ?? null;
    }

    public function setEntryLimit(?int $limit): static
    {
        if ($limit === null) {
            unset($this->preferences['entry_limit']);
        } else {
            $this->preferences['entry_limit'] = $limit;
        }
        return $this;
    }

    public function getEffectiveEntryLimit(int $defaultLimit = 20): int
    {
        return $this->getEntryLimit() ?? $defaultLimit;
    }
}
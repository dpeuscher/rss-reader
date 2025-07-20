<?php

namespace App\Entity;

use App\Repository\FeedRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FeedRepository::class)]
class Feed
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 500)]
    private ?string $url = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $siteUrl = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $lastUpdated = null;

    #[ORM\Column(length: 20, options: ['default' => 'active'])]
    private string $status = 'active';

    #[ORM\Column(type: 'integer', options: ['default' => 60])]
    private int $refreshInterval = 60;

    #[ORM\Column(length: 20, options: ['default' => 'healthy'])]
    private string $healthStatus = 'healthy';

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $lastHealthCheck = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $consecutiveFailures = 0;

    #[ORM\OneToMany(mappedBy: 'feed', targetEntity: Article::class, orphanRemoval: true)]
    private Collection $articles;

    #[ORM\OneToMany(mappedBy: 'feed', targetEntity: Subscription::class, orphanRemoval: true)]
    private Collection $subscriptions;

    #[ORM\OneToMany(mappedBy: 'feed', targetEntity: FeedHealthLog::class, orphanRemoval: true)]
    private Collection $healthLogs;

    public function __construct()
    {
        $this->articles = new ArrayCollection();
        $this->subscriptions = new ArrayCollection();
        $this->healthLogs = new ArrayCollection();
        $this->lastUpdated = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(string $url): static
    {
        $this->url = $url;
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getSiteUrl(): ?string
    {
        return $this->siteUrl;
    }

    public function setSiteUrl(?string $siteUrl): static
    {
        $this->siteUrl = $siteUrl;
        return $this;
    }

    public function getLastUpdated(): ?\DateTimeInterface
    {
        return $this->lastUpdated;
    }

    public function setLastUpdated(\DateTimeInterface $lastUpdated): static
    {
        $this->lastUpdated = $lastUpdated;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getRefreshInterval(): int
    {
        return $this->refreshInterval;
    }

    public function setRefreshInterval(int $refreshInterval): static
    {
        $this->refreshInterval = $refreshInterval;
        return $this;
    }

    public function getArticles(): Collection
    {
        return $this->articles;
    }

    public function addArticle(Article $article): static
    {
        if (!$this->articles->contains($article)) {
            $this->articles->add($article);
            $article->setFeed($this);
        }
        return $this;
    }

    public function removeArticle(Article $article): static
    {
        if ($this->articles->removeElement($article)) {
            if ($article->getFeed() === $this) {
                $article->setFeed(null);
            }
        }
        return $this;
    }

    public function getSubscriptions(): Collection
    {
        return $this->subscriptions;
    }

    public function addSubscription(Subscription $subscription): static
    {
        if (!$this->subscriptions->contains($subscription)) {
            $this->subscriptions->add($subscription);
            $subscription->setFeed($this);
        }
        return $this;
    }

    public function removeSubscription(Subscription $subscription): static
    {
        if ($this->subscriptions->removeElement($subscription)) {
            if ($subscription->getFeed() === $this) {
                $subscription->setFeed(null);
            }
        }
        return $this;
    }

    public function getHealthStatus(): string
    {
        return $this->healthStatus;
    }

    public function setHealthStatus(string $healthStatus): static
    {
        $this->healthStatus = $healthStatus;
        return $this;
    }

    public function getLastHealthCheck(): ?\DateTimeInterface
    {
        return $this->lastHealthCheck;
    }

    public function setLastHealthCheck(?\DateTimeInterface $lastHealthCheck): static
    {
        $this->lastHealthCheck = $lastHealthCheck;
        return $this;
    }

    public function getConsecutiveFailures(): int
    {
        return $this->consecutiveFailures;
    }

    public function setConsecutiveFailures(int $consecutiveFailures): static
    {
        $this->consecutiveFailures = $consecutiveFailures;
        return $this;
    }

    public function getHealthLogs(): Collection
    {
        return $this->healthLogs;
    }

    public function addHealthLog(FeedHealthLog $healthLog): static
    {
        if (!$this->healthLogs->contains($healthLog)) {
            $this->healthLogs->add($healthLog);
            $healthLog->setFeed($this);
        }
        return $this;
    }

    public function removeHealthLog(FeedHealthLog $healthLog): static
    {
        if ($this->healthLogs->removeElement($healthLog)) {
            if ($healthLog->getFeed() === $this) {
                $healthLog->setFeed(null);
            }
        }
        return $this;
    }

    public function isHealthy(): bool
    {
        return $this->healthStatus === 'healthy';
    }

    public function isUnhealthy(): bool
    {
        return $this->healthStatus === 'unhealthy';
    }

    public function hasWarning(): bool
    {
        return $this->healthStatus === 'warning';
    }
}

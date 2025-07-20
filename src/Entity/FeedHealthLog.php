<?php

namespace App\Entity;

use App\Repository\FeedHealthLogRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FeedHealthLogRepository::class)]
class FeedHealthLog
{
    public const STATUS_HEALTHY = 'healthy';
    public const STATUS_WARNING = 'warning';
    public const STATUS_UNHEALTHY = 'unhealthy';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Feed::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Feed $feed = null;

    #[ORM\Column(length: 20)]
    private ?string $status = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $responseTime = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $httpStatusCode = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $checkedAt = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $consecutiveFailures = null;

    public function __construct()
    {
        $this->checkedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getResponseTime(): ?int
    {
        return $this->responseTime;
    }

    public function setResponseTime(?int $responseTime): static
    {
        $this->responseTime = $responseTime;
        return $this;
    }

    public function getHttpStatusCode(): ?int
    {
        return $this->httpStatusCode;
    }

    public function setHttpStatusCode(?int $httpStatusCode): static
    {
        $this->httpStatusCode = $httpStatusCode;
        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): static
    {
        $this->errorMessage = $errorMessage;
        return $this;
    }

    public function getCheckedAt(): ?\DateTimeInterface
    {
        return $this->checkedAt;
    }

    public function setCheckedAt(\DateTimeInterface $checkedAt): static
    {
        $this->checkedAt = $checkedAt;
        return $this;
    }

    public function getConsecutiveFailures(): ?int
    {
        return $this->consecutiveFailures;
    }

    public function setConsecutiveFailures(?int $consecutiveFailures): static
    {
        $this->consecutiveFailures = $consecutiveFailures;
        return $this;
    }

    public function isHealthy(): bool
    {
        return $this->status === self::STATUS_HEALTHY;
    }

    public function isWarning(): bool
    {
        return $this->status === self::STATUS_WARNING;
    }

    public function isUnhealthy(): bool
    {
        return $this->status === self::STATUS_UNHEALTHY;
    }
}
<?php

namespace App\Entity;

use App\Repository\UserAiPreferenceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserAiPreferenceRepository::class)]
#[ORM\Table(name: 'user_ai_preferences')]
#[ORM\Index(columns: ['user_id', 'ai_processing_enabled'], name: 'idx_user_ai_prefs_user_enabled')]
class UserAiPreference
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'aiPreference')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $aiProcessingEnabled = false;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $consentGivenAt = null;

    #[ORM\Column(length: 20, options: ['default' => 'medium'])]
    private string $preferredSummaryLength = 'medium';

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
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

    public function isAiProcessingEnabled(): bool
    {
        return $this->aiProcessingEnabled;
    }

    public function setAiProcessingEnabled(bool $aiProcessingEnabled): static
    {
        $this->aiProcessingEnabled = $aiProcessingEnabled;
        
        if ($aiProcessingEnabled && $this->consentGivenAt === null) {
            $this->consentGivenAt = new \DateTime();
        }
        
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getConsentGivenAt(): ?\DateTimeInterface
    {
        return $this->consentGivenAt;
    }

    public function setConsentGivenAt(?\DateTimeInterface $consentGivenAt): static
    {
        $this->consentGivenAt = $consentGivenAt;
        return $this;
    }

    public function getPreferredSummaryLength(): string
    {
        return $this->preferredSummaryLength;
    }

    public function setPreferredSummaryLength(string $preferredSummaryLength): static
    {
        $this->preferredSummaryLength = $preferredSummaryLength;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }
}
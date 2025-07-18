<?php

namespace App\Entity;

use App\Repository\AiArticleSummaryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AiArticleSummaryRepository::class)]
#[ORM\Table(name: 'ai_article_summaries')]
#[ORM\Index(columns: ['article_id', 'created_at'], name: 'idx_ai_summaries_article_created')]
class AiArticleSummary
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'aiSummaries')]
    #[ORM\JoinColumn(name: 'article_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Article $article = null;

    #[ORM\Column(type: 'text')]
    private ?string $summaryText = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $topics = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $processingTime = null;

    #[ORM\Column(length: 50)]
    private ?string $aiProvider = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getSummaryText(): ?string
    {
        return $this->summaryText;
    }

    public function setSummaryText(string $summaryText): static
    {
        $this->summaryText = $summaryText;
        return $this;
    }

    public function getTopics(): ?array
    {
        return $this->topics;
    }

    public function setTopics(?array $topics): static
    {
        $this->topics = $topics;
        return $this;
    }

    public function getProcessingTime(): ?int
    {
        return $this->processingTime;
    }

    public function setProcessingTime(?int $processingTime): static
    {
        $this->processingTime = $processingTime;
        return $this;
    }

    public function getAiProvider(): ?string
    {
        return $this->aiProvider;
    }

    public function setAiProvider(string $aiProvider): static
    {
        $this->aiProvider = $aiProvider;
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
}
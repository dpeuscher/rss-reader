<?php

namespace App\Entity;

use App\Repository\ArticleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ArticleRepository::class)]
class Article
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 500)]
    private ?string $guid = null;

    #[ORM\Column(length: 500)]
    private ?string $title = null;

    #[ORM\Column(type: 'text')]
    private ?string $content = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $summary = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $publishedAt = null;

    #[ORM\Column(length: 500)]
    private ?string $url = null;

    #[ORM\ManyToOne(inversedBy: 'articles')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Feed $feed = null;

    #[ORM\OneToMany(mappedBy: 'article', targetEntity: UserArticle::class, orphanRemoval: true)]
    private Collection $userArticles;

    public function __construct()
    {
        $this->userArticles = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGuid(): ?string
    {
        return $this->guid;
    }

    public function setGuid(string $guid): static
    {
        $this->guid = $guid;
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

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;
        return $this;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function setSummary(?string $summary): static
    {
        $this->summary = $summary;
        return $this;
    }

    public function getPublishedAt(): ?\DateTimeInterface
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(\DateTimeInterface $publishedAt): static
    {
        $this->publishedAt = $publishedAt;
        return $this;
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

    public function getFeed(): ?Feed
    {
        return $this->feed;
    }

    public function setFeed(?Feed $feed): static
    {
        $this->feed = $feed;
        return $this;
    }

    public function getUserArticles(): Collection
    {
        return $this->userArticles;
    }

    public function addUserArticle(UserArticle $userArticle): static
    {
        if (!$this->userArticles->contains($userArticle)) {
            $this->userArticles->add($userArticle);
            $userArticle->setArticle($this);
        }
        return $this;
    }

    public function removeUserArticle(UserArticle $userArticle): static
    {
        if ($this->userArticles->removeElement($userArticle)) {
            if ($userArticle->getArticle() === $this) {
                $userArticle->setArticle(null);
            }
        }
        return $this;
    }
}
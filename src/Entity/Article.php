<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'articles')]
#[ORM\Index(columns: ['guid'], name: 'idx_guid')]
#[ORM\Index(columns: ['published_at'], name: 'idx_published_at')]
class Article
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 500)]
    private ?string $title = null;

    #[ORM\Column(length: 1000, unique: true)]
    private ?string $guid = null;

    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $link = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $content = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $author = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\ManyToOne(inversedBy: 'articles')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Feed $feed = null;

    #[ORM\OneToMany(mappedBy: 'article', targetEntity: ReadArticle::class, orphanRemoval: true)]
    private Collection $readByUsers;

    public function __construct()
    {
        $this->readByUsers = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getGuid(): ?string
    {
        return $this->guid;
    }

    public function setGuid(string $guid): static
    {
        $this->guid = $guid;
        return $this;
    }

    public function getLink(): ?string
    {
        return $this->link;
    }

    public function setLink(?string $link): static
    {
        $this->link = $link;
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

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(?string $content): static
    {
        $this->content = $content;
        return $this;
    }

    public function getAuthor(): ?string
    {
        return $this->author;
    }

    public function setAuthor(?string $author): static
    {
        $this->author = $author;
        return $this;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?\DateTimeImmutable $publishedAt): static
    {
        $this->publishedAt = $publishedAt;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
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

    public function getReadByUsers(): Collection
    {
        return $this->readByUsers;
    }

    public function addReadByUser(ReadArticle $readByUser): static
    {
        if (!$this->readByUsers->contains($readByUser)) {
            $this->readByUsers->add($readByUser);
            $readByUser->setArticle($this);
        }
        return $this;
    }

    public function removeReadByUser(ReadArticle $readByUser): static
    {
        if ($this->readByUsers->removeElement($readByUser)) {
            if ($readByUser->getArticle() === $this) {
                $readByUser->setArticle(null);
            }
        }
        return $this;
    }

    public function isReadByUser(User $user): bool
    {
        foreach ($this->readByUsers as $readArticle) {
            if ($readArticle->getUser() === $user) {
                return true;
            }
        }
        return false;
    }
}
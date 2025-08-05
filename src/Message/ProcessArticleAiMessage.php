<?php

namespace App\Message;

class ProcessArticleAiMessage
{
    private int $articleId;
    private ?int $userId;

    public function __construct(int $articleId, ?int $userId = null)
    {
        $this->articleId = $articleId;
        $this->userId = $userId;
    }

    public function getArticleId(): int
    {
        return $this->articleId;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }
}
<?php

namespace App\MessageHandler;

use App\Message\ProcessArticleAiMessage;
use App\Repository\ArticleRepository;
use App\Repository\UserRepository;
use App\Service\AiSummaryService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class ProcessArticleAiMessageHandler
{
    private ArticleRepository $articleRepository;
    private UserRepository $userRepository;
    private AiSummaryService $aiSummaryService;
    private EntityManagerInterface $entityManager;
    private LoggerInterface $logger;

    public function __construct(
        ArticleRepository $articleRepository,
        UserRepository $userRepository,
        AiSummaryService $aiSummaryService,
        EntityManagerInterface $entityManager,
        LoggerInterface $logger
    ) {
        $this->articleRepository = $articleRepository;
        $this->userRepository = $userRepository;
        $this->aiSummaryService = $aiSummaryService;
        $this->entityManager = $entityManager;
        $this->logger = $logger;
    }

    public function __invoke(ProcessArticleAiMessage $message): void
    {
        $article = $this->articleRepository->find($message->getArticleId());
        
        if (!$article) {
            $this->logger->warning('Article not found for AI processing', [
                'article_id' => $message->getArticleId()
            ]);
            return;
        }

        $user = null;
        if ($message->getUserId()) {
            $user = $this->userRepository->find($message->getUserId());
            
            if (!$user) {
                $this->logger->warning('User not found for AI processing', [
                    'user_id' => $message->getUserId(),
                    'article_id' => $message->getArticleId()
                ]);
                return;
            }

            if (!$this->aiSummaryService->hasUserConsent($user)) {
                $this->logger->info('User has not consented to AI processing', [
                    'user_id' => $message->getUserId(),
                    'article_id' => $message->getArticleId()
                ]);
                return;
            }
        }

        // Check if article already has a summary
        if ($article->getAiProcessingStatus() === 'completed' && !$article->getAiSummaries()->isEmpty()) {
            $this->logger->info('Article already has AI summary', [
                'article_id' => $message->getArticleId()
            ]);
            return;
        }

        try {
            $this->logger->info('Starting AI processing for article', [
                'article_id' => $message->getArticleId(),
                'user_id' => $message->getUserId()
            ]);

            $summary = $this->aiSummaryService->summarizeArticle($article, $user);
            
            if ($summary) {
                $this->logger->info('AI processing completed successfully', [
                    'article_id' => $message->getArticleId(),
                    'summary_id' => $summary->getId(),
                    'processing_time' => $summary->getProcessingTime()
                ]);
            } else {
                $this->logger->warning('AI processing failed', [
                    'article_id' => $message->getArticleId()
                ]);
            }

        } catch (\Exception $e) {
            $this->logger->error('AI processing threw exception', [
                'article_id' => $message->getArticleId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Mark article as failed
            $article->setAiProcessingStatus('failed');
            $this->entityManager->flush();
        }
    }
}
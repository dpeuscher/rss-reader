<?php

namespace App\Controller;

use App\Entity\UserAiPreference;
use App\Repository\UserAiPreferenceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/ai-consent', name: 'app_ai_consent_')]
class AiConsentController extends AbstractController
{
    private UserAiPreferenceRepository $preferenceRepository;
    private EntityManagerInterface $entityManager;

    public function __construct(
        UserAiPreferenceRepository $preferenceRepository,
        EntityManagerInterface $entityManager
    ) {
        $this->preferenceRepository = $preferenceRepository;
        $this->entityManager = $entityManager;
    }

    #[Route('/status', name: 'status', methods: ['GET'])]
    public function getConsentStatus(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'User not authenticated'], 401);
        }

        $preference = $user->getAiPreference();
        
        return new JsonResponse([
            'hasConsent' => $user->hasAiConsent(),
            'aiProcessingEnabled' => $preference ? $preference->isAiProcessingEnabled() : false,
            'consentGivenAt' => $preference && $preference->getConsentGivenAt() 
                ? $preference->getConsentGivenAt()->format('Y-m-d H:i:s') 
                : null,
            'preferredSummaryLength' => $preference ? $preference->getPreferredSummaryLength() : 'medium'
        ]);
    }

    #[Route('/grant', name: 'grant', methods: ['POST'])]
    public function grantConsent(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'User not authenticated'], 401);
        }

        $data = json_decode($request->getContent(), true);
        $summaryLength = $data['preferredSummaryLength'] ?? 'medium';

        if (!in_array($summaryLength, ['short', 'medium', 'long'])) {
            return new JsonResponse(['error' => 'Invalid summary length'], 400);
        }

        $preference = $user->getAiPreference();
        
        if (!$preference) {
            $preference = new UserAiPreference();
            $preference->setUser($user);
            $user->setAiPreference($preference);
        }

        $preference->setAiProcessingEnabled(true);
        $preference->setConsentGivenAt(new \DateTime());
        $preference->setPreferredSummaryLength($summaryLength);

        $this->entityManager->persist($preference);
        $this->entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'AI consent granted successfully',
            'consentGivenAt' => $preference->getConsentGivenAt()->format('Y-m-d H:i:s')
        ]);
    }

    #[Route('/revoke', name: 'revoke', methods: ['POST'])]
    public function revokeConsent(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'User not authenticated'], 401);
        }

        $preference = $user->getAiPreference();
        
        if ($preference) {
            $preference->setAiProcessingEnabled(false);
            $this->entityManager->flush();
        }

        return new JsonResponse([
            'success' => true,
            'message' => 'AI consent revoked successfully'
        ]);
    }

    #[Route('/preferences', name: 'preferences', methods: ['POST'])]
    public function updatePreferences(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'User not authenticated'], 401);
        }

        $data = json_decode($request->getContent(), true);
        $summaryLength = $data['preferredSummaryLength'] ?? null;

        if ($summaryLength && !in_array($summaryLength, ['short', 'medium', 'long'])) {
            return new JsonResponse(['error' => 'Invalid summary length'], 400);
        }

        $preference = $user->getAiPreference();
        
        if (!$preference) {
            return new JsonResponse(['error' => 'User has not granted AI consent'], 400);
        }

        if ($summaryLength) {
            $preference->setPreferredSummaryLength($summaryLength);
        }

        $this->entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'Preferences updated successfully',
            'preferredSummaryLength' => $preference->getPreferredSummaryLength()
        ]);
    }

    #[Route('/modal', name: 'modal', methods: ['GET'])]
    public function getConsentModal(): Response
    {
        $user = $this->getUser();
        if (!$user) {
            return new Response('User not authenticated', 401);
        }

        return $this->render('ai_consent/modal.html.twig', [
            'hasConsent' => $user->hasAiConsent(),
            'currentPreference' => $user->getAiPreference()
        ]);
    }
}
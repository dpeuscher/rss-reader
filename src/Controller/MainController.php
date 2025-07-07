<?php

namespace App\Controller;

use App\Service\LicensePlateValidatorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;

class MainController extends AbstractController
{
    public function __construct(
        private readonly LicensePlateValidatorService $licensePlateValidator,
        private readonly CsrfTokenManagerInterface $csrfTokenManager
    ) {
    }
    #[Route('/', name: 'app_main')]
    public function index(): Response
    {
        if (!$this->getUser()) {
            return $this->redirectToRoute('app_login');
        }

        return $this->render('main/index.html.twig', [
            'controller_name' => 'MainController',
        ]);
    }

    #[Route('/dashboard', name: 'app_dashboard')]
    public function dashboard(): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        return $this->render('main/dashboard.html.twig', [
            'user' => $this->getUser(),
        ]);
    }

    /**
     * Validates German license plates.
     *
     * @param Request $request The HTTP request
     * @param LicensePlateValidatorService $validatorService The license plate validator service
     * @return Response The response containing the validation result
     */
    #[Route('/license-plate-validator', name: 'app_license_plate_validator')]
    public function licensePlateValidator(Request $request, LicensePlateValidatorService $validatorService): Response
    {
        $licensePlate = $request->request->get('license_plate');
        $isValid = false;
        $errorMessage = '';

        if ($licensePlate !== null) {
            // Validate CSRF token
            $token = $request->request->get('_token');
            if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('license_plate_validator', $token))) {
                $errorMessage = 'Ungültiges Formular. Bitte versuchen Sie es erneut.';
            } else {
                // Sanitize input to prevent XSS
                $licensePlate = htmlspecialchars(strip_tags(trim($licensePlate)), ENT_QUOTES, 'UTF-8');
                
                $result = $validatorService->validate($licensePlate);
                $isValid = $result['valid'];
                $errorMessage = $result['error'];
            }
        }

        return $this->render('main/license_plate_validator.html.twig', [
            'license_plate' => $licensePlate,
            'is_valid' => $isValid,
            'error_message' => $errorMessage,
        ]);
    }

}
<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;

class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $userPasswordHasher,
        EntityManagerInterface $entityManager
    ): Response {
        // Check if user is already authenticated
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        // Apply rate limiting (simplified version)
        $session = $request->getSession();
        $lastAttempt = $session->get('last_registration_attempt', 0);
        $currentTime = time();
        
        if ($currentTime - $lastAttempt < 60) { // 1 minute cooldown
            $this->addFlash('error', 'Zu viele Registrierungsversuche. Bitte versuchen Sie es später erneut.');
            return $this->render('registration/register.html.twig', [
                'registrationForm' => null,
                'rateLimited' => true,
            ]);
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $session->set('last_registration_attempt', $currentTime);
            
            try {
                // Check if email already exists
                $existingUser = $entityManager->getRepository(User::class)->findOneBy(['email' => $user->getEmail()]);
                if ($existingUser) {
                    $this->addFlash('error', 'Diese E-Mail-Adresse ist bereits registriert.');
                    return $this->render('registration/register.html.twig', [
                        'registrationForm' => $form->createView(),
                        'rateLimited' => false,
                    ]);
                }

                // Hash password
                $hashedPassword = $userPasswordHasher->hashPassword(
                    $user,
                    $form->get('plainPassword')->getData()
                );
                $user->setPassword($hashedPassword);

                // Set default role
                $user->setRoles(['ROLE_USER']);

                // Persist user
                $entityManager->persist($user);
                $entityManager->flush();

                $this->addFlash('success', 'Registrierung erfolgreich! Sie können sich jetzt anmelden.');

                return $this->redirectToRoute('app_login');
            } catch (\Exception $e) {
                // Log the error for debugging
                error_log('Registration failed: ' . $e->getMessage());

                $this->addFlash('error', 'Ein Fehler ist aufgetreten. Bitte versuchen Sie es erneut.');
                
                return $this->render('registration/register.html.twig', [
                    'registrationForm' => $form->createView(),
                    'rateLimited' => false,
                ]);
            }
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form->createView(),
            'rateLimited' => false,
        ]);
    }
}
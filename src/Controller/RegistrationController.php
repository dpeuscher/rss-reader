<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\FormLoginAuthenticator;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $userPasswordHasher,
        UserAuthenticatorInterface $userAuthenticator,
        FormLoginAuthenticator $authenticator,
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator,
        UserRepository $userRepository
    ): Response {
        // If user is already authenticated, redirect to dashboard
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        if ($request->isMethod('POST')) {
            // Get form data
            $email = $request->request->get('email');
            $password = $request->request->get('password');
            $passwordConfirm = $request->request->get('password_confirm');
            $csrfToken = $request->request->get('_csrf_token');

            // Validate CSRF token
            if (!$this->isCsrfTokenValid('register', $csrfToken)) {
                $this->addFlash('error', 'CSRF token is invalid. Please try again.');
                return $this->render('security/register.html.twig', [
                    'email' => $email,
                ]);
            }

            $errors = [];

            // Validate email
            $emailConstraints = [
                new Assert\NotBlank(['message' => 'Email is required.']),
                new Assert\Email(['message' => 'Please enter a valid email address.']),
            ];
            
            $emailViolations = $validator->validate($email, $emailConstraints);
            if (count($emailViolations) > 0) {
                foreach ($emailViolations as $violation) {
                    $errors[] = $violation->getMessage();
                }
            }

            // Check if email already exists
            if ($email && $userRepository->findOneBy(['email' => $email])) {
                $errors[] = 'Email address is already registered.';
            }

            // Validate password
            if (empty($password)) {
                $errors[] = 'Password is required.';
            } elseif (strlen($password) < 6) {
                $errors[] = 'Password must be at least 6 characters long.';
            }

            // Validate password confirmation
            if ($password !== $passwordConfirm) {
                $errors[] = 'Passwords do not match.';
            }

            // If there are validation errors, show them
            if (!empty($errors)) {
                foreach ($errors as $error) {
                    $this->addFlash('error', $error);
                }
                return $this->render('security/register.html.twig', [
                    'email' => $email,
                ]);
            }

            // Create new user
            $user = new User();
            $user->setEmail($email);
            $user->setPassword(
                $userPasswordHasher->hashPassword($user, $password)
            );
            $user->setRoles(['ROLE_USER']);

            // Save user to database
            $entityManager->persist($user);
            $entityManager->flush();

            // Add success message
            $this->addFlash('success', 'Your account has been created successfully! Welcome to RSS Reader.');

            // Auto-login the user
            return $userAuthenticator->authenticateUser(
                $user,
                $authenticator,
                $request
            );
        }

        // Show registration form
        return $this->render('security/register.html.twig');
    }
}
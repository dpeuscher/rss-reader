<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\FormLoginAuthenticator;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

class RegistrationController extends AbstractController
{
    private VerifyEmailHelperInterface $verifyEmailHelper;
    private MailerInterface $mailer;
    private EntityManagerInterface $entityManager;
    private LoggerInterface $logger;
    private RateLimiterFactory $registrationLimiter;
    private string $mailFromAddress;
    private string $mailFromName;

    public function __construct(
        VerifyEmailHelperInterface $verifyEmailHelper,
        MailerInterface $mailer,
        EntityManagerInterface $entityManager,
        LoggerInterface $logger,
        RateLimiterFactory $registrationLimiter,
        #[Autowire('%env(MAIL_FROM_ADDRESS)%')] string $mailFromAddress,
        #[Autowire('%env(MAIL_FROM_NAME)%')] string $mailFromName
    ) {
        $this->verifyEmailHelper = $verifyEmailHelper;
        $this->mailer = $mailer;
        $this->entityManager = $entityManager;
        $this->logger = $logger;
        $this->registrationLimiter = $registrationLimiter;
        $this->mailFromAddress = $mailFromAddress;
        $this->mailFromName = $mailFromName;
    }

    #[Route('/register', name: 'app_register')]
    public function register(Request $request, UserPasswordHasherInterface $userPasswordHasher, UserRepository $userRepository): Response
    {
        // Rate limiting
        $limiter = $this->registrationLimiter->create($request->getClientIp());
        if (!$limiter->consume(1)->isAccepted()) {
            $this->addFlash('error', 'Zu viele Registrierungsversuche. Bitte versuchen Sie es später erneut.');
            return $this->redirectToRoute('app_register');
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Check for duplicate email
            $existingUser = $userRepository->findOneBy(['email' => $user->getEmail()]);
            if ($existingUser) {
                $this->addFlash('error', 'Ein Benutzer mit dieser E-Mail-Adresse existiert bereits.');
                return $this->render('registration/register.html.twig', [
                    'registrationForm' => $form,
                ]);
            }

            // encode the plain password
            $user->setPassword(
                $userPasswordHasher->hashPassword(
                    $user,
                    $form->get('plainPassword')->getData()
                )
            );

            // Generate verification token
            $user->setVerificationToken(bin2hex(random_bytes(32)));

            $this->entityManager->persist($user);
            $this->entityManager->flush();

            // generate a signed url and email it to the user
            $this->sendEmailConfirmation($user);

            $this->addFlash(
                'success',
                'Registrierung erfolgreich! Bitte überprüfen Sie Ihre E-Mails zur Bestätigung Ihres Kontos.'
            );

            return $this->redirectToRoute('app_login');
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form,
        ]);
    }

    #[Route('/verify/email', name: 'app_verify_email')]
    public function verifyUserEmail(Request $request, UserRepository $userRepository, TokenStorageInterface $tokenStorage): Response
    {
        $id = $request->get('id');

        if (null === $id) {
            return $this->redirectToRoute('app_register');
        }

        $user = $userRepository->find($id);

        if (null === $user) {
            return $this->redirectToRoute('app_register');
        }

        // Security check: Only allow users to verify their own email or admin users
        $currentUser = $tokenStorage->getToken()?->getUser();
        if ($currentUser && $currentUser instanceof User && $currentUser->getId() !== $user->getId()) {
            if (!in_array('ROLE_ADMIN', $currentUser->getRoles())) {
                $this->logger->warning('User attempted to verify another user\'s email', [
                    'current_user_id' => $currentUser->getId(),
                    'target_user_id' => $user->getId(),
                    'ip' => $request->getClientIp()
                ]);
                throw new AccessDeniedException('Sie können nur Ihre eigene E-Mail-Adresse verifizieren.');
            }
        }

        try {
            $this->verifyEmailHelper->validateEmailConfirmation($request->getUri(), $user->getId(), $user->getEmail());
        } catch (VerifyEmailExceptionInterface $exception) {
            $this->logger->error('Email verification failed', [
                'user_id' => $user->getId(),
                'exception' => $exception->getMessage(),
                'ip' => $request->getClientIp()
            ]);
            $this->addFlash('error', 'Der Bestätigungslink ist ungültig oder abgelaufen.');

            return $this->redirectToRoute('app_register');
        }

        // mark your user as verified
        $user->setIsVerified(true);
        $user->setVerifiedAt(new \DateTime());
        $user->setVerificationToken(null);

        $this->entityManager->flush();

        $this->logger->info('User email verified successfully', [
            'user_id' => $user->getId(),
            'email' => $user->getEmail()
        ]);

        $this->addFlash('success', 'Ihre E-Mail-Adresse wurde erfolgreich bestätigt. Sie können sich jetzt anmelden.');

        return $this->redirectToRoute('app_login');
    }

    private function sendEmailConfirmation(User $user): void
    {
        $signatureComponents = $this->verifyEmailHelper->generateSignature(
            'app_verify_email',
            $user->getId(),
            $user->getEmail()
        );

        $email = (new Email())
            ->from($this->mailFromAddress, $this->mailFromName)
            ->to($user->getEmail())
            ->subject('Bestätigen Sie Ihre E-Mail-Adresse')
            ->html($this->renderView('registration/confirmation_email.html.twig', [
                'user' => $user,
                'signedUrl' => $signatureComponents->getSignedUrl(),
                'expiresAtMessageKey' => $signatureComponents->getExpirationMessageKey(),
                'expiresAtMessageData' => $signatureComponents->getExpirationMessageData(),
            ]));

        try {
            $this->mailer->send($email);
            $this->logger->info('Verification email sent successfully', [
                'user_id' => $user->getId(),
                'email' => $user->getEmail()
            ]);
        } catch (TransportExceptionInterface $e) {
            // Log the error and continue
            $this->logger->error('Failed to send verification email', [
                'user_id' => $user->getId(),
                'email' => $user->getEmail(),
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->addFlash('error', 'Die Bestätigungs-E-Mail konnte nicht versendet werden. Bitte versuchen Sie es später erneut.');
        }
    }
}
<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\User;

use App\Auth\Application\Port\PasswordHasherInterface;
use App\Auth\Domain\Model\LoginBlock;
use App\Auth\Domain\Repository\LoginBlockRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Security\SecurityUser;
use App\Auth\Infrastructure\Security\Totp\TotpService;
use App\Shared\Domain\Model\Email;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class PasswordAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly TotpService $totpService,
        private readonly UserRepositoryInterface $userRepository,
        private readonly PasswordHasherInterface $passwordHasher,
        private readonly LoginBlockRepositoryInterface $loginBlockRepository,
        private readonly LoggerInterface $logger,
        private readonly JsonEncoder $jsonEncoder,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->getPathInfo() === '/api/auth/login'
            && $request->isMethod('POST');
    }

    public function authenticate(Request $request): SelfValidatingPassport
    {
        $data = $this->jsonEncoder->decode((string) $request->getContent(), 'json');

        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';
        $totpCode = $data['totpCode'] ?? null;

        // Honeypot: bots auto-fill hidden fields
        if (!empty($data['username'])) {
            $this->logger->warning('Login honeypot triggered.', ['email' => $email, 'ip' => $request->getClientIp()]);
            $this->loginBlockRepository->save(LoginBlock::create(
                ipAddress: $request->getClientIp() ?? 'unknown',
                email: $email,
                fieldValue: (string) $data['username'],
                userAgent: $request->headers->get('User-Agent', 'unknown'),
            ));
            throw new BadCredentialsException('Invalid credentials.');
        }

        if ($email === '' || $password === '') {
            throw new BadCredentialsException('Invalid credentials.');
        }

        $user = $this->userRepository->findByEmail(new Email($email));

        if ($user === null) {
            $this->logger->debug('Password authentication failed: user not found.', ['email' => $email]);
            throw new BadCredentialsException('Invalid credentials.');
        }

        if ($user->isDisabled()) {
            $this->logger->debug('Password authentication failed: user is disabled.', ['email' => $email]);
            throw new CustomUserMessageAuthenticationException('This account has been disabled.');
        }

        if (!$this->passwordHasher->verify($password, $user->getPassword())) {
            $this->logger->debug('Password authentication failed: invalid password.', ['email' => $email]);
            throw new BadCredentialsException('Invalid credentials.');
        }

        $totpSecret = $user->getTotpSecret();

        if ($totpSecret !== null && $totpSecret !== '') {
            if ($totpCode === null) {
                throw new CustomUserMessageAuthenticationException(
                    'TOTP code is required.',
                    ['totp_required' => true, 'error_code' => 'AUTH_TOTP_REQUIRED'],
                );
            }

            if (!$this->totpService->verifyCode($totpSecret, $totpCode)) {
                $this->logger->debug('Password authentication failed: invalid TOTP code.', ['email' => $email]);
                throw new BadCredentialsException('Invalid credentials.');
            }
        }

        return new SelfValidatingPassport(
            new UserBadge($user->getEmail(), fn (): SecurityUser => new SecurityUser(
                $user->getId()->toString(),
                $user->getEmail(),
                $user->getPassword(),
                $user->getRoles(),
            )),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $messageData = $exception instanceof CustomUserMessageAuthenticationException
            ? $exception->getMessageData()
            : [];

        $errorCode = $messageData['error_code'] ?? 'AUTH_INVALID_CREDENTIALS';
        $details = array_filter($messageData, fn (string $key): bool => $key !== 'error_code', ARRAY_FILTER_USE_KEY);

        $error = [
            'error' => [
                'message' => $exception->getMessageKey(),
                'code' => $errorCode,
            ],
        ];

        if (!empty($details)) {
            $error['error']['details'] = $details;
        }

        return new JsonResponse($error, Response::HTTP_UNAUTHORIZED);
    }
}

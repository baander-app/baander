<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Security\User;

use App\Auth\Application\DTO\VerifiedPasswordLogin;
use App\Auth\Application\Exception\DpopStoreUnavailableException;
use App\Auth\Application\Exception\TotpSecretUnavailableException;
use App\Auth\Application\Port\PasswordHasherInterface;
use App\Auth\Application\Port\TotpVerifierInterface;
use App\Auth\Domain\Model\LoginBlock;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\LoginBlockRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Auth\Infrastructure\Security\AuthenticationFailureMessage;
use App\Auth\Infrastructure\Security\OAuth\DpopTokenRequestVerifier;
use App\Auth\Infrastructure\Security\Passkey\DpopProofRejectedException;
use App\Auth\Infrastructure\Security\SecurityUser;
use App\Shared\Domain\Model\Email;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;

/**
 * Authenticates password login on the API firewall.
 *
 * The DPoP proof is checked before the credentials, so a nonce challenge leaves the
 * TOTP code unspent for the client's retry. The code is matched here and its time step
 * spent later, in the transaction that stores the tokens. On success it stores a
 * VerifiedPasswordLogin on the request; the login controller issues tokens only from it.
 */
final class PasswordAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly TotpVerifierInterface $totpVerifier,
        private readonly UserRepositoryInterface $userRepository,
        private readonly PasswordHasherInterface $passwordHasher,
        private readonly LoginBlockRepositoryInterface $loginBlockRepository,
        private readonly LoggerInterface $logger,
        private readonly JsonEncoder $jsonEncoder,
        private readonly AuthenticationFailureMessage $failureMessage,
        private readonly DpopTokenRequestVerifier $dpopVerifier,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request->getPathInfo() === '/api/auth/login'
            && $request->isMethod('POST');
    }

    /**
     * @throws DpopProofRejectedException when the proof is missing, stale or invalid
     * @throws ServiceUnavailableHttpException while the DPoP nonce store is unavailable
     */
    public function authenticate(Request $request): SelfValidatingPassport
    {
        [$proofKey, $nextNonce] = $this->verifyDpopProof($request);

        try {
            $data = $this->jsonEncoder->decode((string) $request->getContent(), 'json');
        } catch (NotEncodableValueException) {
            throw new BadCredentialsException(AuthenticationFailureMessage::INVALID_CREDENTIALS);
        }

        if (!is_array($data)) {
            throw new BadCredentialsException(AuthenticationFailureMessage::INVALID_CREDENTIALS);
        }

        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';
        $totpCode = $data['totpCode'] ?? null;

        if (!is_string($email) || !is_string($password)
            || ($totpCode !== null && !is_string($totpCode))
            || (isset($data['username']) && !is_string($data['username']))) {
            throw new BadCredentialsException(AuthenticationFailureMessage::INVALID_CREDENTIALS);
        }
        // An empty code is no code: the client is asked for one.
        $totpCode = $totpCode === '' ? null : $totpCode;

        // Honeypot: bots auto-fill hidden fields
        if (!empty($data['username'])) {
            $this->logger->warning('Login honeypot triggered.', ['email' => $email, 'ip' => $request->getClientIp()]);
            $this->loginBlockRepository->save(LoginBlock::create(
                ipAddress: $request->getClientIp() ?? 'unknown',
                email: $email,
                fieldValue: (string) $data['username'],
                userAgent: $request->headers->get('User-Agent', 'unknown'),
            ));
            throw new BadCredentialsException(AuthenticationFailureMessage::INVALID_CREDENTIALS);
        }

        if ($email === '' || $password === '') {
            throw new BadCredentialsException(AuthenticationFailureMessage::INVALID_CREDENTIALS);
        }

        try {
            $address = new Email($email);
        } catch (\InvalidArgumentException) {
            throw new BadCredentialsException(AuthenticationFailureMessage::INVALID_CREDENTIALS);
        }

        $user = $this->userRepository->findByEmail($address);

        if ($user === null) {
            $this->logger->debug('Password authentication failed: user not found.', ['email' => $email]);
            throw new BadCredentialsException(AuthenticationFailureMessage::INVALID_CREDENTIALS);
        }

        if ($user->isDisabled()) {
            $this->logger->debug('Password authentication failed: user is disabled.', ['email' => $email]);
            throw new CustomUserMessageAuthenticationException(AuthenticationFailureMessage::ACCOUNT_DISABLED);
        }

        if (!$this->passwordHasher->verify($password, $user->getPassword())) {
            $this->logger->debug('Password authentication failed: invalid password.', ['email' => $email]);
            throw new BadCredentialsException(AuthenticationFailureMessage::INVALID_CREDENTIALS);
        }

        $request->attributes->set(VerifiedPasswordLogin::class, new VerifiedPasswordLogin(
            $user->getId(),
            $proofKey,
            $nextNonce,
            $user->hasTotp() ? $this->matchTotpStep($user, $totpCode) : null,
        ));

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

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($exception instanceof DpopProofRejectedException) {
            return $exception->response;
        }

        $messageData = $exception instanceof CustomUserMessageAuthenticationException
            ? $exception->getMessageData()
            : [];

        $errorCode = $messageData['error_code'] ?? 'AUTH_INVALID_CREDENTIALS';
        $details = array_filter($messageData, fn (string $key): bool => $key !== 'error_code', ARRAY_FILTER_USE_KEY);

        $error = [
            'error' => [
                'message' => $this->failureMessage->of($exception, $request),
                'code' => $errorCode,
            ],
        ];

        if (!empty($details)) {
            $error['error']['details'] = $details;
        }

        return new JsonResponse($error, Response::HTTP_UNAUTHORIZED);
    }

    /**
     * The proof key's thumbprint and the nonce for the client's next proof, both settled
     * before the credentials are read.
     *
     * @return array{string, string}
     */
    private function verifyDpopProof(Request $request): array
    {
        try {
            $proofKey = $this->dpopVerifier->verify($request);
            if ($proofKey instanceof JsonResponse) {
                throw new DpopProofRejectedException($proofKey);
            }

            // Issued before the credentials are checked, so a store failure never spends the TOTP code.
            return [$proofKey, $this->dpopVerifier->issueNonce()];
        } catch (DpopStoreUnavailableException $exception) {
            // Not a credential failure: the store that logged the failure could not answer.
            // An HTTP exception leaves the firewall's failure handler out of it.
            throw new ServiceUnavailableHttpException(
                DpopStoreUnavailableException::RETRY_AFTER_SECONDS,
                'The DPoP store is unavailable.',
                $exception,
                headers: ['Cache-Control' => 'no-store'],
            );
        }
    }

    /**
     * The time step the code matches; the login controller spends it with the tokens.
     *
     * A secret the configured key cannot open never counts as TOTP being off.
     */
    private function matchTotpStep(User $user, ?string $code): int
    {
        if ($code === null) {
            throw new CustomUserMessageAuthenticationException(
                AuthenticationFailureMessage::TOTP_REQUIRED,
                ['totp_required' => true, 'error_code' => 'AUTH_TOTP_REQUIRED'],
            );
        }

        try {
            $step = $this->totpVerifier->matchEnrolledStep($user, $code);
        } catch (TotpSecretUnavailableException) {
            $this->logger->warning('TOTP login refused: the stored TOTP secret cannot be decrypted with AUTH_TOTP_ENCRYPTION_KEY. Reset the account\'s TOTP to let it sign in.', [
                'user_id' => $user->getPublicId()->toString(),
            ]);
            throw new CustomUserMessageAuthenticationException(
                AuthenticationFailureMessage::TOTP_UNAVAILABLE,
                ['error_code' => 'AUTH_TOTP_UNAVAILABLE'],
            );
        }

        if ($step === null) {
            $this->logger->debug('Password authentication failed: invalid TOTP code.', ['email' => $user->getEmail()]);
            throw new BadCredentialsException(AuthenticationFailureMessage::INVALID_CREDENTIALS);
        }

        return $step;
    }
}

<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Mail;

use App\Auth\Application\Port\PasswordResetDeliveryInterface;
use App\Auth\Domain\Model\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Emails the password reset link once the HTTP response has been sent.
 *
 * Sending inside the request would make the reset request slower for existing accounts than
 * for unknown addresses, which would reveal which addresses have accounts. The email is kept
 * in memory for the request and sent on kernel.terminate, which both the Swoole server and
 * PHP-FPM dispatch after the response has gone out. Without a current request (a console
 * command or a worker) it is sent at once.
 *
 * The email goes straight to the mailer transport rather than through MailerInterface, which
 * would dispatch it on the message bus: the raw token must never be serialized into a
 * Messenger transport or the failed-message table. A failed send is logged without the token
 * or the address and is not retried; the user can request a new link.
 */
final class MailerPasswordResetDelivery implements PasswordResetDeliveryInterface
{
    /** @var \WeakMap<Request, list<PasswordResetEmail>> */
    private \WeakMap $pending;

    public function __construct(
        private readonly TransportInterface $transport,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger,
        private readonly string $appUrl,
        private readonly string $appName,
    ) {
        $this->pending = new \WeakMap();
    }

    public function deliver(User $user, string $token, \DateTimeImmutable $expiresAt): void
    {
        // Capture the locale now: by kernel.terminate the request locale has been reset.
        $email = new PasswordResetEmail(
            userId: $user->getId()->toString(),
            address: $user->getEmail(),
            name: $user->getName(),
            link: sprintf('%s/reset-password#token=%s', rtrim($this->appUrl, '/'), rawurlencode($token)),
            validMinutes: max(1, (int) ceil(($expiresAt->getTimestamp() - time()) / 60)),
            locale: $this->translator->getLocale(),
        );

        $request = $this->requestStack->getMainRequest();
        if ($request === null) {
            $this->send($email);

            return;
        }

        $queued = $this->pending[$request] ?? [];
        $queued[] = $email;
        $this->pending[$request] = $queued;
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    public function onKernelTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        $queued = $this->pending[$request] ?? [];
        unset($this->pending[$request]);

        foreach ($queued as $email) {
            $this->send($email);
        }
    }

    private function send(PasswordResetEmail $email): void
    {
        try {
            $context = [
                'appName' => $this->appName,
                'name' => $email->name,
                'link' => $email->link,
                'validMinutes' => $email->validMinutes,
                'locale' => $email->locale,
            ];

            $message = (new Email())
                ->to(new Address($email->address, $email->name))
                ->subject($this->translator->trans('password_reset_email.subject', ['app' => $this->appName], 'auth', $email->locale))
                ->text($this->twig->render('email/auth/password_reset.txt.twig', $context))
                ->html($this->twig->render('email/auth/password_reset.html.twig', $context));

            $this->transport->send($message);
        } catch (\Throwable $e) {
            // Transport errors can quote the recipient, so log only the exception class.
            $this->logger->error('Password reset email could not be sent.', [
                'channel' => 'auth.password_reset',
                'userId' => $email->userId,
                'exceptionClass' => $e::class,
            ]);
        }
    }
}

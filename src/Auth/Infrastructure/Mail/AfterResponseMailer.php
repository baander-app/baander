<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Mail;

use App\Shared\Domain\Model\Setting\SupportedLanguages;
use App\UserPreference\Application\Port\UserSettingsContractInterface;
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
 * Sends emails that carry a credential once the HTTP response has been sent.
 *
 * Sending inside the request would make, for example, a password reset request slower for
 * existing accounts than for unknown addresses, which would reveal which addresses have
 * accounts; it would also make every registration wait for the mail server. The email is
 * kept in memory for the request and sent on kernel.terminate, which both the Swoole server
 * and PHP-FPM dispatch after the response has gone out. Without a current request (a console
 * command or a worker) it is sent at once.
 *
 * The recipient's language is read from their settings only when the email is sent, so the
 * request itself does no per-account work, and the subject and templates get that language
 * explicitly; the shared translator's locale is never changed.
 *
 * The email goes straight to the mailer transport rather than through MailerInterface, which
 * would dispatch it on the message bus: the credential must never be serialized into a
 * Messenger transport or the failed-message table. A failed send is logged without the
 * credential or the address and is not retried; the user asks for a new link.
 */
final class AfterResponseMailer
{
    /** @var \WeakMap<Request, list<CredentialEmail>> */
    private \WeakMap $pending;

    public function __construct(
        private readonly TransportInterface $transport,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
        private readonly UserSettingsContractInterface $settings,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger,
        private readonly string $appName,
    ) {
        $this->pending = new \WeakMap();
    }

    public function send(CredentialEmail $email): void
    {
        $request = $this->requestStack->getMainRequest();
        if ($request === null) {
            $this->sendNow($email);

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
            $this->sendNow($email);
        }
    }

    private function sendNow(CredentialEmail $email): void
    {
        $locale = $this->language($email);

        try {
            $context = [
                ...$email->context,
                'appName' => $this->appName,
                'name' => $email->name,
                'locale' => $locale,
            ];

            $message = (new Email())
                ->to(new Address($email->address, $email->name))
                ->subject($this->translator->trans($email->subjectKey, ['app' => $this->appName], 'auth', $locale))
                ->text($this->twig->render($email->template . '.txt.twig', $context))
                ->html($this->twig->render($email->template . '.html.twig', $context));

            $this->transport->send($message);
        } catch (\Throwable $e) {
            // Transport errors can quote the recipient, so log only the exception class.
            $this->logger->error($email->failureMessage, [
                'channel' => $email->logChannel,
                'userId' => $email->userId,
                'exceptionClass' => $e::class,
            ]);
        }
    }

    /**
     * The recipient's email language, read when the email is sent. An email that cannot
     * learn it is still worth sending, so a failed lookup falls back to English.
     */
    private function language(CredentialEmail $email): string
    {
        try {
            return $this->settings->resolveLanguage($email->userId);
        } catch (\Throwable $e) {
            // The exception message may quote the address or the link, so log only its class.
            $this->logger->warning('Email language could not be resolved; sending in English.', [
                'channel' => $email->logChannel,
                'userId' => $email->userId,
                'exceptionClass' => $e::class,
            ]);

            return SupportedLanguages::FALLBACK;
        }
    }
}

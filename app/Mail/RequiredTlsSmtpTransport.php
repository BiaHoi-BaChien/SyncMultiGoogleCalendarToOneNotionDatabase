<?php

namespace App\Mail;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

class RequiredTlsSmtpTransport extends EsmtpTransport
{
    private bool $ehloSucceeded = false;

    public function executeCommand(string $command, array $codes): string
    {
        $handshake = $codes === [250] && str_starts_with($command, 'HELO ');
        if ($handshake) {
            $this->ehloSucceeded = false;
            $this->setRequireTls(true);
        }

        $response = parent::executeCommand($command, $codes);
        if ($codes === [250] && str_starts_with($command, 'EHLO ')) {
            $this->ehloSucceeded = true;
        }

        // Symfony 7.4's HELO fallback returns before its require_tls check.
        // isTLS() describes implicit TLS only; successful STARTTLS is checked by Symfony.
        if ($handshake && !$this->ehloSucceeded && !$this->getStream()->isTLS()) {
            $this->getStream()->terminate();
            throw new TransportException('TLS is required; refusing plaintext SMTP HELO fallback.');
        }

        return $response;
    }
}

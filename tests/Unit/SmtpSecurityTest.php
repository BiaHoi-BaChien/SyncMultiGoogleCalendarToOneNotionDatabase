<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mime\Email;
use Tests\Support\Fakes\SmtpStreamFake;
use Tests\TestCase;

class SmtpSecurityTest extends TestCase
{
    #[DataProvider('unsafeServers')]
    public function test_it_rejects_plaintext_before_credentials_or_message_leave(bool $rejectEhlo, bool $advertiseTls, bool $tlsSucceeds): void
    {
        $transport = $this->transport('tls', 587);
        $stream = $this->stream($transport);
        $stream->rejectEhlo = $rejectEhlo;
        $stream->advertiseStartTls = $advertiseTls;
        $stream->tlsSucceeds = $tlsSucceeds;
        $failed = false;
        try { $transport->send($this->email()); } catch (TransportExceptionInterface) { $failed = true; }
        $this->assertTrue($failed, 'Insecure SMTP must fail.');
        foreach ($stream->writes as [$bytes, $encrypted]) {
            $this->assertDoesNotMatchRegularExpression('/^(AUTH |MAIL FROM:|RCPT TO:|DATA\r\n)/', $bytes);
            $this->assertStringNotContainsString('private-test-body', $bytes);
        }
    }

    public static function unsafeServers(): array
    {
        return ['stripped STARTTLS' => [false, false, true], 'HELO fallback' => [true, false, true], 'TLS handshake fails' => [false, true, false]];
    }

    #[DataProvider('secureModes')]
    public function test_it_preserves_authenticated_encrypted_delivery(string $encryption, int $port, bool $implicitTls): void
    {
        $transport = $this->transport($encryption, $port);
        $this->assertSame($implicitTls, $transport->getStream()->isTLS());
        $this->assertTrue($transport->isTlsRequired());
        $stream = $this->stream($transport, $implicitTls);
        $transport->send($this->email());
        $transcript = '';
        $authSeen = false;
        foreach ($stream->writes as [$bytes, $encrypted, $isCommand]) {
            $transcript .= $bytes;
            if (str_starts_with($bytes, 'AUTH ') || !$isCommand) {
                $this->assertTrue($encrypted);
                $authSeen = $authSeen || str_starts_with($bytes, 'AUTH ');
            }
        }
        $this->assertTrue($authSeen);
        $this->assertStringContainsString('private-test-body', $transcript);
        $transport->stop();
    }

    public static function secureModes(): array
    {
        return ['STARTTLS 587' => ['tls', 587, false], 'implicit 465' => ['ssl', 465, true], 'implicit custom port' => ['ssl', 2465, true]];
    }

    public function test_a_reconnect_cannot_reuse_a_previous_successful_handshake(): void
    {
        $transport = $this->transport('tls', 587);
        $stream = $this->stream($transport);
        $transport->send($this->email());
        $transport->stop();
        $stream->writes = [];
        $stream->rejectEhlo = true;
        $failed = false;
        try { $transport->send($this->email()); } catch (TransportExceptionInterface) { $failed = true; }
        $this->assertTrue($failed);
        foreach ($stream->writes as [$bytes]) {
            $this->assertDoesNotMatchRegularExpression('/^(AUTH |MAIL FROM:|RCPT TO:|DATA\r\n)/', $bytes);
        }
    }

    private function transport(string $encryption, int $port): EsmtpTransport
    {
        return $this->app->make('mail.manager')->build([
            'transport' => 'smtp', 'host' => 'smtp.example.test', 'port' => $port,
            'encryption' => $encryption, 'username' => 'offline-user', 'password' => 'offline-password',
        ])->getSymfonyTransport();
    }

    private function stream(EsmtpTransport $transport, bool $implicitTls = false): SmtpStreamFake
    {
        $stream = new SmtpStreamFake($implicitTls);
        (new ReflectionProperty(SmtpTransport::class, 'stream'))->setValue($transport, $stream);
        return $stream;
    }

    private function email(): Email
    {
        return (new Email())->from('sender@example.test')->to('recipient@example.test')->subject('offline')->text('private-test-body');
    }
}

<?php

namespace Tests\Support\Fakes;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\Stream\AbstractStream;

class SmtpStreamFake extends AbstractStream
{
    public array $writes = [];
    private array $responses = [];
    private bool $encrypted = false;
    public bool $rejectEhlo = false;
    public bool $advertiseStartTls = true;
    public bool $tlsSucceeds = true;

    public function __construct(private bool $implicitTls = false) {}

    public function initialize(): void
    {
        $this->encrypted = $this->implicitTls;
        $this->responses = ["220 test SMTP\r\n"];
    }

    public function isTLS(): bool { return $this->implicitTls; }

    public function startTLS(): bool
    {
        $this->encrypted = $this->tlsSucceeds;
        return $this->tlsSucceeds;
    }

    public function write(string $bytes, bool $debug = true): void
    {
        $this->writes[] = [$bytes, $this->encrypted, $debug];
        if (!$debug) { return; }
        $response = match (true) {
            str_starts_with($bytes, 'EHLO ') => $this->rejectEhlo
                ? "500 EHLO unsupported\r\n"
                : "250-test\r\n".(!$this->encrypted && $this->advertiseStartTls ? "250-STARTTLS\r\n" : '')."250 AUTH PLAIN\r\n",
            str_starts_with($bytes, 'HELO ') => "250 test\r\n",
            str_starts_with($bytes, 'STARTTLS') => "220 Ready\r\n",
            str_starts_with($bytes, 'AUTH PLAIN ') => "235 Authenticated\r\n",
            $bytes === "DATA\r\n" => "354 Send body\r\n",
            $bytes === "QUIT\r\n" => "221 Bye\r\n",
            default => "250 OK\r\n",
        };
        array_push($this->responses, ...array_map(fn ($line) => $line."\r\n", explode("\r\n", rtrim($response, "\r\n"))));
    }

    public function readLine(): string
    {
        return array_shift($this->responses) ?? throw new TransportException('Unexpected test read.');
    }

    public function flush(): void {}
    protected function getReadConnectionDescription(): string { return 'offline SMTP fake'; }
}

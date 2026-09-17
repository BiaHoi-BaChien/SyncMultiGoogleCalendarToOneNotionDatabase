<?php

namespace App\Providers;

use App\Mail\RequiredTlsSmtpTransport;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class MailServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make('mail.manager')->extend('smtp', function (array $config) {
            $port = (int) ($config['port'] ?? 0);
            $encryption = $config['encryption'] ?? 'tls';
            if (!in_array($encryption, ['ssl', 'tls'], true)) {
                throw new InvalidArgumentException('SMTP MAIL_ENCRYPTION must be ssl or tls.');
            }
            $scheme = $config['scheme'] ?? ($encryption === 'ssl' || $port === 465 ? 'smtps' : 'smtp');
            if (!in_array($scheme, ['smtp', 'smtps'], true)) {
                throw new InvalidArgumentException('Unsupported SMTP scheme.');
            }

            $transport = new RequiredTlsSmtpTransport($config['host'], $port, $scheme === 'smtps');
            $transport->setRequireTls(true);
            $transport->setUsername($config['username'] ?? '');
            $transport->setPassword($config['password'] ?? '');
            if (isset($config['local_domain'])) {
                $transport->setLocalDomain($config['local_domain']);
            }
            if (isset($config['timeout'])) {
                $transport->getStream()->setTimeout((float) $config['timeout']);
            }
            if (isset($config['source_ip'])) {
                $transport->getStream()->setSourceIp($config['source_ip']);
            }

            return $transport;
        });
    }
}

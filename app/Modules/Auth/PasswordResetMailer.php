<?php

namespace App\Modules\Auth;

class PasswordResetMailer
{
    private const VERSION = 1;

    public function send(array $profile, string $resetUrl, string $expiresAt): array
    {
        $toEmail = $this->cleanHeader((string) ($profile['email'] ?? ''));

        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'status' => 'invalid_recipient', 'transport' => 'none'];
        }

        $subject = 'MyTakii Intranet sifre sifirlama';
        $text = $this->messageBody($profile, $resetUrl, $expiresAt);
        $transport = strtolower((string) (getenv('PASSWORD_RESET_MAIL_TRANSPORT') ?: getenv('MAIL_TRANSPORT') ?: 'native'));
        $transport = in_array($transport, ['native', 'smtp', 'sendmail', 'outbox'], true) ? $transport : 'native';
        $entry = $this->entry($profile, $toEmail, $subject, $text, $transport);

        if ($transport === 'sendmail') {
            $sendmailResult = $this->sendSendmail($toEmail, $subject, $text);

            if ($sendmailResult['ok']) {
                $entry['status'] = 'sent';
                $entry['text'] = 'Password reset email sent via sendmail transport.';
                $entry['reset_url_stored'] = false;
                $this->appendOutbox($entry);

                return ['ok' => true, 'status' => 'sent', 'transport' => 'sendmail'];
            }

            $entry['status'] = 'queued';
            $entry['transport'] = 'outbox';
            $entry['sendmail_status'] = 'failed';
            $entry['sendmail_error'] = $this->safeError((string) ($sendmailResult['error'] ?? 'sendmail_failed'));
            $this->appendOutbox($entry);

            return ['ok' => true, 'status' => 'queued', 'transport' => 'outbox'];
        }

        if ($transport === 'smtp') {
            $smtpResult = $this->sendSmtp($toEmail, $subject, $text);

            if ($smtpResult['ok']) {
                $entry['status'] = 'sent';
                $entry['text'] = 'Password reset email sent via SMTP transport.';
                $entry['reset_url_stored'] = false;
                $this->appendOutbox($entry);

                return ['ok' => true, 'status' => 'sent', 'transport' => 'smtp'];
            }

            $entry['status'] = 'queued';
            $entry['transport'] = 'outbox';
            $entry['smtp_status'] = 'failed';
            $entry['smtp_error'] = $this->safeError((string) ($smtpResult['error'] ?? 'smtp_failed'));
            $this->appendOutbox($entry);

            return ['ok' => true, 'status' => 'queued', 'transport' => 'outbox'];
        }

        if ($transport === 'native') {
            $nativeResult = $this->sendNative($toEmail, $subject, $text);

            if ($nativeResult['ok']) {
                $entry['status'] = 'sent';
                $entry['transport'] = (string) ($nativeResult['transport'] ?? 'native');
                $entry['text'] = 'Password reset email sent via ' . $entry['transport'] . ' transport.';
                $entry['reset_url_stored'] = false;
                $this->appendOutbox($entry);

                return ['ok' => true, 'status' => 'sent', 'transport' => $entry['transport']];
            }

            $entry['status'] = 'queued';
            $entry['transport'] = 'outbox';
            $entry['native_status'] = 'failed';
            $entry['native_error'] = $this->safeError((string) ($nativeResult['error'] ?? 'mail_failed'));
            $this->appendOutbox($entry);

            return ['ok' => true, 'status' => 'queued', 'transport' => 'outbox'];
        }

        $this->appendOutbox($entry);

        return ['ok' => true, 'status' => 'queued', 'transport' => 'outbox'];
    }

    public function sendTemporaryPassword(array $profile, string $username, string $temporaryPassword): array
    {
        $toEmail = $this->cleanHeader((string) ($profile['email'] ?? ''));

        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'status' => 'invalid_recipient', 'transport' => 'none'];
        }

        $subject = 'MyTakii Intranet yeni giris sifreniz';
        $text = $this->temporaryPasswordBody($profile, $username, $temporaryPassword);
        $transport = strtolower((string) (getenv('PASSWORD_RESET_MAIL_TRANSPORT') ?: getenv('MAIL_TRANSPORT') ?: 'native'));
        $transport = in_array($transport, ['native', 'smtp', 'sendmail', 'outbox'], true) ? $transport : 'native';

        if ($transport === 'outbox') {
            return ['ok' => false, 'status' => 'not_sent', 'transport' => 'outbox', 'error' => 'mail_transport_outbox'];
        }

        $result = match ($transport) {
            'smtp' => $this->sendSmtp($toEmail, $subject, $text),
            'sendmail' => $this->sendSendmail($toEmail, $subject, $text),
            default => $this->sendNative($toEmail, $subject, $text),
        };

        if (!empty($result['ok'])) {
            return [
                'ok' => true,
                'status' => 'sent',
                'transport' => (string) ($result['transport'] ?? $transport),
            ];
        }

        return [
            'ok' => false,
            'status' => 'not_sent',
            'transport' => $transport,
            'error' => $this->safeError((string) ($result['error'] ?? 'mail_failed')),
        ];
    }

    private function messageBody(array $profile, string $resetUrl, string $expiresAt): string
    {
        $name = trim((string) ($profile['name'] ?? ''));
        $name = $name !== '' ? $name : 'Kullanici';

        return implode("\n", [
            'Merhaba ' . $name . ',',
            '',
            'MyTakii Intranet hesabi icin sifre sifirlama talebi aldik.',
            'Yeni sifrenizi belirlemek icin asagidaki baglantiyi acin:',
            '',
            $resetUrl,
            '',
            'Bu baglanti ' . $expiresAt . ' tarihine kadar gecerlidir.',
            'Bu talebi siz olusturmadiysaniz bu e-postayi dikkate almayin.',
            '',
            'MyTakii Intranet',
        ]);
    }

    private function temporaryPasswordBody(array $profile, string $username, string $temporaryPassword): string
    {
        $name = trim((string) ($profile['name'] ?? ''));
        $name = $name !== '' ? $name : 'Kullanici';
        $loginUrl = rtrim((string) (getenv('APP_URL') ?: 'https://takii.bigabilisim.com'), '/') . '/login';

        return implode("\n", [
            'Merhaba ' . $name . ',',
            '',
            'MyTakii Intranet hesabiniz icin yeni bir sifre olusturuldu.',
            'Kullanici adi: ' . $username,
            'Gecici sifre: ' . $temporaryPassword,
            '',
            'Giris adresi: ' . $loginUrl,
            'Bu bilgileri ucuncu kisilerle paylasmayin.',
            '',
            'MyTakii Intranet',
        ]);
    }

    private function entry(array $profile, string $toEmail, string $subject, string $text, string $transport): array
    {
        return [
            'id' => 'PRMAIL-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(3)), 0, 6),
            'to_email' => $toEmail,
            'to_name' => (string) ($profile['name'] ?? ''),
            'subject' => $subject,
            'text' => $text,
            'status' => 'queued',
            'transport' => $transport,
            'created_at' => date('Y-m-d H:i'),
        ];
    }

    private function sendNative(string $toEmail, string $subject, string $text): array
    {
        $fromAddress = $this->fromAddress();
        $fromName = $this->fromName();
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $fromName . ' <' . $fromAddress . '>',
            'Reply-To: ' . $fromAddress,
            'X-Takii-Password-Reset: true',
        ];
        $parameters = filter_var($fromAddress, FILTER_VALIDATE_EMAIL) ? '-f' . $fromAddress : '';
        $errors = [];

        if (function_exists('mail')) {
            $sent = $parameters !== ''
                ? mail($toEmail, $subject, $text, implode("\r\n", $headers), $parameters)
                : mail($toEmail, $subject, $text, implode("\r\n", $headers));

            if ($sent) {
                return ['ok' => true, 'transport' => 'native', 'error' => ''];
            }

            $errors[] = 'mail_returned_false';
        } else {
            $errors[] = 'mail_function_missing';
        }

        $sendmailResult = $this->sendSendmail($toEmail, $subject, $text);

        if ($sendmailResult['ok']) {
            return ['ok' => true, 'transport' => 'sendmail', 'error' => ''];
        }

        $errors[] = (string) ($sendmailResult['error'] ?? 'sendmail_failed');

        return ['ok' => false, 'transport' => 'outbox', 'error' => implode('; ', $errors)];
    }

    private function sendSendmail(string $toEmail, string $subject, string $text): array
    {
        if (!function_exists('proc_open')) {
            return ['ok' => false, 'error' => 'proc_open_missing'];
        }

        $path = trim((string) (getenv('SENDMAIL_PATH') ?: '/usr/sbin/sendmail'));

        if (!is_executable($path)) {
            $path = '/usr/lib/sendmail';
        }

        if (!is_executable($path)) {
            return ['ok' => false, 'error' => 'sendmail_binary_missing'];
        }

        $command = [$path, '-t', '-i', '-f' . $this->fromAddress()];
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($command, $descriptorSpec, $pipes);

        if (!is_resource($process)) {
            return ['ok' => false, 'error' => 'sendmail_start_failed'];
        }

        fwrite($pipes[0], $this->messagePayload($toEmail, $subject, $text, false));
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            return ['ok' => false, 'error' => 'sendmail_exit_' . $exitCode . '_' . trim((string) ($stderr ?: $stdout))];
        }

        return ['ok' => true, 'error' => ''];
    }

    private function sendSmtp(string $toEmail, string $subject, string $text): array
    {
        $host = trim((string) getenv('SMTP_HOST'));
        $username = trim((string) getenv('SMTP_USERNAME'));
        $password = (string) getenv('SMTP_PASSWORD');

        if ($host === '' || $username === '' || $password === '') {
            return ['ok' => false, 'error' => 'smtp_config_missing'];
        }

        $port = (int) (getenv('SMTP_PORT') ?: 587);
        $port = $port > 0 ? $port : 587;
        $encryption = strtolower((string) (getenv('SMTP_ENCRYPTION') ?: ($port === 465 ? 'ssl' : 'tls')));
        $encryption = $encryption === 'starttls' ? 'tls' : $encryption;
        $encryption = in_array($encryption, ['ssl', 'tls', 'none'], true) ? $encryption : 'tls';
        $timeout = (int) (getenv('SMTP_TIMEOUT') ?: 12);
        $timeout = $timeout > 0 ? $timeout : 12;
        $verifyPeer = filter_var(getenv('SMTP_VERIFY_PEER') ?: 'true', FILTER_VALIDATE_BOOLEAN);
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => $verifyPeer,
                'verify_peer_name' => $verifyPeer,
                'allow_self_signed' => !$verifyPeer,
            ],
        ]);
        $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $socket = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);

        if ($socket === false) {
            return ['ok' => false, 'error' => 'smtp_connect_failed_' . $errno . '_' . $errstr];
        }

        stream_set_timeout($socket, $timeout);
        $banner = $this->smtpRead($socket);

        if (!$this->smtpCodeIs($banner, [220])) {
            fclose($socket);

            return ['ok' => false, 'error' => 'smtp_banner_failed_' . $banner];
        }

        $ehloName = $this->smtpClientName();
        $response = $this->smtpCommand($socket, 'EHLO ' . $ehloName, [250]);

        if (!$response['ok']) {
            fclose($socket);

            return ['ok' => false, 'error' => 'smtp_ehlo_failed_' . $response['response']];
        }

        if ($encryption === 'tls') {
            $response = $this->smtpCommand($socket, 'STARTTLS', [220]);

            if (!$response['ok'] || !@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($socket);

                return ['ok' => false, 'error' => 'smtp_starttls_failed_' . $response['response']];
            }

            $response = $this->smtpCommand($socket, 'EHLO ' . $ehloName, [250]);

            if (!$response['ok']) {
                fclose($socket);

                return ['ok' => false, 'error' => 'smtp_tls_ehlo_failed_' . $response['response']];
            }
        }

        $response = $this->smtpCommand($socket, 'AUTH LOGIN', [334]);

        if (!$response['ok']) {
            fclose($socket);

            return ['ok' => false, 'error' => 'smtp_auth_start_failed_' . $response['response']];
        }

        $response = $this->smtpCommand($socket, base64_encode($username), [334]);

        if (!$response['ok']) {
            fclose($socket);

            return ['ok' => false, 'error' => 'smtp_auth_user_failed_' . $response['response']];
        }

        $response = $this->smtpCommand($socket, base64_encode($password), [235]);

        if (!$response['ok']) {
            fclose($socket);

            return ['ok' => false, 'error' => 'smtp_auth_password_failed_' . $response['response']];
        }

        $fromAddress = $this->fromAddress();
        $response = $this->smtpCommand($socket, 'MAIL FROM:<' . $fromAddress . '>', [250]);

        if (!$response['ok']) {
            fclose($socket);

            return ['ok' => false, 'error' => 'smtp_mail_from_failed_' . $response['response']];
        }

        $response = $this->smtpCommand($socket, 'RCPT TO:<' . $toEmail . '>', [250, 251]);

        if (!$response['ok']) {
            fclose($socket);

            return ['ok' => false, 'error' => 'smtp_rcpt_to_failed_' . $response['response']];
        }

        $response = $this->smtpCommand($socket, 'DATA', [354]);

        if (!$response['ok']) {
            fclose($socket);

            return ['ok' => false, 'error' => 'smtp_data_failed_' . $response['response']];
        }

        fwrite($socket, $this->messagePayload($toEmail, $subject, $text, true) . "\r\n.\r\n");
        $response = $this->smtpRead($socket);

        if (!$this->smtpCodeIs($response, [250])) {
            fclose($socket);

            return ['ok' => false, 'error' => 'smtp_send_failed_' . $response];
        }

        $this->smtpCommand($socket, 'QUIT', [221, 250]);
        fclose($socket);

        return ['ok' => true, 'error' => ''];
    }

    private function messagePayload(string $toEmail, string $subject, string $text, bool $dotStuff): string
    {
        $fromAddress = $this->fromAddress();
        $fromName = $this->fromName();
        $messageIdHost = preg_replace('/[^a-z0-9.-]/i', '', $this->smtpClientName()) ?: 'takii.local';
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $messageIdHost . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'From: ' . $this->formatAddress($fromAddress, $fromName),
            'To: <' . $toEmail . '>',
            'Subject: ' . $this->encodeHeader($subject),
            'X-Takii-Password-Reset: true',
        ];
        $body = str_replace(["\r\n", "\r"], "\n", $text);
        $body = $dotStuff ? str_replace("\n.", "\n..", $body) : $body;
        $body = str_replace("\n", "\r\n", $body);

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    private function smtpCommand($socket, string $command, array $expectedCodes): array
    {
        fwrite($socket, $command . "\r\n");
        $response = $this->smtpRead($socket);

        return [
            'ok' => $this->smtpCodeIs($response, $expectedCodes),
            'response' => trim($response),
        ];
    }

    private function smtpRead($socket): string
    {
        $response = '';

        while (!feof($socket)) {
            $line = fgets($socket, 515);

            if ($line === false) {
                break;
            }

            $response .= $line;

            if (preg_match('/^\d{3} /', $line) === 1) {
                break;
            }
        }

        return trim($response);
    }

    private function smtpCodeIs(string $response, array $expectedCodes): bool
    {
        return in_array((int) substr($response, 0, 3), $expectedCodes, true);
    }

    private function smtpClientName(): string
    {
        $host = (string) parse_url((string) getenv('APP_URL'), PHP_URL_HOST);

        return $host !== '' ? $host : 'takii.bigabilisim.com';
    }

    private function fromAddress(): string
    {
        $configured = $this->cleanHeader((string) getenv('MAIL_FROM_ADDRESS'));

        if (filter_var($configured, FILTER_VALIDATE_EMAIL)) {
            return $configured;
        }

        $host = $this->smtpClientName();
        $fallback = 'no-reply@' . $host;

        return filter_var($fallback, FILTER_VALIDATE_EMAIL) ? $fallback : 'no-reply@takii.bigabilisim.com';
    }

    private function fromName(): string
    {
        $fromName = $this->cleanHeader((string) (getenv('MAIL_FROM_NAME') ?: 'MyTakii Intranet'));

        return $fromName !== '' ? $fromName : 'MyTakii Intranet';
    }

    private function formatAddress(string $address, string $name): string
    {
        $safeName = str_replace(['\\', '"'], ['\\\\', '\\"'], $name);

        return '"' . $safeName . '" <' . $address . '>';
    }

    private function encodeHeader(string $value): string
    {
        if (preg_match('/[^\x20-\x7E]/', $value) !== 1) {
            return $value;
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function safeError(string $error): string
    {
        $error = preg_replace('/\s+/', ' ', $error) ?: 'unknown';

        return substr($error, 0, 240);
    }

    private function appendOutbox(array $entry): void
    {
        $path = $this->outboxPath();
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $decoded = $this->loadOutbox();
        $outbox = is_array($decoded) && is_array($decoded['messages'] ?? null)
            ? $decoded
            : ['version' => self::VERSION, 'messages' => []];
        $outbox['version'] = self::VERSION;
        $outbox['messages'][] = $entry;

        file_put_contents($path, json_encode($outbox, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function loadOutbox(): array
    {
        $path = $this->outboxPath();

        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function outboxPath(): string
    {
        return (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3)) . '/storage/password-reset-mail-outbox.json';
    }

    private function cleanHeader(string $value): string
    {
        return trim(str_replace(["\r", "\n"], '', $value));
    }
}

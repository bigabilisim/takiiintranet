<?php

namespace App\Modules\Notifications;

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;

class PushNotificationStore
{
    private const SUBSCRIPTION_VERSION = 1;

    public function publicKey(): string
    {
        return $this->vapidKeys()['publicKey'];
    }

    public function subscribe(string $email, array $subscription): array
    {
        if ($email === '' || empty($subscription['endpoint']) || empty($subscription['keys']['p256dh']) || empty($subscription['keys']['auth'])) {
            return ['ok' => false, 'message' => 'push.flash.invalid_subscription'];
        }

        $data = $this->subscriptionsData();
        $endpoint = (string) $subscription['endpoint'];
        $data['subscriptions'][$this->endpointKey($endpoint)] = [
            'user_email' => $email,
            'endpoint' => $endpoint,
            'keys' => [
                'p256dh' => (string) $subscription['keys']['p256dh'],
                'auth' => (string) $subscription['keys']['auth'],
            ],
            'contentEncoding' => 'aes128gcm',
            'created_at' => date('Y-m-d H:i'),
            'last_seen_at' => date('Y-m-d H:i'),
        ];

        $this->saveSubscriptionsData($data);

        return ['ok' => true, 'message' => 'push.flash.subscribed'];
    }

    public function unsubscribe(string $email, array $subscription): array
    {
        $endpoint = (string) ($subscription['endpoint'] ?? '');
        $data = $this->subscriptionsData();
        $key = $this->endpointKey($endpoint);

        if ($endpoint !== '' && isset($data['subscriptions'][$key]) && $data['subscriptions'][$key]['user_email'] === $email) {
            unset($data['subscriptions'][$key]);
            $this->saveSubscriptionsData($data);
        }

        return ['ok' => true, 'message' => 'push.flash.unsubscribed'];
    }

    public function sendToUser(string $email, array $payload): array
    {
        $subscriptions = array_values(array_filter(
            $this->subscriptionsData()['subscriptions'],
            fn (array $subscription): bool => ($subscription['user_email'] ?? '') === $email
        ));

        if (count($subscriptions) === 0) {
            return ['ok' => true, 'sent' => 0, 'failed' => 0, 'message' => 'push.flash.no_subscription'];
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => $this->vapidSubject(),
                'publicKey' => $this->vapidKeys()['publicKey'],
                'privateKey' => $this->vapidKeys()['privateKey'],
            ],
        ]);

        $sent = 0;
        $failed = 0;
        $data = $this->subscriptionsData();
        $dirty = false;

        foreach ($subscriptions as $subscriptionData) {
            $endpoint = (string) ($subscriptionData['endpoint'] ?? '');
            $subscriptionKey = $this->endpointKey($endpoint);

            try {
                $subscription = Subscription::create($subscriptionData);
                $report = $webPush->sendOneNotification($subscription, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } catch (\Throwable) {
                $failed++;
                continue;
            }

            if ($report->isSuccess()) {
                $sent++;
            } else {
                $failed++;

                if ($report->isSubscriptionExpired() && isset($data['subscriptions'][$subscriptionKey])) {
                    unset($data['subscriptions'][$subscriptionKey]);
                    $dirty = true;
                }
            }
        }

        if ($dirty) {
            $this->saveSubscriptionsData($data);
        }

        return [
            'ok' => $failed === 0,
            'sent' => $sent,
            'failed' => $failed,
            'message' => $failed === 0 ? 'push.flash.sent' : 'push.flash.partial',
        ];
    }

    private function vapidKeys(): array
    {
        $publicKey = getenv('VAPID_PUBLIC_KEY') ?: '';
        $privateKey = getenv('VAPID_PRIVATE_KEY') ?: '';

        if ($publicKey !== '' && $privateKey !== '') {
            return ['publicKey' => $publicKey, 'privateKey' => $privateKey];
        }

        $path = $this->vapidPath();

        if (is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);

            if (is_array($decoded) && !empty($decoded['publicKey']) && !empty($decoded['privateKey'])) {
                return $decoded;
            }
        }

        $keys = VAPID::createVapidKeys();
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($path, json_encode($keys, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $keys;
    }

    private function vapidSubject(): string
    {
        $subject = getenv('VAPID_SUBJECT') ?: getenv('APP_URL') ?: '';

        if ($subject !== '') {
            return $subject;
        }

        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');

        return $host !== '' ? 'https://' . $host : 'mailto:webpush@takii.bigabilisim.com';
    }

    private function subscriptionsData(): array
    {
        $path = $this->subscriptionsPath();

        if (!is_file($path)) {
            return ['version' => self::SUBSCRIPTION_VERSION, 'subscriptions' => []];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (!is_array($decoded) || ($decoded['version'] ?? null) !== self::SUBSCRIPTION_VERSION || !isset($decoded['subscriptions'])) {
            return ['version' => self::SUBSCRIPTION_VERSION, 'subscriptions' => []];
        }

        return $decoded;
    }

    private function saveSubscriptionsData(array $data): void
    {
        $path = $this->subscriptionsPath();
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function endpointKey(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    private function subscriptionsPath(): string
    {
        return (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3)) . '/storage/push-subscriptions.json';
    }

    private function vapidPath(): string
    {
        return (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3)) . '/storage/vapid.json';
    }
}

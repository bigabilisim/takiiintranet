<?php

namespace App\Modules\Notifications;

use App\Core\StateStore;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;

class PushNotificationStore
{
    private const SUBSCRIPTION_VERSION = 1;
    private const SUBSCRIPTIONS_STATE_KEY = 'push_subscriptions';
    private const VAPID_STATE_KEY = 'vapid_keys';

    public function __construct(private readonly StateStore $stateStore)
    {
    }

    public function publicKey(): string
    {
        return $this->vapidKeys()['publicKey'];
    }

    public function subscribe(string $email, array $subscription): array
    {
        $writeGuard = $this->stateStore->beginWrite(self::SUBSCRIPTIONS_STATE_KEY, $this->subscriptionsPath());

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
        $writeGuard = $this->stateStore->beginWrite(self::SUBSCRIPTIONS_STATE_KEY, $this->subscriptionsPath());
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
        $expiredSubscriptionKeys = [];

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

                if ($report->isSubscriptionExpired()) {
                    $expiredSubscriptionKeys[] = $subscriptionKey;
                }
            }
        }

        if ($expiredSubscriptionKeys !== []) {
            $writeGuard = $this->stateStore->beginWrite(self::SUBSCRIPTIONS_STATE_KEY, $this->subscriptionsPath());
            $data = $this->subscriptionsData();

            foreach (array_unique($expiredSubscriptionKeys) as $subscriptionKey) {
                unset($data['subscriptions'][$subscriptionKey]);
            }

            $this->saveSubscriptionsData($data);
        }

        return [
            'ok' => $failed === 0,
            'sent' => $sent,
            'failed' => $failed,
            'message' => $failed === 0 ? 'push.flash.sent' : 'push.flash.partial',
        ];
    }

    public function migrateUserIdentity(string $oldIdentity, string $newIdentity): int
    {
        if ($oldIdentity === '' || $newIdentity === '' || $oldIdentity === $newIdentity) {
            return 0;
        }

        $writeGuard = $this->stateStore->beginWrite(self::SUBSCRIPTIONS_STATE_KEY, $this->subscriptionsPath());
        $data = $this->subscriptionsData();
        $migrated = 0;

        foreach ($data['subscriptions'] as $key => $subscription) {
            if ((string) ($subscription['user_email'] ?? '') !== $oldIdentity) {
                continue;
            }

            $data['subscriptions'][$key]['user_email'] = $newIdentity;
            $data['subscriptions'][$key]['last_seen_at'] = date('Y-m-d H:i');
            $migrated++;
        }

        if ($migrated > 0) {
            $this->saveSubscriptionsData($data);
        }

        return $migrated;
    }

    private function vapidKeys(): array
    {
        $publicKey = getenv('VAPID_PUBLIC_KEY') ?: '';
        $privateKey = getenv('VAPID_PRIVATE_KEY') ?: '';

        if ($publicKey !== '' && $privateKey !== '') {
            return ['publicKey' => $publicKey, 'privateKey' => $privateKey];
        }

        $path = $this->vapidPath();
        $keys = $this->stateStore->read(self::VAPID_STATE_KEY, $path);

        if (!empty($keys['publicKey']) && !empty($keys['privateKey'])) {
            return $keys;
        }

        $writeGuard = $this->stateStore->beginWrite(self::VAPID_STATE_KEY, $path);
        $keys = $this->stateStore->read(self::VAPID_STATE_KEY, $path);

        if (!empty($keys['publicKey']) && !empty($keys['privateKey'])) {
            return $keys;
        }

        $keys = VAPID::createVapidKeys();
        $this->stateStore->write(self::VAPID_STATE_KEY, $path, $keys);

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
        $decoded = $this->stateStore->read(
            self::SUBSCRIPTIONS_STATE_KEY,
            $this->subscriptionsPath(),
            ['version' => self::SUBSCRIPTION_VERSION, 'subscriptions' => []]
        );

        if (($decoded['version'] ?? null) !== self::SUBSCRIPTION_VERSION || !is_array($decoded['subscriptions'] ?? null)) {
            return ['version' => self::SUBSCRIPTION_VERSION, 'subscriptions' => []];
        }

        return $decoded;
    }

    private function saveSubscriptionsData(array $data): void
    {
        $this->stateStore->write(self::SUBSCRIPTIONS_STATE_KEY, $this->subscriptionsPath(), $data);
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

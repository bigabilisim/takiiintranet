<?php
$currentMessageUrl = '/module/messages' . ($selectedRecipient !== '' ? '?thread=' . urlencode($selectedRecipient) : '');
$initials = function (string $name): string {
    $letters = '';
    $parts = preg_split('/\s+/', trim($name)) ?: [];

    foreach (array_slice($parts, 0, 2) as $part) {
        if ($part !== '') {
            $letters .= strtoupper(substr($part, 0, 1));
        }
    }

    return $letters !== '' ? $letters : 'MS';
};
?>

<section class="page-header">
    <div>
        <p class="eyebrow"><?= htmlspecialchars($t('messages.eyebrow'), ENT_QUOTES, 'UTF-8') ?></p>
        <h1><?= htmlspecialchars($t('messages.title'), ENT_QUOTES, 'UTF-8') ?></h1>
    </div>
    <div class="module-badge" style="--accent: #3f7f92">
        <?= htmlspecialchars($t('messages.summary', ['count' => $unreadCount]), ENT_QUOTES, 'UTF-8') ?>
    </div>
</section>

<?php if ($flashSuccess): ?>
    <p class="alert success"><?= htmlspecialchars($t($flashSuccess), ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<?php if ($flashError): ?>
    <p class="alert"><?= htmlspecialchars($t($flashError), ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<section class="message-insights">
    <div>
        <span><?= htmlspecialchars($t('messages.insight.unread'), ENT_QUOTES, 'UTF-8') ?></span>
        <strong><?= htmlspecialchars((string) $unreadCount, ENT_QUOTES, 'UTF-8') ?></strong>
    </div>
    <div>
        <span><?= htmlspecialchars($t('messages.insight.conversations'), ENT_QUOTES, 'UTF-8') ?></span>
        <strong><?= htmlspecialchars((string) $conversationCount, ENT_QUOTES, 'UTF-8') ?></strong>
    </div>
    <div>
        <span><?= htmlspecialchars($t('messages.insight.quick'), ENT_QUOTES, 'UTF-8') ?></span>
        <strong><?= htmlspecialchars((string) $quickContactCount, ENT_QUOTES, 'UTF-8') ?></strong>
    </div>
    <?php if ($canManageDeletedMessages): ?>
        <div>
            <span><?= htmlspecialchars($t('messages.insight.deleted'), ENT_QUOTES, 'UTF-8') ?></span>
            <strong><?= htmlspecialchars((string) count($deletedMessages), ENT_QUOTES, 'UTF-8') ?></strong>
        </div>
    <?php endif; ?>
</section>

<section class="messages-layout">
    <div class="message-compose-panel">
        <div class="section-title">
            <h2><?= htmlspecialchars($t('messages.compose'), ENT_QUOTES, 'UTF-8') ?></h2>
            <span><?= htmlspecialchars($t('messages.compose_hint'), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <?php if ($activeContact): ?>
            <div class="selected-contact-card">
                <span class="message-avatar"><?= htmlspecialchars($initials($activeContact['name']), ENT_QUOTES, 'UTF-8') ?></span>
                <span>
                    <small><?= htmlspecialchars($t('messages.selected_contact'), ENT_QUOTES, 'UTF-8') ?></small>
                    <strong><?= htmlspecialchars($activeContact['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                </span>
            </div>
        <?php endif; ?>
        <?php if ($canSend): ?>
            <form class="message-form" method="post" action="/messages/send">
                <?= $csrf() ?>
                <label>
                    <span><?= htmlspecialchars($t('messages.to'), ENT_QUOTES, 'UTF-8') ?></span>
                    <select name="to_email" required>
                        <?php foreach ($recipients as $recipient): ?>
                            <option value="<?= htmlspecialchars($recipient['email'], ENT_QUOTES, 'UTF-8') ?>" <?= $selectedRecipient === $recipient['email'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($recipient['name'] . ' / ' . $recipient['department'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span><?= htmlspecialchars($t('messages.subject'), ENT_QUOTES, 'UTF-8') ?></span>
                    <input type="text" name="subject" maxlength="160" required>
                </label>
                <label>
                    <span><?= htmlspecialchars($t('messages.body'), ENT_QUOTES, 'UTF-8') ?></span>
                    <textarea name="body" rows="8" maxlength="2000" required></textarea>
                </label>
                <button class="button primary" type="submit"><?= htmlspecialchars($t('messages.send'), ENT_QUOTES, 'UTF-8') ?></button>
            </form>
        <?php else: ?>
            <div class="empty-inline"><?= htmlspecialchars($t('messages.no_send_permission'), ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <div class="quick-contact-panel">
            <div>
                <strong><?= htmlspecialchars($t('messages.quick_contacts'), ENT_QUOTES, 'UTF-8') ?></strong>
                <small><?= htmlspecialchars($t('messages.quick_hint'), ENT_QUOTES, 'UTF-8') ?></small>
            </div>
            <div class="quick-contact-list">
                <?php foreach ($quickContacts as $contact): ?>
                    <a class="quick-contact <?= $selectedRecipient === $contact['email'] ? 'is-active' : '' ?>" href="/module/messages?thread=<?= htmlspecialchars($contact['email'], ENT_QUOTES, 'UTF-8') ?>">
                        <span class="message-avatar"><?= htmlspecialchars($initials($contact['name']), ENT_QUOTES, 'UTF-8') ?></span>
                        <span>
                            <strong><?= htmlspecialchars($contact['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <small><?= htmlspecialchars($t($contact['reason_key']), ENT_QUOTES, 'UTF-8') ?> / <?= htmlspecialchars($contact['department'], ENT_QUOTES, 'UTF-8') ?></small>
                        </span>
                    </a>
                <?php endforeach; ?>
                <?php if (count($quickContacts) === 0): ?>
                    <div class="empty-inline"><?= htmlspecialchars($t('messages.no_quick_contacts'), ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="message-list-panel">
        <div class="section-title">
            <h2><?= htmlspecialchars($t('messages.conversations'), ENT_QUOTES, 'UTF-8') ?></h2>
        </div>
        <div class="conversation-list">
            <?php foreach ($conversations as $conversation): ?>
                <article class="conversation-card <?= $conversation['is_pinned'] ? 'is-pinned' : '' ?> <?= $selectedRecipient === $conversation['email'] ? 'is-active' : '' ?>">
                    <a href="/module/messages?thread=<?= htmlspecialchars($conversation['email'], ENT_QUOTES, 'UTF-8') ?>">
                        <span class="message-avatar"><?= htmlspecialchars($initials($conversation['name']), ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="conversation-copy">
                            <strong><?= htmlspecialchars($conversation['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <span><?= htmlspecialchars($conversation['latest_subject'], ENT_QUOTES, 'UTF-8') ?></span>
                            <small><?= htmlspecialchars($conversation['department'] . ' / ' . $conversation['latest_at'], ENT_QUOTES, 'UTF-8') ?></small>
                        </span>
                    </a>
                    <div class="conversation-actions">
                        <?php if ($conversation['unread_count'] > 0): ?>
                            <em><?= htmlspecialchars($t('messages.unread_badge', ['count' => $conversation['unread_count']]), ENT_QUOTES, 'UTF-8') ?></em>
                        <?php endif; ?>
                        <form method="post" action="/messages/pins">
                            <?= $csrf() ?>
                            <input type="hidden" name="counterpart_email" value="<?= htmlspecialchars($conversation['email'], ENT_QUOTES, 'UTF-8') ?>">
                            <button class="button compact" type="submit">
                                <?= htmlspecialchars($t($conversation['is_pinned'] ? 'messages.unpin' : 'messages.pin'), ENT_QUOTES, 'UTF-8') ?>
                            </button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
            <?php if (count($conversations) === 0): ?>
                <div class="empty-inline"><?= htmlspecialchars($t('messages.no_conversations'), ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
        </div>

        <div class="section-title">
            <h2><?= htmlspecialchars($t('messages.thread'), ENT_QUOTES, 'UTF-8') ?></h2>
        </div>
        <?php if ($activeContact): ?>
            <section class="thread-panel">
                <header>
                    <div class="thread-contact">
                        <span class="message-avatar"><?= htmlspecialchars($initials($activeContact['name']), ENT_QUOTES, 'UTF-8') ?></span>
                        <span>
                            <strong><?= htmlspecialchars($activeContact['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <span><?= htmlspecialchars($activeContact['role'] . ' / ' . $activeContact['department'], ENT_QUOTES, 'UTF-8') ?></span>
                        </span>
                    </div>
                    <div class="thread-actions">
                        <?php if ($activeConversationUnreadCount > 0): ?>
                            <form method="post" action="/messages/threads/read">
                                <?= $csrf() ?>
                                <input type="hidden" name="counterpart_email" value="<?= htmlspecialchars($activeContact['email'], ENT_QUOTES, 'UTF-8') ?>">
                                <button class="button compact" type="submit">
                                    <?= htmlspecialchars($t('messages.mark_thread_read'), ENT_QUOTES, 'UTF-8') ?>
                                </button>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="/messages/pins">
                            <?= $csrf() ?>
                            <input type="hidden" name="counterpart_email" value="<?= htmlspecialchars($activeContact['email'], ENT_QUOTES, 'UTF-8') ?>">
                            <button class="button compact" type="submit">
                                <?= htmlspecialchars($t((count($threadMessages) > 0 && $threadMessages[0]['is_pinned']) ? 'messages.unpin' : 'messages.pin'), ENT_QUOTES, 'UTF-8') ?>
                            </button>
                        </form>
                    </div>
                </header>
                <?php if ($canSend): ?>
                    <form class="thread-reply-form" method="post" action="/messages/send">
                        <?= $csrf() ?>
                        <input type="hidden" name="to_email" value="<?= htmlspecialchars($activeContact['email'], ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="subject" value="<?= htmlspecialchars($replySubject, ENT_QUOTES, 'UTF-8') ?>">
                        <label>
                            <span><?= htmlspecialchars($t('messages.reply'), ENT_QUOTES, 'UTF-8') ?></span>
                            <textarea name="body" rows="4" maxlength="2000" required></textarea>
                        </label>
                        <button class="button primary" type="submit"><?= htmlspecialchars($t('messages.send_reply'), ENT_QUOTES, 'UTF-8') ?></button>
                    </form>
                <?php endif; ?>
                <div class="thread-messages">
                    <?php foreach ($threadMessages as $threadMessage): ?>
                        <article class="thread-bubble <?= $threadMessage['is_mine'] ? 'is-mine' : 'is-theirs' ?> <?= !empty($threadMessage['is_unread']) ? 'is-unread' : '' ?>">
                            <div class="thread-bubble-head">
                                <span><?= htmlspecialchars($threadMessage['speaker_name'] . ' / ' . $threadMessage['created_at'], ENT_QUOTES, 'UTF-8') ?></span>
                                <form method="post" action="/messages/<?= htmlspecialchars($threadMessage['id'], ENT_QUOTES, 'UTF-8') ?>/delete">
                                    <?= $csrf() ?>
                                    <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($currentMessageUrl, ENT_QUOTES, 'UTF-8') ?>">
                                    <button class="button compact danger" type="submit"><?= htmlspecialchars($t('messages.delete'), ENT_QUOTES, 'UTF-8') ?></button>
                                </form>
                            </div>
                            <strong><?= htmlspecialchars($threadMessage['subject'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <p><?= nl2br(htmlspecialchars($threadMessage['body'], ENT_QUOTES, 'UTF-8')) ?></p>
                        </article>
                    <?php endforeach; ?>
                    <?php if (count($threadMessages) === 0): ?>
                        <div class="empty-inline"><?= htmlspecialchars($t('messages.thread_empty'), ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                </div>
            </section>
        <?php else: ?>
            <div class="empty-inline"><?= htmlspecialchars($t('messages.thread_select'), ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="section-title">
            <h2><?= htmlspecialchars($t('messages.inbox'), ENT_QUOTES, 'UTF-8') ?></h2>
        </div>
        <div class="message-list">
            <?php foreach ($inbox as $message): ?>
                <article class="message-card <?= empty($message['read_at']) ? 'is-unread' : '' ?>">
                    <header>
                        <div>
                            <strong><?= htmlspecialchars($message['subject'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <span><?= htmlspecialchars($t('messages.from', ['name' => $message['from_name']]), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <time><?= htmlspecialchars($message['created_at'], ENT_QUOTES, 'UTF-8') ?></time>
                    </header>
                    <p><?= nl2br(htmlspecialchars($message['body'], ENT_QUOTES, 'UTF-8')) ?></p>
                    <div class="message-card-actions">
                        <form method="post" action="/messages/pins">
                            <?= $csrf() ?>
                            <input type="hidden" name="counterpart_email" value="<?= htmlspecialchars((string) $message['counterpart_email'], ENT_QUOTES, 'UTF-8') ?>">
                            <button class="button compact" type="submit">
                                <?= htmlspecialchars($t($message['is_pinned'] ? 'messages.unpin' : 'messages.pin'), ENT_QUOTES, 'UTF-8') ?>
                            </button>
                        </form>
                        <?php if (empty($message['read_at'])): ?>
                            <form method="post" action="/messages/<?= htmlspecialchars($message['id'], ENT_QUOTES, 'UTF-8') ?>/read">
                                <?= $csrf() ?>
                                <button class="button compact" type="submit"><?= htmlspecialchars($t('messages.mark_read'), ENT_QUOTES, 'UTF-8') ?></button>
                            </form>
                        <?php else: ?>
                            <small><?= htmlspecialchars($t('messages.read_at', ['time' => $message['read_at']]), ENT_QUOTES, 'UTF-8') ?></small>
                        <?php endif; ?>
                        <form method="post" action="/messages/<?= htmlspecialchars($message['id'], ENT_QUOTES, 'UTF-8') ?>/delete">
                            <?= $csrf() ?>
                            <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($currentMessageUrl, ENT_QUOTES, 'UTF-8') ?>">
                            <button class="button compact danger" type="submit"><?= htmlspecialchars($t('messages.delete'), ENT_QUOTES, 'UTF-8') ?></button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
            <?php if (count($inbox) === 0): ?>
                <div class="empty-inline"><?= htmlspecialchars($t('messages.empty_inbox'), ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
        </div>

        <div class="section-title message-sent-title">
            <h2><?= htmlspecialchars($t('messages.sent'), ENT_QUOTES, 'UTF-8') ?></h2>
        </div>
        <div class="message-list is-sent">
            <?php foreach ($sent as $message): ?>
                <article class="message-card">
                    <header>
                        <div>
                            <strong><?= htmlspecialchars($message['subject'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <span><?= htmlspecialchars($t('messages.to_name', ['name' => $message['to_name']]), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <time><?= htmlspecialchars($message['created_at'], ENT_QUOTES, 'UTF-8') ?></time>
                    </header>
                    <p><?= nl2br(htmlspecialchars($message['body'], ENT_QUOTES, 'UTF-8')) ?></p>
                    <div class="message-card-actions">
                        <form method="post" action="/messages/pins">
                            <?= $csrf() ?>
                            <input type="hidden" name="counterpart_email" value="<?= htmlspecialchars((string) $message['counterpart_email'], ENT_QUOTES, 'UTF-8') ?>">
                            <button class="button compact" type="submit">
                                <?= htmlspecialchars($t($message['is_pinned'] ? 'messages.unpin' : 'messages.pin'), ENT_QUOTES, 'UTF-8') ?>
                            </button>
                        </form>
                        <form method="post" action="/messages/<?= htmlspecialchars($message['id'], ENT_QUOTES, 'UTF-8') ?>/delete">
                            <?= $csrf() ?>
                            <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($currentMessageUrl, ENT_QUOTES, 'UTF-8') ?>">
                            <button class="button compact danger" type="submit"><?= htmlspecialchars($t('messages.delete'), ENT_QUOTES, 'UTF-8') ?></button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
            <?php if (count($sent) === 0): ?>
                <div class="empty-inline"><?= htmlspecialchars($t('messages.empty_sent'), ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
        </div>

        <?php if ($canManageDeletedMessages): ?>
            <div class="section-title message-sent-title">
                <h2><?= htmlspecialchars($t('messages.deleted_pool'), ENT_QUOTES, 'UTF-8') ?></h2>
                <span><?= htmlspecialchars($t('messages.pool_count', ['count' => count($deletedMessages)]), ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="deleted-message-pool">
                <?php foreach ($deletedMessages as $message): ?>
                    <article class="deleted-message-card">
                        <header>
                            <div>
                                <strong><?= htmlspecialchars($message['subject'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <span><?= htmlspecialchars($message['from_label'] . ' -> ' . $message['to_label'], ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <time><?= htmlspecialchars((string) $message['deleted_at'], ENT_QUOTES, 'UTF-8') ?></time>
                        </header>
                        <p><?= nl2br(htmlspecialchars($message['body'], ENT_QUOTES, 'UTF-8')) ?></p>
                        <div class="message-card-actions">
                            <small>
                                <?= htmlspecialchars($t('messages.deleted_meta', [
                                    'time' => (string) $message['deleted_at'],
                                    'name' => (string) $message['deleted_by_label'],
                                ]), ENT_QUOTES, 'UTF-8') ?>
                            </small>
                            <form method="post" action="/messages/<?= htmlspecialchars($message['id'], ENT_QUOTES, 'UTF-8') ?>/restore">
                                <?= $csrf() ?>
                                <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($currentMessageUrl, ENT_QUOTES, 'UTF-8') ?>">
                                <button class="button compact approve" type="submit"><?= htmlspecialchars($t('messages.restore'), ENT_QUOTES, 'UTF-8') ?></button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
                <?php if (count($deletedMessages) === 0): ?>
                    <div class="empty-inline"><?= htmlspecialchars($t('messages.empty_deleted_pool'), ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

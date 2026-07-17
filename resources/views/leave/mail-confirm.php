<?php
$request = is_array($result['request'] ?? null) ? $result['request'] : [];
$isApproval = ($kind ?? 'approval') === 'approval';
$isReject = $isApproval && ($decision ?? '') === 'reject';
$decisionLabel = $isApproval
    ? ($isReject ? 'leave.mail.confirm_reject' : 'leave.mail.confirm_approve')
    : (($decision ?? '') === 'signed' ? 'leave.mail.confirm_signed' : 'leave.mail.confirm_not_signed');
?>

<section class="empty-state mail-decision-confirm">
    <span class="module-code">LV</span>
    <h1><?= htmlspecialchars($t($isApproval ? 'leave.mail.confirm_title' : 'leave.mail.signature_confirm_title'), ENT_QUOTES, 'UTF-8') ?></h1>
    <p><?= htmlspecialchars($t((string) ($result['message'] ?? 'leave.mail.confirm_prompt')), ENT_QUOTES, 'UTF-8') ?></p>
    <p class="mail-result-meta">
        <?= htmlspecialchars((string) ($request['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
        &middot; <?= htmlspecialchars((string) ($request['requester'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
        &middot; <?= htmlspecialchars((string) ($request['starts_on'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
        - <?= htmlspecialchars((string) ($request['ends_on'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
    </p>

    <form method="post" action="<?= htmlspecialchars((string) $action, ENT_QUOTES, 'UTF-8') ?>" autocomplete="off">
        <?= $csrf() ?>
        <?php if ($isReject): ?>
            <label class="mail-decision-note">
                <span><?= htmlspecialchars($t('leave.reject_reason'), ENT_QUOTES, 'UTF-8') ?></span>
                <textarea name="decision_note" rows="3" maxlength="600" required></textarea>
            </label>
        <?php endif; ?>
        <button class="button <?= $isReject ? 'danger' : '' ?>" type="submit">
            <?= htmlspecialchars($t($decisionLabel), ENT_QUOTES, 'UTF-8') ?>
        </button>
    </form>
</section>

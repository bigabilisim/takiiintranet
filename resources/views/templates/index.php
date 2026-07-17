<?php
$template = $selectedTemplate ?? null;
$projectData = is_array($template['project_data'] ?? null) ? json_encode($template['project_data'], JSON_UNESCAPED_SLASHES) : '';
$testMailRecipient = (string) (($lastTestMailRecipient ?? '') ?: ($user['email'] ?? ''));
$reportSampleData = json_encode([
    'report_month' => date('F Y'),
    'total_requests' => '42',
    'approved_requests' => '31',
    'pending_requests' => '11',
    'product_used' => '18',
    'product_pending' => '5',
    'people_used' => '13',
    'people_pending' => '6',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$templateEditorI18n = [
    'category.layout' => $t('templates.builder.category.layout'),
    'category.basic' => $t('templates.builder.category.basic'),
    'category.mail' => $t('templates.builder.category.mail'),
    'category.reports' => $t('templates.builder.category.reports'),
    'block.section' => $t('templates.builder.block.section'),
    'block.columns' => $t('templates.builder.block.columns'),
    'block.text' => $t('templates.builder.block.text'),
    'block.button' => $t('templates.builder.block.button'),
    'block.metric' => $t('templates.builder.block.metric'),
    'block.table' => $t('templates.builder.block.table'),
    'block.image' => $t('templates.builder.block.image'),
    'content.title' => $t('templates.builder.content.title'),
    'content.body' => $t('templates.builder.content.body'),
    'content.left' => $t('templates.builder.content.left'),
    'content.right' => $t('templates.builder.content.right'),
    'content.text' => $t('templates.builder.content.text'),
    'content.action' => $t('templates.builder.content.action'),
    'content.metric' => $t('templates.builder.content.metric'),
    'content.value' => $t('templates.builder.content.value'),
    'content.row' => $t('templates.builder.content.row'),
    'report.export_unavailable' => $t('templates.report_export.unavailable'),
    'report.export_working' => $t('templates.report_export.working'),
    'report.export_ready' => $t('templates.report_export.ready'),
    'report.export_invalid_json' => $t('templates.report_export.invalid_json'),
];
?>

<section class="page-header">
    <div>
        <p class="eyebrow"><?= htmlspecialchars($t('templates.eyebrow'), ENT_QUOTES, 'UTF-8') ?></p>
        <h1><?= htmlspecialchars($t('templates.title'), ENT_QUOTES, 'UTF-8') ?></h1>
    </div>
    <div class="module-badge" style="--accent: #2f6f62">
        <?= htmlspecialchars($t('templates.summary'), ENT_QUOTES, 'UTF-8') ?>
    </div>
</section>

<?php if ($flashSuccess): ?>
    <p class="alert success"><?= htmlspecialchars($t($flashSuccess), ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<?php if ($flashError): ?>
    <p class="alert"><?= htmlspecialchars($t($flashError), ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<section class="templates-layout">
    <aside class="template-sidebar">
        <div class="section-title">
            <h2><?= htmlspecialchars($t('templates.library'), ENT_QUOTES, 'UTF-8') ?></h2>
            <span><?= htmlspecialchars($t('templates.count', ['count' => count($templates)]), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="template-list">
            <?php foreach ($templates as $templateItem): ?>
                <a
                    class="template-card <?= ($template['id'] ?? '') === ($templateItem['id'] ?? '') ? 'is-active' : '' ?>"
                    href="/module/templates?template=<?= htmlspecialchars((string) $templateItem['id'], ENT_QUOTES, 'UTF-8') ?>"
                >
                    <span><?= htmlspecialchars($t('templates.type.' . $templateItem['type']), ENT_QUOTES, 'UTF-8') ?></span>
                    <strong><?= htmlspecialchars($templateItem['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                    <small><?= htmlspecialchars($templateItem['updated_at'] . ' / ' . $templateItem['updated_by'], ENT_QUOTES, 'UTF-8') ?></small>
                </a>
            <?php endforeach; ?>
        </div>
    </aside>

    <section class="template-editor-panel">
        <?php if ($template === null): ?>
            <div class="empty-inline"><?= htmlspecialchars($t('templates.empty'), ENT_QUOTES, 'UTF-8') ?></div>
        <?php else: ?>
            <form
                class="template-meta-form"
                method="post"
                action="/templates/save"
                data-template-form
            >
                <?= $csrf() ?>
                <input type="hidden" name="template_id" value="<?= htmlspecialchars($template['id'], ENT_QUOTES, 'UTF-8') ?>">
                <textarea class="is-hidden" name="html" data-template-html><?= htmlspecialchars($template['html'], ENT_QUOTES, 'UTF-8') ?></textarea>
                <textarea class="is-hidden" name="css" data-template-css><?= htmlspecialchars($template['css'], ENT_QUOTES, 'UTF-8') ?></textarea>
                <textarea class="is-hidden" name="project_data" data-template-project><?= htmlspecialchars((string) $projectData, ENT_QUOTES, 'UTF-8') ?></textarea>

                <div class="template-meta-grid">
                    <label>
                        <span><?= htmlspecialchars($t('templates.name'), ENT_QUOTES, 'UTF-8') ?></span>
                        <input type="text" name="name" maxlength="120" value="<?= htmlspecialchars($template['name'], ENT_QUOTES, 'UTF-8') ?>" <?= $canManageTemplates ? '' : 'readonly' ?>>
                    </label>
                    <label>
                        <span><?= htmlspecialchars($t('templates.type'), ENT_QUOTES, 'UTF-8') ?></span>
                        <select name="type" <?= $canManageTemplates ? '' : 'disabled' ?>>
                            <option value="mail" <?= $template['type'] === 'mail' ? 'selected' : '' ?>><?= htmlspecialchars($t('templates.type.mail'), ENT_QUOTES, 'UTF-8') ?></option>
                            <option value="report" <?= $template['type'] === 'report' ? 'selected' : '' ?>><?= htmlspecialchars($t('templates.type.report'), ENT_QUOTES, 'UTF-8') ?></option>
                        </select>
                        <?php if (!$canManageTemplates): ?>
                            <input type="hidden" name="type" value="<?= htmlspecialchars($template['type'], ENT_QUOTES, 'UTF-8') ?>">
                        <?php endif; ?>
                    </label>
                    <label>
                        <span><?= htmlspecialchars($t('templates.description'), ENT_QUOTES, 'UTF-8') ?></span>
                        <input type="text" name="description" maxlength="240" value="<?= htmlspecialchars($template['description'], ENT_QUOTES, 'UTF-8') ?>" <?= $canManageTemplates ? '' : 'readonly' ?>>
                    </label>
                    <button class="button primary" type="submit" <?= $canManageTemplates ? '' : 'disabled' ?>>
                        <?= htmlspecialchars($t('templates.save'), ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </div>
            </form>

            <section class="template-test-mail-card">
                <div class="template-test-mail-copy">
                    <strong><?= htmlspecialchars($t('templates.test_mail.title'), ENT_QUOTES, 'UTF-8') ?></strong>
                    <span><?= htmlspecialchars($t('templates.test_mail.subtitle'), ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <form
                    class="template-test-mail-form"
                    method="post"
                    action="/templates/test-mail"
                    data-template-test-form
                >
                    <?= $csrf() ?>
                    <input type="hidden" name="template_id" value="<?= htmlspecialchars($template['id'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="template_name" value="<?= htmlspecialchars($template['name'], ENT_QUOTES, 'UTF-8') ?>" data-template-test-name>
                    <input type="hidden" name="type" value="<?= htmlspecialchars($template['type'], ENT_QUOTES, 'UTF-8') ?>" data-template-test-type>
                    <textarea class="is-hidden" name="html" data-template-test-html><?= htmlspecialchars($template['html'], ENT_QUOTES, 'UTF-8') ?></textarea>
                    <textarea class="is-hidden" name="css" data-template-test-css><?= htmlspecialchars($template['css'], ENT_QUOTES, 'UTF-8') ?></textarea>
                    <textarea class="is-hidden" name="project_data" data-template-test-project><?= htmlspecialchars((string) $projectData, ENT_QUOTES, 'UTF-8') ?></textarea>

                    <label>
                        <span><?= htmlspecialchars($t('templates.test_mail.recipient'), ENT_QUOTES, 'UTF-8') ?></span>
                        <input type="email" name="to_email" value="<?= htmlspecialchars($testMailRecipient, ENT_QUOTES, 'UTF-8') ?>" required <?= $canManageTemplates ? '' : 'disabled' ?>>
                    </label>
                    <label>
                        <span><?= htmlspecialchars($t('templates.test_mail.subject'), ENT_QUOTES, 'UTF-8') ?></span>
                        <input type="text" name="subject" maxlength="140" value="<?= htmlspecialchars($t('templates.test_mail.default_subject', ['name' => $template['name']]), ENT_QUOTES, 'UTF-8') ?>" <?= $canManageTemplates ? '' : 'disabled' ?>>
                    </label>
                    <button class="button ghost" type="submit" <?= $canManageTemplates ? '' : 'disabled' ?>>
                        <?= htmlspecialchars($t('templates.test_mail.send'), ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </form>
            </section>

            <section class="template-report-card" data-template-report-tools <?= $template['type'] === 'report' ? '' : 'hidden' ?>>
                <div class="template-report-copy">
                    <strong><?= htmlspecialchars($t('templates.report_export.title'), ENT_QUOTES, 'UTF-8') ?></strong>
                    <span><?= htmlspecialchars($t('templates.report_export.subtitle'), ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div class="template-report-actions">
                    <button class="button ghost" type="button" data-template-report-preview>
                        <?= htmlspecialchars($t('templates.report_export.preview'), ENT_QUOTES, 'UTF-8') ?>
                    </button>
                    <button class="button primary" type="button" data-template-report-pdf>
                        <?= htmlspecialchars($t('templates.report_export.pdf'), ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </div>
                <label>
                    <span><?= htmlspecialchars($t('templates.report_export.sample_data'), ENT_QUOTES, 'UTF-8') ?></span>
                    <textarea rows="7" data-template-report-data><?= htmlspecialchars((string) $reportSampleData, ENT_QUOTES, 'UTF-8') ?></textarea>
                </label>
                <div class="template-report-status" data-template-report-status></div>
                <div class="template-report-preview-shell">
                    <div class="template-report-preview" data-template-report-preview-surface></div>
                </div>
            </section>

            <div class="template-builder-shell">
                <script>
                    window.MYTAKII_TEMPLATE_I18N = <?= json_encode($templateEditorI18n, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
                </script>
                <div class="template-builder-toolbar">
                    <div>
                        <strong><?= htmlspecialchars($template['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                        <span><?= htmlspecialchars($t('templates.updated', ['time' => $template['updated_at'], 'name' => $template['updated_by']]), ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <span><?= htmlspecialchars($t('templates.grapesjs_badge'), ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div
                    id="template-builder"
                    data-template-builder
                    data-can-edit="<?= $canManageTemplates ? '1' : '0' ?>"
                    data-empty-text="<?= htmlspecialchars($t('templates.editor_unavailable'), ENT_QUOTES, 'UTF-8') ?>"
                ></div>
            </div>
        <?php endif; ?>
    </section>
</section>

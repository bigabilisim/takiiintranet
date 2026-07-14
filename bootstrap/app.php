<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\AdminController;
use App\Controllers\DashboardController;
use App\Controllers\LeaveController;
use App\Controllers\MessagesController;
use App\Controllers\ModuleController;
use App\Controllers\PersonnelController;
use App\Controllers\ProcurementController;
use App\Controllers\PushController;
use App\Controllers\ShiftController;
use App\Controllers\TemplatesController;
use App\Core\AccessControl;
use App\Core\AuditLogStore;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\ReleaseNoteStore;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Core\StateStore;
use App\Core\Translator;
use App\Core\UserIdentityMigrationService;
use App\Core\UserProfileStore;
use App\Core\View;
use App\Modules\Leave\LeaveApprovalMailer;
use App\Modules\Leave\LeaveStore;
use App\Modules\Auth\PasswordResetMailer;
use App\Modules\Auth\PasswordResetStore;
use App\Modules\Auth\PersonnelCredentialService;
use App\Modules\Messaging\MessageStore;
use App\Modules\Notifications\PushNotificationStore;
use App\Modules\Procurement\ProcurementStore;
use App\Modules\Shift\ShiftStore;
use App\Modules\Templates\TemplateStore;
use App\Modules\Templates\TemplateTestMailer;
use App\Modules\Weather\WeatherStore;

define('APP_ROOT', dirname(__DIR__));

function loadEnvFile(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $value = trim($value, "\"'");

        if ($key === '' || getenv($key) !== false) {
            continue;
        }

        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

loadEnvFile(APP_ROOT . '/.env');

function browserPreferredLocale(array $availableLocales, string $defaultLocale): string
{
    $header = (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');

    if ($header === '') {
        return $defaultLocale;
    }

    $availableByLower = [];

    foreach (array_keys($availableLocales) as $locale) {
        $availableByLower[strtolower(str_replace('_', '-', (string) $locale))] = (string) $locale;
    }

    $preferences = [];

    foreach (explode(',', $header) as $index => $part) {
        $pieces = array_map('trim', explode(';', $part));
        $language = strtolower(str_replace('_', '-', $pieces[0] ?? ''));

        if ($language === '') {
            continue;
        }

        $quality = 1.0;

        foreach (array_slice($pieces, 1) as $piece) {
            if (str_starts_with($piece, 'q=')) {
                $quality = max(0.0, min(1.0, (float) substr($piece, 2)));
            }
        }

        $preferences[] = ['language' => $language, 'quality' => $quality, 'index' => $index];
    }

    usort($preferences, fn (array $a, array $b): int => $b['quality'] <=> $a['quality'] ?: $a['index'] <=> $b['index']);

    foreach ($preferences as $preference) {
        $language = $preference['language'];

        if (isset($availableByLower[$language])) {
            return $availableByLower[$language];
        }

        $primary = strtok($language, '-');

        foreach ($availableByLower as $availableLower => $availableLocale) {
            if (strtok($availableLower, '-') === $primary) {
                return $availableLocale;
            }
        }
    }

    return $defaultLocale;
}

function runLeaveBookSignatureFollowupScheduler(LeaveStore $leaveStore, StateStore $stateStore): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    $path = APP_ROOT . '/storage/leave-book-signature-followup-scheduler.json';
    $writeGuard = $stateStore->beginWrite('leave_signature_scheduler', $path);
    $slot = date('Y-m-d H');
    $state = $stateStore->read('leave_signature_scheduler', $path);

    if (($state['last_slot'] ?? '') === $slot) {
        return;
    }

    $state = [
        'version' => 1,
        'last_slot' => $slot,
        'started_at' => date('Y-m-d H:i'),
        'finished_at' => null,
        'status' => 'running',
        'result' => null,
    ];
    $stateStore->write('leave_signature_scheduler', $path, $state);

    try {
        $state['result'] = $leaveStore->sendDueLeaveBookSignatureFollowups();
        $state['status'] = 'completed';
    } catch (Throwable $exception) {
        $state['status'] = 'failed';
        $state['error'] = substr(preg_replace('/\s+/', ' ', $exception->getMessage()) ?: 'unknown', 0, 240);
    }

    $state['finished_at'] = date('Y-m-d H:i');
    $stateStore->write('leave_signature_scheduler', $path, $state);
}

$vendorAutoload = APP_ROOT . '/vendor/autoload.php';

if (is_file($vendorAutoload)) {
    require $vendorAutoload;
}

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = APP_ROOT . '/app/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require $path;
    }
});

Session::start();

$appConfig = require APP_ROOT . '/config/app.php';
$modules = require APP_ROOT . '/config/modules.php';
$databaseConfig = require APP_ROOT . '/config/database.php';
$stateConfig = require APP_ROOT . '/config/state.php';

$defaultLocale = array_key_exists($appConfig['locale'], $appConfig['available_locales'])
    ? $appConfig['locale']
    : $appConfig['fallback_locale'];
$requestedLocale = is_string($_GET['lang'] ?? null) ? (string) $_GET['lang'] : null;
$sessionLocale = Session::get('locale');
$manualLocaleSelected = Session::get('locale_manually_selected') === true;

if ($requestedLocale !== null && array_key_exists($requestedLocale, $appConfig['available_locales'])) {
    $locale = $requestedLocale;
    Session::put('locale_manually_selected', true);
} elseif ($manualLocaleSelected && is_string($sessionLocale) && array_key_exists($sessionLocale, $appConfig['available_locales'])) {
    $locale = $sessionLocale;
} else {
    $locale = $defaultLocale;
}

if (!array_key_exists($locale, $appConfig['available_locales'])) {
    $locale = $defaultLocale;
}

Session::put('locale', $locale);

$translator = new Translator(
    APP_ROOT . '/resources/lang',
    $locale,
    $appConfig['fallback_locale']
);

$database = new Database($databaseConfig);
$stateStore = new StateStore($stateConfig['driver'] === 'mariadb' ? $database : null, $stateConfig);
$userProfiles = new UserProfileStore($appConfig['demo_users'], $stateStore);
$directoryUsers = $userProfiles->users();
$accessControl = new AccessControl($directoryUsers, $modules, $stateStore);
$auth = new Auth($userProfiles, $accessControl);
$messageStore = new MessageStore($accessControl->usersByIdentity(), $stateStore);
$pushStore = new PushNotificationStore($stateStore);
$auditLog = new AuditLogStore();
$releaseNotes = new ReleaseNoteStore();
$passwordResetMailer = new PasswordResetMailer();
$passwordResets = new PasswordResetStore($userProfiles, $passwordResetMailer, $stateStore);
$personnelCredentials = new PersonnelCredentialService($userProfiles, $passwordResets, $passwordResetMailer);
$shiftStore = new ShiftStore($userProfiles, $stateStore);
$leaveStore = new LeaveStore($accessControl, new LeaveApprovalMailer(), $stateStore, $userProfiles, $shiftStore);
$identityMigration = new UserIdentityMigrationService(
    $stateStore,
    $userProfiles,
    $accessControl,
    $leaveStore,
    $messageStore,
    $pushStore,
    $shiftStore,
    $passwordResets
);
$view = new View(APP_ROOT . '/resources/views', $translator, $auth, $appConfig, $modules, $messageStore, $leaveStore);
$router = new Router();

$adminController = new AdminController(
    $view,
    $auth,
    $accessControl,
    $userProfiles,
    $releaseNotes,
    $auditLog,
    $leaveStore,
    $pushStore,
    $translator,
    $identityMigration
);
$authController = new AuthController($view, $auth, $passwordResets);
$dashboardController = new DashboardController($view, $auth, $leaveStore, new WeatherStore());
$leaveController = new LeaveController($view, $auth, $leaveStore, $pushStore, $translator, $accessControl, $auditLog);
$messagesController = new MessagesController($view, $auth, $messageStore, $accessControl, $translator, $pushStore);
$personnelController = new PersonnelController(
    $view,
    $auth,
    $userProfiles,
    $accessControl,
    $auditLog,
    $shiftStore,
    $identityMigration,
    $personnelCredentials
);
$procurementController = new ProcurementController($view, $auth, new ProcurementStore());
$shiftController = new ShiftController($view, $auth, $shiftStore, $auditLog);
$templatesController = new TemplatesController($view, $auth, new TemplateStore(), new TemplateTestMailer(), $translator);
$moduleController = new ModuleController($view, $auth, $modules);
$pushController = new PushController($auth, $pushStore, $translator);

runLeaveBookSignatureFollowupScheduler($leaveStore, $stateStore);

$router->get('/', [$dashboardController, 'index']);
$router->get('/login', [$authController, 'showLogin']);
$router->post('/login', [$authController, 'login']);
$router->get('/forgot-password', [$authController, 'showForgotPassword']);
$router->post('/forgot-password', [$authController, 'requestPasswordReset']);
$router->get('/password-reset/{token}', [$authController, 'showResetPassword']);
$router->post('/password-reset', [$authController, 'resetPassword']);
$router->post('/logout', [$authController, 'logout']);
$router->get('/admin/access', [$adminController, 'access']);
$router->get('/admin/versions', [$adminController, 'versions']);
$router->get('/admin/access/users/export', [$adminController, 'exportUsers']);
$router->post('/admin/access/users/import', [$adminController, 'importUsers']);
$router->post('/admin/access/users', [$adminController, 'updateUser']);
$router->post('/admin/access/departments/create', [$adminController, 'createDepartment']);
$router->post('/admin/access/departments/delete', [$adminController, 'deleteDepartment']);
$router->post('/admin/access/departments', [$adminController, 'updateDepartment']);
$router->get('/module/leave', [$leaveController, 'index']);
$router->get('/leave/book-signatures', [$leaveController, 'bookSignatures']);
$router->post('/leave/book-signatures/{id}/signed', [$leaveController, 'signBookSignature']);
$router->get('/leave/policies', [$leaveController, 'policies']);
$router->post('/leave/policies', [$leaveController, 'updatePolicy']);
$router->post('/leave/policies/departments/create', [$leaveController, 'createSubDepartment']);
$router->post('/leave/policies/departments/assign-parent', [$leaveController, 'assignSubDepartment']);
$router->post('/leave/policies/departments/delete', [$leaveController, 'deleteSubDepartment']);
$router->post('/leave/requests', [$leaveController, 'create']);
$router->post('/leave/requests/{id}/requester-update', [$leaveController, 'updateOwnRequest']);
$router->post('/leave/requests/{id}/requester-cancel', [$leaveController, 'cancelOwnRequest']);
$router->post('/leave/requests/{id}/request-cancellation', [$leaveController, 'requestCancellation']);
$router->post('/leave/requests/{id}/decision', [$leaveController, 'decision']);
$router->post('/leave/requests/{id}/cancel', [$leaveController, 'cancel']);
$router->get('/leave/mail-approval/{token}/{decision}', [$leaveController, 'mailDecision']);
$router->get('/leave/book-signature/{token}/{decision}', [$leaveController, 'bookSignatureDecision']);
$router->get('/module/messages', [$messagesController, 'index']);
$router->get('/messages/unread-count', [$messagesController, 'unreadCount']);
$router->post('/messages/send', [$messagesController, 'send']);
$router->post('/messages/pins', [$messagesController, 'togglePin']);
$router->post('/messages/threads/read', [$messagesController, 'markThreadRead']);
$router->post('/messages/{id}/delete', [$messagesController, 'delete']);
$router->post('/messages/{id}/restore', [$messagesController, 'restore']);
$router->post('/messages/{id}/read', [$messagesController, 'markRead']);
$router->get('/module/personnel', [$personnelController, 'index']);
$router->get('/personnel/export', [$personnelController, 'export']);
$router->get('/personnel/export/xlsx', [$personnelController, 'exportExcel']);
$router->post('/personnel/create', [$personnelController, 'create']);
$router->post('/personnel/update', [$personnelController, 'update']);
$router->post('/personnel/reset-password', [$personnelController, 'resetPassword']);
$router->post('/personnel/delete', [$personnelController, 'delete']);
$router->get('/module/shift', [$shiftController, 'index']);
$router->post('/shift/templates', [$shiftController, 'saveTemplate']);
$router->post('/shift/templates/delete', [$shiftController, 'deleteTemplate']);
$router->post('/shift/weekend-plans', [$shiftController, 'saveWeekendPlan']);
$router->post('/shift/weekend-plans/delete', [$shiftController, 'deleteWeekendPlan']);
$router->post('/shift/holidays', [$shiftController, 'saveHoliday']);
$router->post('/shift/holidays/delete', [$shiftController, 'deleteHoliday']);
$router->post('/shift/assign', [$shiftController, 'assign']);
$router->get('/module/procurement', [$procurementController, 'index']);
$router->post('/procurement/requests', [$procurementController, 'create']);
$router->get('/module/templates', [$templatesController, 'index']);
$router->post('/templates/save', [$templatesController, 'save']);
$router->post('/templates/test-mail', [$templatesController, 'testMail']);
$router->get('/push/config', [$pushController, 'config']);
$router->post('/push/subscribe', [$pushController, 'subscribe']);
$router->post('/push/unsubscribe', [$pushController, 'unsubscribe']);
$router->post('/push/test', [$pushController, 'test']);
$router->get('/module/{slug}', [$moduleController, 'show']);
$router->setNotFound(function (Request $request) use ($view): Response {
    return new Response($view->render('errors/404', ['title' => '404']), 404);
});

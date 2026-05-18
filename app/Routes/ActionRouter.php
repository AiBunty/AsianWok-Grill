<?php

declare(strict_types=1);

namespace AWG\Routes;

use AWG\Controllers\AuthController;
use AWG\Controllers\LeadController;
use AWG\Controllers\DiagnosticsController;
use AWG\Controllers\MenuController;
use AWG\Controllers\MenuManagementController;
use AWG\Controllers\WebhookController;
use AWG\Controllers\AdminModuleController;
use AWG\Controllers\EventController;
use AWG\Middleware\PermissionMiddleware;
use AWG\Services\AuthService;
use AWG\Services\LeadService;
use AWG\Services\DiagnosticsService;
use AWG\Services\MenuService;
use AWG\Services\MenuManagementService;
use AWG\Services\AdminModuleService;
use AWG\Services\EventService;
use AWG\Services\WhatsAppCloudService;
use AWG\Services\OtpService;
use AWG\Services\MailerService;
use AWG\Repositories\EventRepository;
use AWG\Repositories\EventBookingRepository;
use AWG\Repositories\EventTransactionRepository;
use AWG\Repositories\EventCheckinLogRepository;
use AWG\Repositories\EventOtpRepository;
use AWG\Repositories\EventMailLogRepository;

final class ActionRouter
{
    public static function dispatch(string $method, string $action, array $body, array $query): array
    {
        if ($action === '' || $action === 'health') {
            return [
                'ok' => true,
                'message' => 'AWG backend is running.',
                'method' => strtoupper($method),
                'action' => $action === '' ? 'health' : $action,
                'timestamp' => date('c'),
            ];
        }

        if ($action === 'auth_bootstrap_status') {
            $controller = new AuthController(new AuthService());
            return $controller->bootstrapStatus();
        }

        if ($action === 'auth_bootstrap_superadmin' && $method === 'POST') {
            $controller = new AuthController(new AuthService());
            return $controller->bootstrapSuperadmin();
        }

        if ($action === 'auth_login' && $method === 'POST') {
            $controller = new AuthController(new AuthService());
            return $controller->login($body);
        }

        if ($action === 'auth_me') {
            $controller = new AuthController(new AuthService());
            return $controller->me();
        }

        if ($action === 'auth_logout' && $method === 'POST') {
            $auth = PermissionMiddleware::requireAuthenticatedUser();
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AuthController(new AuthService());
            return $controller->logout();
        }

        if ($action === 'auth_change_password' && $method === 'POST') {
            $auth = PermissionMiddleware::requireAuthenticatedUser();
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AuthController(new AuthService());
            return $controller->changePassword($body);
        }

        if ($action === 'auth_list_users') {
            $auth = PermissionMiddleware::requirePermission('userManagement', true);
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AuthController(new AuthService());
            return $controller->listUsers();
        }

        if ($action === 'auth_create_user' && $method === 'POST') {
            $auth = PermissionMiddleware::requirePermission('userManagement', true);
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AuthController(new AuthService());
            return $controller->createUser($body);
        }

        if ($action === 'auth_set_user_status' && $method === 'POST') {
            $auth = PermissionMiddleware::requirePermission('userManagement', true);
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AuthController(new AuthService());
            return $controller->setUserStatus($body);
        }

        if ($action === 'auth_set_user_permissions' && $method === 'POST') {
            $auth = PermissionMiddleware::requirePermission('userManagement', true);
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AuthController(new AuthService());
            return $controller->setUserPermissions($body);
        }

        if ($action === 'auth_reset_password' && $method === 'POST') {
            $auth = PermissionMiddleware::requirePermission('userManagement', true);
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AuthController(new AuthService());
            return $controller->resetPassword($body);
        }

        if ($action === 'auth_delete_user' && $method === 'POST') {
            $auth = PermissionMiddleware::requirePermission('userManagement', true);
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AuthController(new AuthService());
            return $controller->deleteUser($body);
        }

        if ($action === 'server_connection_diagnostic') {
            $auth = PermissionMiddleware::requirePermission('userManagement', true);
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new DiagnosticsController(new DiagnosticsService());
            return $controller->serverConnections();
        }

        if ($action === 'submit_lead' && $method === 'POST') {
            $controller = new LeadController(new LeadService());
            return $controller->submitLead($body);
        }

        if ($action === 'complete_spin' && $method === 'POST') {
            $controller = new LeadController(new LeadService());
            return $controller->completeSpin($body);
        }

        if ($action === 'qr_scan_client' && $method === 'POST') {
            $controller = new LeadController(new LeadService());
            return $controller->qrScanClient($body);
        }

        if (($action === 'qr_redirect_resolve' || $action === 'qr_redirect') && $method === 'GET') {
            $controller = new LeadController(new LeadService());
            return $controller->qrRedirectResolve($query);
        }

        if ($action === 'qr_report' && $method === 'GET') {
            $auth = PermissionMiddleware::requireAuthenticatedUser();
            $includeRows = ($auth['ok'] ?? false) === true;

            $controller = new LeadController(new LeadService());
            return $controller->qrReport($includeRows);
        }

        if ($action === 'qr_spin_wheel_get_prize' && $method === 'POST') {
            $controller = new LeadController(new LeadService());
            return $controller->qrSpinWheelGetPrize($body);
        }

        if ($action === 'settings_get' && isset($query['setting_group']) && $query['setting_group'] === 'menuBlocker') {
            $controller = new LeadController(new LeadService());
            return $controller->getMenuBlockerSettings();
        }

        if ($action === 'whatsapp_webhook') {
            $controller = new WebhookController(new WhatsAppCloudService());
            if ($method === 'GET') {
                return $controller->whatsappWebhookGet($query);
            }
            if ($method === 'POST') {
                return $controller->whatsappWebhookPost($body);
            }

            return [
                'ok' => false,
                'error' => 'METHOD_NOT_ALLOWED',
                'message' => 'Method not allowed for whatsapp_webhook.',
            ];
        }

        if ($action === 'menu_public_items') {
            $controller = new MenuController(new MenuService());
            return $controller->publicFoodMenu($query);
        }

        if ($action === 'menu_public_cocktail') {
            $controller = new MenuController(new MenuService());
            return $controller->publicCocktailMenu();
        }

        if (in_array($action, [
            'admin_menu_editor_load',
            'admin_menu_editor_save_changes',
            'admin_menu_editor_add_row',
            'admin_menu_editor_delete_rows',
            'admin_menu_editor_set_visibility',
            'admin_menu_editor_upload_image',
            'admin_menu_editor_image_preview',
            'admin_menu_designer_load',
            'admin_menu_designer_save_category_order',
            'admin_menu_designer_save_item_order',
            'admin_menu_designer_toggle_category',
            'admin_menu_designer_toggle_item',
            'admin_menu_designer_clone_category',
            'admin_menu_import_preview',
            'admin_menu_import_execute',
            'admin_menu_export',
            'admin_menu_template',
        ], true)) {
            $auth = PermissionMiddleware::requirePermission('menuEditor');
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new MenuManagementController(new MenuManagementService());

            if ($action === 'admin_menu_editor_load') {
                return $controller->editorLoad($query);
            }
            if ($action === 'admin_menu_editor_save_changes' && $method === 'POST') {
                return $controller->editorSaveChanges($body);
            }
            if ($action === 'admin_menu_editor_add_row' && $method === 'POST') {
                return $controller->editorAddRow($body);
            }
            if ($action === 'admin_menu_editor_delete_rows' && $method === 'POST') {
                return $controller->editorDeleteRows($body);
            }
            if ($action === 'admin_menu_editor_set_visibility' && $method === 'POST') {
                return $controller->editorSetVisibility($body);
            }
            if ($action === 'admin_menu_editor_upload_image' && $method === 'POST') {
                return $controller->editorUploadImage($body);
            }
            if ($action === 'admin_menu_editor_image_preview') {
                return $controller->editorImagePreview($query);
            }
            if ($action === 'admin_menu_designer_load') {
                return $controller->designerLoad($query);
            }
            if ($action === 'admin_menu_designer_save_category_order' && $method === 'POST') {
                return $controller->designerSaveCategoryOrder($body);
            }
            if ($action === 'admin_menu_designer_save_item_order' && $method === 'POST') {
                return $controller->designerSaveItemOrder($body);
            }
            if ($action === 'admin_menu_designer_toggle_category' && $method === 'POST') {
                return $controller->designerToggleCategory($body);
            }
            if ($action === 'admin_menu_designer_toggle_item' && $method === 'POST') {
                return $controller->designerToggleItem($body);
            }
            if ($action === 'admin_menu_designer_clone_category' && $method === 'POST') {
                return $controller->designerCloneCategory($body);
            }
            if ($action === 'admin_menu_import_preview' && $method === 'POST') {
                return $controller->importPreview($body);
            }
            if ($action === 'admin_menu_import_execute' && $method === 'POST') {
                return $controller->importExecute($body, $auth);
            }
            if ($action === 'admin_menu_export') {
                return $controller->export($query);
            }
            if ($action === 'admin_menu_template') {
                return $controller->template($query);
            }

            return [
                'ok' => false,
                'error' => 'METHOD_NOT_ALLOWED',
                'message' => 'Method not allowed for this action.',
            ];
        }

        if ($action === 'menu_admin_sources') {
            $auth = PermissionMiddleware::requirePermission('menuEditor');
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new MenuController(new MenuService());
            return $controller->adminMenuSources();
        }

        if ($action === 'menu_admin_import' && $method === 'POST') {
            $auth = PermissionMiddleware::requirePermission('menuEditor');
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new MenuController(new MenuService());
            return $controller->adminMenuImport($body);
        }

        if (($action === 'menu_admin_workspace'
                || $action === 'admin_menu_workspace'
                || $action === 'admin_menu_workspace_state')) {
            $auth = PermissionMiddleware::requirePermission('menuEditor');
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new MenuController(new MenuService());
            return $controller->adminMenuWorkspace();
        }

        if (($action === 'menu_admin_snapshot'
                || $action === 'admin_menu_snapshot'
                || $action === 'admin_menu_designer_snapshot')) {
            $auth = PermissionMiddleware::requirePermission('menuEditor');
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new MenuController(new MenuService());
            return $controller->adminMenuSnapshot($query);
        }

        if (($action === 'menu_admin_export'
                || $action === 'admin_menu_export'
                || $action === 'admin_menu_export_source')) {
            $auth = PermissionMiddleware::requirePermission('menuEditor');
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new MenuController(new MenuService());
            return $controller->adminMenuExport($query);
        }

        if (($action === 'menu_admin_sync'
                || $action === 'admin_menu_sync'
                || $action === 'admin_menu_import') && $method === 'POST') {
            $auth = PermissionMiddleware::requirePermission('menuEditor');
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new MenuController(new MenuService());
            return $controller->adminMenuImport($body);
        }

        if (($action === 'menu_admin_save_snapshot'
                || $action === 'admin_menu_save_snapshot') && $method === 'POST') {
            $auth = PermissionMiddleware::requirePermission('menuEditor');
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new MenuController(new MenuService());
            return $controller->adminMenuSaveSnapshot($body);
        }

        if (($action === 'menu_admin_save_category_order'
                || $action === 'admin_menu_save_category_order') && $method === 'POST') {
            $auth = PermissionMiddleware::requirePermission('menuEditor');
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new MenuController(new MenuService());
            return $controller->adminMenuSaveCategoryOrder($body);
        }

        // Canonical events contract
        $buildEventController = static function (): EventController {
            $mailRepo = new EventMailLogRepository();

            return new EventController(
                new EventService(
                    new EventRepository(),
                    new EventBookingRepository(),
                    new EventTransactionRepository(),
                    new EventCheckinLogRepository(),
                    new OtpService(new EventOtpRepository()),
                    new MailerService($mailRepo),
                    $mailRepo
                )
            );
        };

        if ($action === 'events_list') {
            $controller = $buildEventController();
            return $controller->eventsList($query);
        }

        if ($action === 'event_popup') {
            $controller = $buildEventController();
            return $controller->eventPopup($query);
        }

        if ($action === 'event_detail') {
            $controller = $buildEventController();
            return $controller->eventDetail($query);
        }

        if ($action === 'send_event_otp' && $method === 'POST') {
            $controller = $buildEventController();
            return $controller->sendEventOtp($body);
        }

        if ($action === 'verify_event_otp' && $method === 'POST') {
            $controller = $buildEventController();
            return $controller->verifyEventOtp($body);
        }

        if ($action === 'register_free_event' && $method === 'POST') {
            $controller = $buildEventController();
            return $controller->registerFreeEvent($body);
        }

        if ($action === 'create_event_order' && $method === 'POST') {
            $controller = $buildEventController();
            return $controller->createEventOrder($body);
        }

        if ($action === 'confirm_event_payment' && $method === 'POST') {
            $controller = $buildEventController();
            return $controller->confirmEventPayment($body);
        }

        if ($action === 'resend_event_confirmation' && $method === 'POST') {
            $controller = $buildEventController();
            return $controller->resendEventConfirmation($body);
        }

        if ($action === 'request_event_cancellation' && $method === 'POST') {
            $controller = $buildEventController();
            return $controller->requestEventCancellation($body);
        }

        if ($action === 'admin_list_events') {
            $auth = PermissionMiddleware::requireAuthenticatedUser();
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->listEvents();
        }

        if (($action === 'admin_create_event' || $action === 'admin_update_event' || $action === 'admin_save_event') && $method === 'POST') {
            $auth = PermissionMiddleware::requireAuthenticatedUser();
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->saveEvent($body);
        }

        if ($action === 'admin_toggle_event' && $method === 'POST') {
            $auth = PermissionMiddleware::requireAuthenticatedUser();
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->toggleEvent($body);
        }

        if ($action === 'admin_delete_event' && $method === 'POST') {
            $auth = PermissionMiddleware::requireAuthenticatedUser();
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->deleteEvent($body);
        }

        if ($action === 'admin_clone_event' && $method === 'POST') {
            $auth = PermissionMiddleware::requireAuthenticatedUser();
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->cloneEvent($body);
        }

        if ($action === 'admin_event_image_upload' && $method === 'POST') {
            $auth = PermissionMiddleware::requirePermission('eventManagement', true);
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->uploadEventImage($body);
        }

        if ($action === 'verify_event_qr' && $method === 'POST') {
            $controller = $buildEventController();
            return $controller->verifyEventQr($body);
        }

        if ($action === 'admin_preview_event_qr' && $method === 'POST') {
            $auth = PermissionMiddleware::requirePermission('eventManagement', true);
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = $buildEventController();
            return $controller->adminPreviewEventQr($body);
        }

        if ($action === 'admin_batch_checkin_event_qr' && $method === 'POST') {
            $auth = PermissionMiddleware::requirePermission('eventManagement', true);
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = $buildEventController();
            return $controller->adminBatchCheckinEventQr($body);
        }

        if (($action === 'event_guest_report' || $action === 'admin_event_guest_report')) {
            $auth = PermissionMiddleware::requirePermission('eventManagement', true);
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = $buildEventController();
            return $controller->eventGuestReport($query);
        }

        if ($action === 'event_transactions_report') {
            $auth = PermissionMiddleware::requirePermission('eventManagement', true);
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = $buildEventController();
            return $controller->eventTransactionsReport($query);
        }

        if ($action === 'admin_mail_log_report' || $action === 'admin_event_mail_log_report') {
            $auth = PermissionMiddleware::requirePermission('eventManagement', true);
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = $buildEventController();
            return $controller->adminMailLogReport($query);
        }

        if ($action === 'public_live_events' || $action === 'public_events') {
            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->listPublicLiveEvents();
        }

        if (($action === 'public_event_register' || $action === 'event_register') && $method === 'POST') {
            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->registerPublicEvent($body);
        }

        if ($action === 'public_spin_offers' || $action === 'spin_offers') {
            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->getSpinOffers();
        }

        if ($action === 'get_blocker_settings' || $action === 'public_blocker_settings') {
            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->getBlockerSettings();
        }

        if ($action === 'public_verify_blocker_passcode' && $method === 'POST') {
            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->verifyBlockerPasscode($body);
        }

        if ($action === 'public_verify_scanner_passcode' && $method === 'POST') {
            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->verifyScannerPasscode($body);
        }

        if ($action === 'admin_dashboard_summary' || $action === 'admin_dashboard_stats') {
            $auth = PermissionMiddleware::requireAuthenticatedUser();
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->dashboardSummary();
        }

        if ($action === 'admin_verify_phone' || $action === 'verify') {
            $auth = PermissionMiddleware::requireAuthenticatedUser();
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->verifyPhone($query);
        }

        if (($action === 'admin_redeem_coupon' || $action === 'redeem') && $method === 'POST') {
            $auth = PermissionMiddleware::requireAuthenticatedUser();
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->redeemCoupon($body);
        }

        if (($action === 'regen_coupon' || $action === 'admin_regen_coupon') && $method === 'POST') {
            $auth = PermissionMiddleware::requireAuthenticatedUser();
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->regenerateCoupon($body);
        }

        if ($action === 'admin_issue_surprise_coupon' && $method === 'POST') {
            $auth = PermissionMiddleware::requireAuthenticatedUser();
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->issueSurpriseCoupon($body, (int) ($auth['user']['id'] ?? 0));
        }

        if ($action === 'admin_list_events') {
            $auth = PermissionMiddleware::requireAuthenticatedUser();
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->listEvents();
        }

        if (($action === 'admin_event_list' || $action === 'events_list' || $action === 'event_list')) {
            $auth = PermissionMiddleware::requireAuthenticatedUser();
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->listEvents();
        }

        if ($action === 'admin_save_event' && $method === 'POST') {
            $auth = PermissionMiddleware::requireAuthenticatedUser();
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->saveEvent($body);
        }

        if (($action === 'admin_create_event' || $action === 'admin_update_event') && $method === 'POST') {
            $auth = PermissionMiddleware::requireAuthenticatedUser();
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->saveEvent($body);
        }

        if ($action === 'admin_clone_event' && $method === 'POST') {
            $auth = PermissionMiddleware::requireAuthenticatedUser();
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->cloneEvent($body);
        }

        if ($action === 'admin_toggle_event' && $method === 'POST') {
            $auth = PermissionMiddleware::requireAuthenticatedUser();
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->toggleEvent($body);
        }

        if ($action === 'admin_generate_event_qr' && $method === 'POST') {
            $auth = PermissionMiddleware::requireAuthenticatedUser();
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->generateEventQr($body);
        }

        if ($action === 'admin_delete_event' && $method === 'POST') {
            $auth = PermissionMiddleware::requireAuthenticatedUser();
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->deleteEvent($body);
        }

        if ($action === 'admin_preview_event_qr' && $method === 'POST') {
            $auth = PermissionMiddleware::requireAuthenticatedUser();
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->previewEventQr($body);
        }

        if ($action === 'admin_batch_checkin_event_qr' && $method === 'POST') {
            $auth = PermissionMiddleware::requireAuthenticatedUser();
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->batchCheckinEventQr($body);
        }

        if ($action === 'admin_event_guest_report') {
            $auth = PermissionMiddleware::requireAuthenticatedUser();
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->eventGuestReport($query);
        }

        if ($action === 'admin_mail_log_report' || $action === 'admin_event_mail_log_report') {
            $auth = PermissionMiddleware::requireAuthenticatedUser();
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->eventMailLogReport($query);
        }

        if ($action === 'auth_get_app_settings' && $method === 'POST') {
            $auth = PermissionMiddleware::requirePermission('userManagement', true);
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->getAppSettings();
        }

        if ($action === 'auth_set_app_settings' && $method === 'POST') {
            $auth = PermissionMiddleware::requirePermission('userManagement', true);
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->setAppSettings($body);
        }

        if ($action === 'admin_update_blocker_pages' && $method === 'POST') {
            $auth = PermissionMiddleware::requirePermission('userManagement', true);
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->updateBlockerPages($body);
        }

        if ($action === 'auth_get_spin_offers') {
            $auth = PermissionMiddleware::requirePermission('userManagement', true);
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->getSpinOffers();
        }

        if ($action === 'auth_set_spin_offers' && $method === 'POST') {
            $auth = PermissionMiddleware::requirePermission('userManagement', true);
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->setSpinOffers($body);
        }

        if ($action === 'auth_get_qr_redirect_settings') {
            $auth = PermissionMiddleware::requirePermission('userManagement', true);
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AuthController(new AuthService());
            return $controller->getQrRedirectSettings();
        }

        if ($action === 'auth_set_qr_redirect_settings' && $method === 'POST') {
            $auth = PermissionMiddleware::requirePermission('userManagement', true);
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AuthController(new AuthService());
            return $controller->setQrRedirectSettings($body);
        }

        if ($action === 'auth_list_qr_redirects') {
            $auth = PermissionMiddleware::requirePermission('userManagement', true);
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AuthController(new AuthService());
            return $controller->listQrRedirects();
        }

        if ($action === 'auth_save_qr_redirect' && $method === 'POST') {
            $auth = PermissionMiddleware::requirePermission('userManagement', true);
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AuthController(new AuthService());
            return $controller->saveQrRedirect($body);
        }

        if ($action === 'auth_set_qr_redirect_active' && $method === 'POST') {
            $auth = PermissionMiddleware::requirePermission('userManagement', true);
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AuthController(new AuthService());
            return $controller->setQrRedirectActive($body);
        }

        if ($action === 'auth_delete_qr_redirect' && $method === 'POST') {
            $auth = PermissionMiddleware::requirePermission('userManagement', true);
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AuthController(new AuthService());
            return $controller->deleteQrRedirect($body);
        }

        // Menu Blocker Admin Actions
        if ($action === 'auth_get_menu_blocker_settings') {
            $auth = PermissionMiddleware::requirePermission('userManagement', true);
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AuthController(new AuthService());
            return $controller->getMenuBlockerSettings();
        }

        if ($action === 'auth_update_menu_blocker_settings' && $method === 'POST') {
            $auth = PermissionMiddleware::requirePermission('userManagement', true);
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AuthController(new AuthService());
            return $controller->updateMenuBlockerSettings($body);
        }

        if ($action === 'auth_get_menu_blocker_stats') {
            $auth = PermissionMiddleware::requirePermission('userManagement', true);
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AuthController(new AuthService());
            return $controller->getMenuBlockerStats($body);
        }

        if ($action === 'auth_get_menu_blocker_phone_history') {
            $auth = PermissionMiddleware::requirePermission('userManagement', true);
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AuthController(new AuthService());
            return $controller->getMenuBlockerPhoneHistory($body);
        }

        if ($action === 'admin_crm_panel_status') {
            $auth = PermissionMiddleware::requireAuthenticatedUser();
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }
            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->crmPanelStatus();
        }

        if ($action === 'admin_list_crm_trigger_configs') {
            $auth = PermissionMiddleware::requireAuthenticatedUser();
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }
            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->listCrmTriggerConfigs();
        }

        if ($action === 'admin_save_crm_trigger_config' && $method === 'POST') {
            $auth = PermissionMiddleware::requireAuthenticatedUser();
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }
            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->saveCrmTriggerConfig($body);
        }

        if ($action === 'admin_test_crm_trigger' && $method === 'POST') {
            $auth = PermissionMiddleware::requireAuthenticatedUser();
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }
            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->testCrmTrigger($body);
        }

        if ($action === 'admin_reset_crm_trigger_to_default' && $method === 'POST') {
            $auth = PermissionMiddleware::requireAuthenticatedUser();
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }
            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->resetCrmTriggerToDefault($body);
        }

        if ($action === 'admin_list_crm_contacts') {
            $auth = PermissionMiddleware::requireAuthenticatedUser();
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }
            $controller = new AdminModuleController(new AdminModuleService());
            $payload = $method === 'POST' ? $body : $query;
            return $controller->listCrmContacts(is_array($payload) ? $payload : []);
        }

        if ($action === 'admin_list_crm_push_logs') {
            $auth = PermissionMiddleware::requireAuthenticatedUser();
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }
            $controller = new AdminModuleController(new AdminModuleService());
            $payload = $method === 'POST' ? $body : $query;
            return $controller->listCrmPushLogs(is_array($payload) ? $payload : []);
        }

        if ($action === 'admin_backfill_crm_contacts' && $method === 'POST') {
            $auth = PermissionMiddleware::requireAuthenticatedUser();
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }
            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->backfillCrmContacts();
        }

        if ($action === 'admin_export_crm_contacts') {
            $auth = PermissionMiddleware::requireAuthenticatedUser();
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }
            $controller = new AdminModuleController(new AdminModuleService());
            $payload = $method === 'POST' ? $body : $query;
            return $controller->exportCrmContacts(is_array($payload) ? $payload : []);
        }

        if ($action === 'admin_crm_leads_status') {
            $auth = PermissionMiddleware::requireAuthenticatedUser();
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AdminModuleController(new AdminModuleService());
            $payload = $method === 'POST' ? $body : $query;
            return $controller->crmLeadsStatus(is_array($payload) ? $payload : []);
        }

        if ($action === 'admin_list_crm_leads') {
            $auth = PermissionMiddleware::requireAuthenticatedUser();
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AdminModuleController(new AdminModuleService());
            $payload = $method === 'POST' ? $body : $query;
            return $controller->listCrmLeads(is_array($payload) ? $payload : []);
        }

        if ($action === 'admin_export_crm_leads') {
            $auth = PermissionMiddleware::requireAuthenticatedUser();
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AdminModuleController(new AdminModuleService());
            $payload = $method === 'POST' ? $body : $query;
            return $controller->exportCrmLeads(is_array($payload) ? $payload : []);
        }

        if ($action === 'admin_test_crm_sync' && $method === 'POST') {
            $auth = PermissionMiddleware::requireAuthenticatedUser();
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->testCrmSync($body);
        }

        if ($action === 'admin_delete_crm_test_lead' && $method === 'POST') {
            $auth = PermissionMiddleware::requireAuthenticatedUser();
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->deleteCrmTestLead($body);
        }

        if (($action === 'sync_crm_by_phone' || $action === 'sync-crm-by-phone') && $method === 'POST') {
            $auth = PermissionMiddleware::requireAuthenticatedUser();
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new LeadController(new LeadService());
            return $controller->syncCrmByPhone($body);
        }

        if ($action === 'admin_cash_summary') {
            $auth = PermissionMiddleware::requireAuthenticatedUser();
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }
            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->cashSummary($query, $auth['user'] ?? []);
        }

        if ($action === 'admin_issue_cash_paid_pass' && $method === 'POST') {
            $auth = PermissionMiddleware::requireAuthenticatedUser();
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->issueCashPaidPass($body, $auth['user'] ?? []);
        }

        if ($action === 'superadmin_cash_dashboard') {
            $auth = PermissionMiddleware::requirePermission('userManagement', true);
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->superadminCashDashboard($query);
        }

        if (($action === 'admin_request_cash_handover' || $action === 'admin_request_cash_cancel') && $method === 'POST') {
            $auth = PermissionMiddleware::requireAuthenticatedUser();
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }
            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->requestCashAction($body, $auth['user'] ?? [], $action);
        }

        if (($action === 'superadmin_approve_cash_handover' || $action === 'superadmin_resolve_cash_cancel') && $method === 'POST') {
            $auth = PermissionMiddleware::requirePermission('userManagement', true);
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }
            $controller = new AdminModuleController(new AdminModuleService());
            return $controller->resolveCashAction($body, $auth['user'] ?? [], $action);
        }

        if ($action === 'auth_get_whatsapp_workspace' && $method === 'POST') {
            $auth = PermissionMiddleware::requirePermission('userManagement', true);
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AuthController(new AuthService());
            return $controller->getWhatsappWorkspace();
        }

        if (($action === 'auth_set_whatsapp_config' || $action === 'auth_save_whatsapp_config') && $method === 'POST') {
            $auth = PermissionMiddleware::requirePermission('userManagement', true);
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AuthController(new AuthService());
            return $controller->saveWhatsappConfig($body);
        }

        if ($action === 'auth_sync_whatsapp_templates' && $method === 'POST') {
            $auth = PermissionMiddleware::requirePermission('userManagement', true);
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AuthController(new AuthService());
            return $controller->syncWhatsappTemplates();
        }

        if (($action === 'auth_save_whatsapp_mapping' || $action === 'auth_save_whatsapp_event_mapping') && $method === 'POST') {
            $auth = PermissionMiddleware::requirePermission('userManagement', true);
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AuthController(new AuthService());
            return $controller->saveWhatsappMapping($body);
        }

        if (($action === 'auth_save_whatsapp_template_draft' || $action === 'auth_save_whatsapp_draft') && $method === 'POST') {
            $auth = PermissionMiddleware::requirePermission('userManagement', true);
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AuthController(new AuthService());
            return $controller->saveWhatsappTemplateDraft($body);
        }

        if (($action === 'auth_submit_whatsapp_template_draft' || $action === 'auth_submit_whatsapp_draft') && $method === 'POST') {
            $auth = PermissionMiddleware::requirePermission('userManagement', true);
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AuthController(new AuthService());
            return $controller->submitWhatsappTemplateDraft($body);
        }

        if ($action === 'auth_preview_whatsapp_template' && $method === 'POST') {
            $auth = PermissionMiddleware::requirePermission('userManagement', true);
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AuthController(new AuthService());
            return $controller->previewWhatsappTemplate($body);
        }

        if ($action === 'auth_run_whatsapp_scheduler' && $method === 'POST') {
            $auth = PermissionMiddleware::requirePermission('userManagement', true);
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AuthController(new AuthService());
            return $controller->runWhatsappScheduler($body);
        }

        if (($action === 'auth_send_test_whatsapp_template' || $action === 'auth_send_whatsapp_test') && $method === 'POST') {
            $auth = PermissionMiddleware::requirePermission('userManagement', true);
            if (($auth['ok'] ?? false) !== true) {
                return $auth;
            }

            $controller = new AuthController(new AuthService());
            return $controller->sendTestWhatsappTemplate($body);
        }

        return [
            'ok' => false,
            'error' => 'ACTION_NOT_IMPLEMENTED',
            'message' => 'The requested action is not implemented yet.',
            'action' => $action,
        ];
    }
}

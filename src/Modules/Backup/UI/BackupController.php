<?php

declare(strict_types=1);

namespace App\Modules\Backup\UI;

use App\Modules\Backup\Application\BackupService;
use App\Shared\Http\Request;
use App\Shared\Http\Response;
use App\Shared\Security\Csrf;
use App\Shared\Security\RateLimiter;
use App\Shared\Security\RecordsAccess;
use App\Shared\Security\RecordsPrincipal;
use App\Shared\Security\RecordsSession;
use App\Shared\Security\SecurityLogger;
use App\Shared\View\View;
use InvalidArgumentException;
use RuntimeException;

final class BackupController
{
    public function __construct(
        private readonly BackupService $service,
        private readonly View $view,
        private readonly Csrf $csrf,
        private readonly RecordsAccess $recordsAccess,
        private readonly RecordsSession $recordsSession,
        private readonly RateLimiter $rateLimiter,
        private readonly SecurityLogger $securityLogger,
        private readonly bool $operational,
    ) {
    }

    public function index(Request $request): Response
    {
        $principal = $this->authorize($request);
        if ($principal instanceof Response) {
            return $principal;
        }

        $flash = $_SESSION['_backup_flash'] ?? null;
        $errors = $_SESSION['_backup_errors'] ?? [];
        unset($_SESSION['_backup_flash'], $_SESSION['_backup_errors']);
        $body = $this->view->render('pickupsheet/backup', [
            'pageTitle' => 'Backup and restore',
            'pageDescription' => 'Create encrypted Pickupsheet backups and restore validated application data.',
            'pageRobots' => 'noindex, nofollow',
            'activePage' => 'pickupsheet',
            'basePath' => $request->basePath,
            'assetBase' => $request->basePath . '/public/assets',
            'csrfToken' => $this->csrf->token(),
            'recordsUsername' => $principal->username,
            'recordsFullName' => $principal->fullName(),
            'operational' => $this->operational,
            'flash' => is_string($flash) ? $flash : null,
            'errors' => is_array($errors) ? $errors : [],
            'maxBackupMb' => (int) floor(BackupService::MAX_BACKUP_BYTES / 1048576),
        ]);
        return Response::html($body, 200, $this->privateHeaders());
    }

    public function download(Request $request): Response
    {
        $principal = $this->authorize($request);
        if ($principal instanceof Response) {
            return $principal;
        }
        $denied = $this->writeGuard($request, $principal, 'backup_download', 10);
        if ($denied !== null) {
            return $denied;
        }
        if (!$this->operational) {
            return $this->unavailable($request, $principal, 'pickupsheet.backup_download');
        }
        $passphrase = $request->rawInput('passphrase');
        if (!hash_equals($passphrase, $request->rawInput('passphrase_confirmation'))) {
            $_SESSION['_backup_errors'] = ['The backup passphrases do not match.'];
            $this->log($request, $principal, 'pickupsheet.backup_download', 'denied', ['reason' => 'confirmation']);
            return $this->redirect($request);
        }

        try {
            $backup = $this->service->createEncrypted($passphrase);
            $this->log($request, $principal, 'pickupsheet.backup_download', 'accepted', [
                'table_count' => $backup['tableCount'],
                'row_count' => $backup['rowCount'],
            ]);
            return Response::download($backup['contents'], 'application/json', $backup['filename']);
        } catch (InvalidArgumentException $exception) {
            $_SESSION['_backup_errors'] = [$exception->getMessage()];
            $this->log($request, $principal, 'pickupsheet.backup_download', 'denied', ['reason' => 'validation']);
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            $_SESSION['_backup_errors'] = ['The encrypted backup could not be created. Check MySQL and OpenSSL.'];
            $this->log($request, $principal, 'pickupsheet.backup_download', 'failed');
        }
        return $this->redirect($request);
    }

    public function restore(Request $request): Response
    {
        $principal = $this->authorize($request);
        if ($principal instanceof Response) {
            return $principal;
        }
        $denied = $this->writeGuard($request, $principal, 'backup_restore', 3);
        if ($denied !== null) {
            return $denied;
        }
        if (!$this->operational) {
            return $this->unavailable($request, $principal, 'pickupsheet.backup_restore');
        }
        if ($request->input('confirmation') !== 'RESTORE') {
            $_SESSION['_backup_errors'] = ['Type RESTORE exactly to confirm replacement of application data.'];
            $this->log($request, $principal, 'pickupsheet.backup_restore', 'denied', ['reason' => 'confirmation']);
            return $this->redirect($request);
        }

        $file = $request->uploadedFile('backup_file');
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK || $file['tmpName'] === ''
            || $file['size'] < 1 || $file['size'] > BackupService::MAX_BACKUP_BYTES
            || !is_file($file['tmpName']) || !is_readable($file['tmpName'])
        ) {
            $_SESSION['_backup_errors'] = ['Select a valid encrypted backup file no larger than 12 MB.'];
            $this->log($request, $principal, 'pickupsheet.backup_restore', 'denied', ['reason' => 'upload']);
            return $this->redirect($request);
        }
        $contents = file_get_contents($file['tmpName']);
        if (!is_string($contents)) {
            $_SESSION['_backup_errors'] = ['The uploaded backup could not be read.'];
            $this->log($request, $principal, 'pickupsheet.backup_restore', 'failed', ['reason' => 'upload_read']);
            return $this->redirect($request);
        }

        try {
            $restored = $this->service->restoreEncrypted($contents, $request->rawInput('passphrase'));
            $_SESSION['_backup_flash'] = sprintf(
                'Restore completed: %d rows across %d tables.',
                $restored['rowCount'],
                $restored['tableCount'],
            );
            $this->log($request, $principal, 'pickupsheet.backup_restore', 'accepted', [
                'table_count' => $restored['tableCount'],
                'row_count' => $restored['rowCount'],
            ]);
        } catch (InvalidArgumentException $exception) {
            $_SESSION['_backup_errors'] = [$exception->getMessage()];
            $this->log($request, $principal, 'pickupsheet.backup_restore', 'denied', ['reason' => 'validation']);
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            $_SESSION['_backup_errors'] = ['Restore failed and no partial restore was committed. Check the backup schema and MySQL.'];
            $this->log($request, $principal, 'pickupsheet.backup_restore', 'failed');
        }
        return $this->redirect($request);
    }

    private function authorize(Request $request): RecordsPrincipal|Response
    {
        $principal = $this->recordsSession->principal($this->recordsAccess);
        $context = ['action' => 'backup'];
        if ($principal !== null) {
            $context += [
                'actor_id' => $this->actorId($principal),
                'role' => $principal->role,
                'identity_provider' => $principal->identityProvider,
            ];
            if ($principal->can('backup')) {
                $this->securityLogger->event('pickupsheet.records_access', $request, 'granted', $context);
                return $principal;
            }
            $this->securityLogger->event('pickupsheet.records_access', $request, 'forbidden', $context);
            return Response::html('You do not have permission to back up or restore application data.', 403, $this->privateHeaders());
        }
        $this->securityLogger->event('pickupsheet.records_access', $request, 'denied', $context);
        return Response::redirect($request->basePath . '/dhl/pickupsheet/login', 302);
    }

    private function writeGuard(Request $request, RecordsPrincipal $principal, string $scope, int $limit): ?Response
    {
        try {
            $retryAfter = $this->rateLimiter->consume('pickup-' . $scope, $request->clientIdentifier(), $limit, 3600);
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            $this->log($request, $principal, 'pickupsheet.' . $scope, 'failed', ['reason' => 'rate_limit']);
            return Response::html('Backup operations are temporarily unavailable.', 503, $this->privateHeaders());
        }
        if ($retryAfter > 0) {
            $this->log($request, $principal, 'pickupsheet.' . $scope, 'rate_limited', ['retry_after' => $retryAfter]);
            return Response::html('Too many backup operations. Please try again later.', 429, $this->privateHeaders() + ['Retry-After' => (string) $retryAfter]);
        }
        if (!$this->csrf->validate($request->input('_token'))) {
            $this->log($request, $principal, 'pickupsheet.' . $scope, 'denied', ['reason' => 'csrf']);
            return Response::html('Invalid or expired form token.', 419, $this->privateHeaders());
        }
        return null;
    }

    private function unavailable(Request $request, RecordsPrincipal $principal, string $event): Response
    {
        $_SESSION['_backup_errors'] = ['Backup and restore require an active MySQL connection and AES-256-GCM OpenSSL support.'];
        $this->log($request, $principal, $event, 'unavailable');
        return $this->redirect($request);
    }

    /** @param array<string, bool|float|int|string|null> $context */
    private function log(Request $request, RecordsPrincipal $principal, string $event, string $outcome, array $context = []): void
    {
        $this->securityLogger->event($event, $request, $outcome, $context + [
            'actor_id' => $this->actorId($principal),
            'role' => $principal->role,
            'identity_provider' => $principal->identityProvider,
        ]);
    }

    private function actorId(RecordsPrincipal $principal): string
    {
        return substr(hash('sha256', $principal->username), 0, 24);
    }

    private function redirect(Request $request): Response
    {
        return Response::redirect($request->basePath . '/dhl/pickupsheet/admin/backup');
    }

    /** @return array<string, string> */
    private function privateHeaders(): array
    {
        return ['Cache-Control' => 'private, no-store, max-age=0', 'X-Robots-Tag' => 'noindex, nofollow'];
    }
}

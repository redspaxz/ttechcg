<?php

declare(strict_types=1);

namespace App\Modules\CRM\UI;

use App\Modules\CRM\Application\CustomerService;
use App\Modules\CRM\Domain\CustomerProfile;
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

final class CustomerController
{
    public function __construct(
        private readonly CustomerService $service,
        private readonly View $view,
        private readonly Csrf $csrf,
        private readonly RecordsAccess $recordsAccess,
        private readonly RecordsSession $recordsSession,
        private readonly RateLimiter $rateLimiter,
        private readonly SecurityLogger $securityLogger,
    ) {
    }

    public function index(Request $request): Response
    {
        $principal = $this->authorize($request);
        if ($principal instanceof Response) {
            return $principal;
        }

        $search = $request->queryString('q');
        $status = $request->queryString('status');
        $pageValue = $request->queryString('page', '1');
        $page = ctype_digit($pageValue) ? max(1, (int) $pageValue) : 1;
        $summary = ['customerCount' => 0, 'activeCount' => 0, 'attentionCount' => 0, 'followUpsDue' => 0];
        $customers = ['items' => [], 'page' => 1, 'perPage' => 20, 'totalRecords' => 0, 'totalPages' => 1];
        $error = null;
        try {
            $summary = $this->service->summary();
            $customers = $this->service->paginated($search, $status, $page);
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            $error = 'Customer data is unavailable. Apply the CRM migration and check the MySQL connection.';
        }

        $flash = $_SESSION['_crm_flash'] ?? null;
        unset($_SESSION['_crm_flash']);
        $body = $this->view->render('pickupsheet/customers', $this->common($request, $principal) + [
            'pageTitle' => 'Customer CRM',
            'summary' => $summary,
            'customers' => $customers,
            'search' => $search,
            'statusFilter' => $status,
            'flash' => is_string($flash) ? $flash : null,
            'error' => $error,
        ]);
        return Response::html($body, 200, $this->privateHeaders());
    }

    public function create(Request $request): Response
    {
        $principal = $this->authorize($request);
        if ($principal instanceof Response) {
            return $principal;
        }
        return $this->form($request, $principal, null);
    }

    public function edit(Request $request): Response
    {
        $principal = $this->authorize($request);
        if ($principal instanceof Response) {
            return $principal;
        }

        try {
            $customer = $this->service->find(strtolower($request->queryString('customer')));
            if ($customer === null) {
                return Response::html('Customer profile not found.', 404, $this->privateHeaders());
            }
            return $this->form($request, $principal, $customer);
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            return Response::html('Customer data is temporarily unavailable.', 503, $this->privateHeaders());
        }
    }

    public function save(Request $request): Response
    {
        $principal = $this->authorize($request);
        if ($principal instanceof Response) {
            return $principal;
        }

        try {
            $retryAfter = $this->rateLimiter->consume('pickup-crm-write', $request->clientIdentifier(), 60, 3600);
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            $this->log($request, $principal, 'pickupsheet.crm_customer_save', 'failed');
            return Response::html('Customer updates are temporarily unavailable.', 503, $this->privateHeaders());
        }
        if ($retryAfter > 0) {
            $this->log($request, $principal, 'pickupsheet.crm_customer_save', 'rate_limited', ['retry_after' => $retryAfter]);
            return Response::html('Too many customer updates. Please try again later.', 429, $this->privateHeaders() + ['Retry-After' => (string) $retryAfter]);
        }
        if (!$this->csrf->validate($request->input('_token'))) {
            $this->log($request, $principal, 'pickupsheet.crm_customer_save', 'denied', ['reason' => 'csrf']);
            return Response::html('Invalid or expired form token.', 419, $this->privateHeaders());
        }

        $key = strtolower($request->input('customer_key'));
        $input = [
            'display_name' => $request->input('display_name'),
            'contact_name' => $request->input('contact_name'),
            'email' => $request->input('email'),
            'phone' => $request->input('phone'),
            'address' => $request->input('address'),
            'city' => $request->input('city'),
            'country_code' => $request->input('country_code'),
            'status' => $request->input('status'),
            'notes' => $request->rawInput('notes'),
            'next_follow_up_on' => $request->input('next_follow_up_on'),
        ];
        $_SESSION['_crm_old'] = $input;

        try {
            $saved = $this->service->save($key === '' ? null : $key, $input, $this->actorId($principal));
            unset($_SESSION['_crm_old'], $_SESSION['_crm_errors']);
            $_SESSION['_crm_flash'] = 'Customer profile saved.';
            $this->log($request, $principal, 'pickupsheet.crm_customer_save', 'accepted', [
                'resource_id' => substr($saved->customerKey, 0, 24),
                'customer_status' => $saved->status,
                'source' => $saved->source,
            ]);
            return Response::redirect($request->basePath . '/dhl/pickupsheet/customers/edit?customer=' . rawurlencode($saved->customerKey));
        } catch (InvalidArgumentException $exception) {
            $_SESSION['_crm_errors'] = [$exception->getMessage()];
            $this->log($request, $principal, 'pickupsheet.crm_customer_save', 'denied', [
                'resource_id' => preg_match('/^[a-f0-9]{64}$/', $key) === 1 ? substr($key, 0, 24) : null,
                'reason' => 'validation',
            ]);
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            $_SESSION['_crm_errors'] = ['The customer profile could not be saved. Check MySQL and try again.'];
            $this->log($request, $principal, 'pickupsheet.crm_customer_save', 'failed', [
                'resource_id' => preg_match('/^[a-f0-9]{64}$/', $key) === 1 ? substr($key, 0, 24) : null,
            ]);
        }

        $location = $request->basePath . '/dhl/pickupsheet/customers/new';
        if (preg_match('/^[a-f0-9]{64}$/', $key) === 1) {
            $location = $request->basePath . '/dhl/pickupsheet/customers/edit?customer=' . rawurlencode($key);
        }
        return Response::redirect($location);
    }

    public function adjustRewards(Request $request): Response
    {
        $principal = $this->authorize($request);
        if ($principal instanceof Response) {
            return $principal;
        }

        try {
            $retryAfter = $this->rateLimiter->consume('pickup-crm-reward-write', $request->clientIdentifier(), 40, 3600);
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            $this->log($request, $principal, 'pickupsheet.crm_reward_adjustment', 'failed');
            return Response::html('Reward updates are temporarily unavailable.', 503, $this->privateHeaders());
        }
        if ($retryAfter > 0) {
            $this->log($request, $principal, 'pickupsheet.crm_reward_adjustment', 'rate_limited', ['retry_after' => $retryAfter]);
            return Response::html('Too many reward updates. Please try again later.', 429, $this->privateHeaders() + ['Retry-After' => (string) $retryAfter]);
        }
        if (!$this->csrf->validate($request->input('_token'))) {
            $this->log($request, $principal, 'pickupsheet.crm_reward_adjustment', 'denied', ['reason' => 'csrf']);
            return Response::html('Invalid or expired form token.', 419, $this->privateHeaders());
        }

        $key = strtolower($request->input('customer_key'));
        $operation = strtolower($request->input('operation'));
        $points = $request->input('points');
        try {
            $updated = $this->service->adjustRewards(
                $key,
                $operation,
                $points,
                $request->input('reason'),
                $this->actorId($principal),
            );
            $pointsDelta = $operation === 'bonus' ? (int) $points : -(int) $points;
            $_SESSION['_crm_flash'] = sprintf(
                'Reward balance updated by %s%d point%s. New balance: %d.',
                $pointsDelta > 0 ? '+' : '',
                $pointsDelta,
                abs($pointsDelta) === 1 ? '' : 's',
                $updated->rewardBalance(),
            );
            $this->log($request, $principal, 'pickupsheet.crm_reward_adjustment', 'accepted', [
                'resource_id' => substr($updated->customerKey, 0, 24),
                'reward_delta' => $pointsDelta,
                'reward_balance' => $updated->rewardBalance(),
            ]);
        } catch (InvalidArgumentException $exception) {
            $_SESSION['_crm_errors'] = [$exception->getMessage()];
            $this->log($request, $principal, 'pickupsheet.crm_reward_adjustment', 'denied', [
                'resource_id' => preg_match('/^[a-f0-9]{64}$/', $key) === 1 ? substr($key, 0, 24) : null,
                'reason' => 'validation',
            ]);
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            $_SESSION['_crm_errors'] = ['The reward balance could not be updated. Check MySQL and try again.'];
            $this->log($request, $principal, 'pickupsheet.crm_reward_adjustment', 'failed', [
                'resource_id' => preg_match('/^[a-f0-9]{64}$/', $key) === 1 ? substr($key, 0, 24) : null,
            ]);
        }

        $location = $request->basePath . '/dhl/pickupsheet/customers';
        if (preg_match('/^[a-f0-9]{64}$/', $key) === 1) {
            $location = $request->basePath . '/dhl/pickupsheet/customers/edit?customer=' . rawurlencode($key);
        }
        return Response::redirect($location);
    }

    private function form(Request $request, RecordsPrincipal $principal, ?CustomerProfile $customer): Response
    {
        $old = $_SESSION['_crm_old'] ?? [];
        $errors = $_SESSION['_crm_errors'] ?? [];
        $flash = $_SESSION['_crm_flash'] ?? null;
        unset($_SESSION['_crm_old'], $_SESSION['_crm_errors'], $_SESSION['_crm_flash']);
        $shipments = [];
        $rewardAdjustments = [];
        $rewardRedemptions = [];
        if ($customer !== null) {
            try {
                $shipments = $this->service->recentShipments($customer->customerKey, 20);
                $rewardAdjustments = $this->service->rewardAdjustments($customer->customerKey, 20);
                $rewardRedemptions = $this->service->rewardRedemptions($customer->customerKey, 20);
            } catch (RuntimeException $exception) {
                error_log($exception->__toString());
                $errors = [...(is_array($errors) ? $errors : []), 'Shipment or reward history could not be loaded.'];
            }
        }

        $body = $this->view->render('pickupsheet/customer-form', $this->common($request, $principal) + [
            'pageTitle' => $customer === null ? 'Add CRM customer' : 'Customer profile',
            'customer' => $customer,
            'shipments' => $shipments,
            'rewardAdjustments' => $rewardAdjustments,
            'rewardRedemptions' => $rewardRedemptions,
            'old' => is_array($old) ? $old : [],
            'errors' => is_array($errors) ? $errors : [],
            'flash' => is_string($flash) ? $flash : null,
        ]);
        return Response::html($body, 200, $this->privateHeaders());
    }

    private function authorize(Request $request): RecordsPrincipal|Response
    {
        $principal = $this->recordsSession->principal($this->recordsAccess);
        $context = ['action' => 'crm'];
        if ($principal !== null) {
            $context += [
                'actor_id' => $this->actorId($principal),
                'role' => $principal->role,
                'identity_provider' => $principal->identityProvider,
            ];
            if ($principal->can('crm')) {
                $this->securityLogger->event('pickupsheet.records_access', $request, 'granted', $context);
                return $principal;
            }
            $this->securityLogger->event('pickupsheet.records_access', $request, 'forbidden', $context);
            return Response::html('You do not have permission to manage customer data.', 403, $this->privateHeaders());
        }

        $this->securityLogger->event('pickupsheet.records_access', $request, 'denied', $context);
        return Response::redirect($request->basePath . '/dhl/pickupsheet/login', 302);
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

    /** @return array<string, mixed> */
    private function common(Request $request, RecordsPrincipal $principal): array
    {
        return [
            'pageDescription' => 'Manage customer profiles and shipment relationships for Pickupsheet.',
            'pageRobots' => 'noindex, nofollow',
            'activePage' => 'pickupsheet',
            'basePath' => $request->basePath,
            'assetBase' => $request->basePath . '/public/assets',
            'csrfToken' => $this->csrf->token(),
            'recordsRole' => $principal->role,
            'recordsUsername' => $principal->username,
            'recordsFullName' => $principal->fullName(),
        ];
    }

    /** @return array<string, string> */
    private function privateHeaders(): array
    {
        return ['Cache-Control' => 'private, no-store, max-age=0', 'X-Robots-Tag' => 'noindex, nofollow'];
    }

    private function actorId(RecordsPrincipal $principal): string
    {
        return substr(hash('sha256', $principal->username), 0, 24);
    }
}

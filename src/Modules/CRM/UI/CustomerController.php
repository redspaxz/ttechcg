<?php

declare(strict_types=1);

namespace App\Modules\CRM\UI;

use App\Modules\CRM\Application\CustomerService;
use App\Modules\CRM\Application\DuplicateCustomerException;
use App\Modules\CRM\Domain\CustomerProfile;
use App\Shared\Http\Request;
use App\Shared\Http\Response;
use App\Shared\Security\Csrf;
use App\Shared\Security\RateLimiter;
use App\Shared\Security\RecordsAccess;
use App\Shared\Security\RecordsPrincipal;
use App\Shared\Security\RecordsSession;
use App\Shared\Security\RecordsUserService;
use App\Shared\Security\SecurityLogger;
use App\Shared\Spreadsheet\XlsxWriter;
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
        private readonly ?RecordsUserService $recordsUsers = null,
    ) {
    }

    public function index(Request $request): Response
    {
        $principal = $this->authorize($request, 'crm_view');
        if ($principal instanceof Response) {
            return $principal;
        }

        $summary = ['customerCount' => 0, 'activeCount' => 0, 'attentionCount' => 0, 'followUpsDue' => 0];
        $directory = ['customers' => $this->emptyPage()] + $this->directoryFilterValues($request);
        $duplicateSuggestions = [];
        $recentMerges = [];
        $error = null;
        try {
            $summary = $this->service->summary();
            $directory = $this->customerDirectory($request, $principal);
            if ($principal->can('crm')) {
                $duplicateSuggestions = $this->service->duplicateSuggestions();
                $recentMerges = $this->service->recentMerges();
            }
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            $error = 'Customer data is unavailable. Apply the CRM migration and check the MySQL connection.';
        }

        $flash = $_SESSION['_crm_flash'] ?? null;
        $mergeError = $_SESSION['_crm_merge_error'] ?? null;
        unset($_SESSION['_crm_flash'], $_SESSION['_crm_merge_error']);
        if ($error === null && is_string($mergeError) && $mergeError !== '') {
            $error = $mergeError;
        }
        $body = $this->view->render('pickupsheet/customers', $this->common($request, $principal) + [
            'pageTitle' => 'Customer CRM',
            'summary' => $summary,
            'duplicateSuggestions' => $duplicateSuggestions,
            'canMergeCustomers' => $error === null && $principal->can('crm'),
            'recentMerges' => $recentMerges,
            'flash' => is_string($flash) ? $flash : null,
            'error' => $error,
        ] + $directory);
        return Response::html($body, 200, $this->privateHeaders());
    }

    public function page(Request $request): Response
    {
        $principal = $this->authorize($request, 'crm_view', false);
        if ($principal instanceof Response) {
            return $principal;
        }

        try {
            return Response::html(
                $this->view->renderPartial('pickupsheet/_customer-directory', $this->common($request, $principal) + $this->customerDirectory($request, $principal)),
                200,
                $this->privateHeaders(),
            );
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            return Response::html('Customer profiles could not be loaded.', 503, $this->privateHeaders());
        }
    }

    public function create(Request $request): Response
    {
        $principal = $this->authorize($request, 'crm');
        if ($principal instanceof Response) {
            return $principal;
        }
        try {
            // Synchronize once when the form opens so autocomplete requests can stay read-only.
            $this->service->synchronize();
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
        }
        return $this->form($request, $principal, null, true);
    }

    public function search(Request $request): Response
    {
        $principal = $this->authorize($request, 'crm_view', false);
        if ($principal instanceof Response) {
            return $principal;
        }
        try {
            $retryAfter = $this->rateLimiter->consume('pickup-crm-search', $request->clientIdentifier(), 180, 300);
            if ($retryAfter > 0) {
                return Response::json(['suggestions' => []], 429, $this->privateHeaders() + ['Retry-After' => (string) $retryAfter]);
            }
            $query = $request->queryString('q');
            $existing = $this->service->existingCustomer($query);
            return Response::json([
                'suggestions' => $this->service->suggestions($query, 12),
                'existing' => $existing === null ? null : [
                    'name' => $existing['customer']->displayName,
                    'alias' => $existing['alias'],
                    'url' => $this->profileUrl($request, $existing['customer']->customerKey),
                    // Summary for the profile merge preview.
                    'key' => $existing['customer']->customerKey,
                    'reference' => $existing['customer']->reference(),
                    'status' => $existing['customer']->status,
                    'contactName' => $existing['customer']->contactName,
                    'shipmentCount' => $existing['customer']->shipmentCount,
                    'lastShipmentOn' => $existing['customer']->lastShipmentOn,
                ],
            ], 200, $this->privateHeaders());
        } catch (InvalidArgumentException $exception) {
            return Response::json(['suggestions' => [], 'message' => $exception->getMessage()], 422, $this->privateHeaders());
        } catch (RuntimeException $exception) {
            error_log('CRM customer search failed: ' . $exception->getMessage());
            return Response::json(['suggestions' => []], 503, $this->privateHeaders());
        }
    }

    public function edit(Request $request): Response
    {
        $principal = $this->authorize($request, 'crm_view');
        if ($principal instanceof Response) {
            return $principal;
        }

        try {
            $customer = $this->service->find(strtolower($request->queryString('customer')));
            if ($customer === null) {
                return Response::html('Customer profile not found.', 404, $this->privateHeaders());
            }
            // Profiles open as read-only details; ?mode=edit switches to the form.
            return $this->form($request, $principal, $customer, $request->queryString('mode') === 'edit');
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            return Response::html('Customer data is temporarily unavailable.', 503, $this->privateHeaders());
        }
    }

    public function shipmentPage(Request $request): Response
    {
        return $this->customerTablePage($request, 'shipments');
    }

    public function pointsPage(Request $request): Response
    {
        return $this->customerTablePage($request, 'points');
    }

    public function save(Request $request): Response
    {
        $principal = $this->authorize($request, 'crm_update');
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
        $canEditNames = $principal->can('crm');
        if (!$canEditNames && preg_match('/^[a-f0-9]{64}$/', $key) !== 1) {
            $this->log($request, $principal, 'pickupsheet.crm_customer_save', 'denied', ['reason' => 'existing_profile_required']);
            return Response::html('Operators may only update existing customer profiles.', 403, $this->privateHeaders());
        }
        $input = [
            'email' => $request->input('email'),
            'phone' => $request->input('phone'),
            'address' => $request->input('address'),
            'city' => $request->input('city'),
            'country_code' => $request->input('country_code'),
            'status' => $request->input('status'),
            'notes' => $request->rawInput('notes'),
            'next_follow_up_on' => $request->input('next_follow_up_on'),
            'expected_updated_at' => $request->input('expected_updated_at'),
        ];
        if ($canEditNames) {
            $input['display_name'] = $request->input('display_name');
            $input['contact_name'] = $request->input('contact_name');
        }
        $_SESSION['_crm_old'] = $input;

        try {
            $owner = $canEditNames ? $this->resolveOwner($request->input('owner'), $principal) : null;
            if ($owner !== null) {
                $input['owner_actor_id'] = $owner['actorId'];
                $input['owner_name'] = $owner['name'];
            }
            $previousProfile = $canEditNames && preg_match('/^[a-f0-9]{64}$/', $key) === 1
                ? $this->service->find($key)
                : null;
            $previousDisplayName = $previousProfile?->displayName;
            $saved = $canEditNames
                ? $this->service->save($key === '' ? null : $key, $input, $this->actorId($principal))
                : $this->service->updateDetailsWithoutNames($key, $input, $this->actorId($principal));
            $nameChanged = is_string($previousDisplayName)
                && trim($previousDisplayName) !== trim($saved->displayName);
            unset($_SESSION['_crm_old'], $_SESSION['_crm_errors']);
            $_SESSION['_crm_flash'] = $nameChanged
                ? sprintf(
                    'Customer profile saved. %s now appears as the consignor on %s existing pickup-sheet shipment%s.',
                    $saved->displayName,
                    number_format($previousProfile?->shipmentCount ?? 0),
                    ($previousProfile?->shipmentCount ?? 0) === 1 ? '' : 's',
                )
                : 'Customer profile saved.';
            $this->log($request, $principal, 'pickupsheet.crm_customer_save', 'accepted', [
                'resource_id' => substr($saved->customerKey, 0, 24),
                'customer_status' => $saved->status,
                'source' => $saved->source,
                'organization_name_changed' => $nameChanged,
                'shipments_renamed' => $nameChanged ? ($previousProfile?->shipmentCount ?? 0) : 0,
            ]);
            return Response::redirect($request->basePath . '/dhl/pickupsheet/customers/edit?customer=' . rawurlencode($saved->customerKey));
        } catch (InvalidArgumentException $exception) {
            $_SESSION['_crm_errors'] = [$exception->getMessage()];
            if ($exception instanceof DuplicateCustomerException) {
                $_SESSION['_crm_existing_customer'] = [
                    'name' => $exception->existing->displayName,
                    'url' => $this->profileUrl($request, $exception->existing->customerKey),
                ];
            }
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

    public function merge(Request $request): Response
    {
        $principal = $this->authorize($request, 'crm');
        if ($principal instanceof Response) {
            return $principal;
        }
        try {
            $retryAfter = $this->rateLimiter->consume('pickup-crm-merge', $request->clientIdentifier(), 20, 3600);
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            $this->log($request, $principal, 'pickupsheet.crm_customer_merge', 'failed');
            return Response::html('Customer merges are temporarily unavailable.', 503, $this->privateHeaders());
        }
        if ($retryAfter > 0) {
            $this->log($request, $principal, 'pickupsheet.crm_customer_merge', 'rate_limited', ['retry_after' => $retryAfter]);
            return Response::html('Too many customer merges. Please try again later.', 429, $this->privateHeaders() + ['Retry-After' => (string) $retryAfter]);
        }
        if (!$this->csrf->validate($request->input('_token'))) {
            $this->log($request, $principal, 'pickupsheet.crm_customer_merge', 'denied', ['reason' => 'csrf']);
            return Response::html('Invalid or expired form token.', 419, $this->privateHeaders());
        }

        $targetKey = strtolower($request->input('target_customer_key'));
        $sourceKey = strtolower($request->input('source_customer_key'));
        // The profile page merges a duplicate picked by name, for pairs the suggestions list does not detect.
        $fromProfile = $request->input('return_to') === 'profile';
        try {
            if ($sourceKey === '' && $request->input('source_customer_name') !== '') {
                $sourceName = $request->input('source_customer_name');
                $source = $this->service->existingCustomer($sourceName);
                if ($source === null) {
                    throw new InvalidArgumentException(sprintf('No customer profile named "%s" was found. Pick a name from the suggestions.', $sourceName));
                }
                $sourceKey = $source['customer']->customerKey;
            }
            $targetName = $this->service->find($targetKey)?->displayName;
            $sourceName = $this->service->find($sourceKey)?->displayName;
            $merged = $this->service->merge($targetKey, $sourceKey, $this->actorId($principal), $fromProfile);
            $_SESSION['_crm_flash'] = sprintf(
                'Merged %s into %s. Shipments, rewards, and available contact details were preserved.',
                $sourceName ?? 'the duplicate profile',
                $targetName ?? $merged->displayName,
            );
            $this->log($request, $principal, 'pickupsheet.crm_customer_merge', 'accepted', [
                'resource_id' => substr($merged->customerKey, 0, 24),
                'merged_resource_id' => substr($sourceKey, 0, 24),
            ]);
        } catch (InvalidArgumentException $exception) {
            if ($fromProfile) {
                $_SESSION['_crm_errors'] = [$exception->getMessage()];
            } else {
                $_SESSION['_crm_merge_error'] = $exception->getMessage();
            }
            $this->log($request, $principal, 'pickupsheet.crm_customer_merge', 'denied', ['reason' => 'validation']);
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            $message = 'The customer profiles could not be merged. Check MySQL and try again.';
            if ($fromProfile) {
                $_SESSION['_crm_errors'] = [$message];
            } else {
                $_SESSION['_crm_merge_error'] = $message;
            }
            $this->log($request, $principal, 'pickupsheet.crm_customer_merge', 'failed');
        }
        if ($fromProfile && preg_match('/^[a-f0-9]{64}$/', $targetKey) === 1) {
            return Response::redirect($request->basePath . '/dhl/pickupsheet/customers/edit?customer=' . rawurlencode($targetKey));
        }
        return Response::redirect($request->basePath . '/dhl/pickupsheet/customers');
    }

    public function undoMerge(Request $request): Response
    {
        $principal = $this->authorize($request, 'crm');
        if ($principal instanceof Response) {
            return $principal;
        }
        try {
            $retryAfter = $this->rateLimiter->consume('pickup-crm-merge', $request->clientIdentifier(), 20, 3600);
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            $this->log($request, $principal, 'pickupsheet.crm_customer_merge_undo', 'failed');
            return Response::html('Customer merges are temporarily unavailable.', 503, $this->privateHeaders());
        }
        if ($retryAfter > 0) {
            $this->log($request, $principal, 'pickupsheet.crm_customer_merge_undo', 'rate_limited', ['retry_after' => $retryAfter]);
            return Response::html('Too many customer merges. Please try again later.', 429, $this->privateHeaders() + ['Retry-After' => (string) $retryAfter]);
        }
        if (!$this->csrf->validate($request->input('_token'))) {
            $this->log($request, $principal, 'pickupsheet.crm_customer_merge_undo', 'denied', ['reason' => 'csrf']);
            return Response::html('Invalid or expired form token.', 419, $this->privateHeaders());
        }

        try {
            $restored = $this->service->undoMerge($request->input('merge_id'), $this->actorId($principal));
            $_SESSION['_crm_flash'] = sprintf('Merge undone. %s is a separate customer profile again.', $restored->displayName);
            $this->log($request, $principal, 'pickupsheet.crm_customer_merge_undo', 'accepted', [
                'resource_id' => substr($restored->customerKey, 0, 24),
            ]);
        } catch (InvalidArgumentException $exception) {
            $_SESSION['_crm_merge_error'] = $exception->getMessage();
            $this->log($request, $principal, 'pickupsheet.crm_customer_merge_undo', 'denied', ['reason' => 'validation']);
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            $_SESSION['_crm_merge_error'] = 'The merge could not be undone. Check MySQL and try again.';
            $this->log($request, $principal, 'pickupsheet.crm_customer_merge_undo', 'failed');
        }
        return Response::redirect($request->basePath . '/dhl/pickupsheet/customers');
    }

    public function dismissMerge(Request $request): Response
    {
        $principal = $this->authorize($request, 'crm');
        if ($principal instanceof Response) {
            return $principal;
        }
        $guard = $this->guardWrite($request, $principal, 'pickupsheet.crm_customer_merge_dismiss', 'pickup-crm-merge', 20);
        if ($guard !== null) {
            return $guard;
        }

        try {
            $this->service->dismissMerge($request->input('merge_id'), $this->actorId($principal));
            $_SESSION['_crm_flash'] = 'Merge kept. It was removed from Recent merges and can no longer be undone.';
            $this->log($request, $principal, 'pickupsheet.crm_customer_merge_dismiss', 'accepted');
        } catch (InvalidArgumentException $exception) {
            $_SESSION['_crm_merge_error'] = $exception->getMessage();
            $this->log($request, $principal, 'pickupsheet.crm_customer_merge_dismiss', 'denied', ['reason' => 'validation']);
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            $_SESSION['_crm_merge_error'] = 'The merge could not be updated. Check MySQL and try again.';
            $this->log($request, $principal, 'pickupsheet.crm_customer_merge_dismiss', 'failed');
        }
        return Response::redirect($request->basePath . '/dhl/pickupsheet/customers');
    }

    public function addActivity(Request $request): Response
    {
        $principal = $this->authorize($request, 'crm_update');
        if ($principal instanceof Response) {
            return $principal;
        }
        $guard = $this->guardWrite($request, $principal, 'pickupsheet.crm_customer_activity', 'pickup-crm-write', 60);
        if ($guard !== null) {
            return $guard;
        }

        $key = strtolower($request->input('customer_key'));
        $input = [
            'activity_type' => $request->input('activity_type'),
            'occurred_on' => $request->input('occurred_on'),
            'summary' => $request->rawInput('summary'),
            'next_follow_up_on' => $request->input('next_follow_up_on'),
        ];
        try {
            $this->service->addActivity($key, $input, $this->actorId($principal), $principal->fullName());
            $_SESSION['_crm_flash'] = 'Activity logged.';
            $this->log($request, $principal, 'pickupsheet.crm_customer_activity', 'accepted', ['resource_id' => substr($key, 0, 24)]);
        } catch (InvalidArgumentException $exception) {
            $_SESSION['_crm_activity_old'] = $input;
            $_SESSION['_crm_errors'] = [$exception->getMessage()];
            $this->log($request, $principal, 'pickupsheet.crm_customer_activity', 'denied', ['reason' => 'validation']);
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            $_SESSION['_crm_errors'] = ['The activity could not be saved. Check MySQL and try again.'];
            $this->log($request, $principal, 'pickupsheet.crm_customer_activity', 'failed');
        }
        return Response::redirect(preg_match('/^[a-f0-9]{64}$/', $key) === 1
            ? $this->profileUrl($request, $key)
            : $request->basePath . '/dhl/pickupsheet/customers');
    }

    public function delete(Request $request): Response
    {
        $principal = $this->authorize($request, 'crm');
        if ($principal instanceof Response) {
            return $principal;
        }
        $guard = $this->guardWrite($request, $principal, 'pickupsheet.crm_customer_delete', 'pickup-crm-delete', 20);
        if ($guard !== null) {
            return $guard;
        }

        $key = strtolower($request->input('customer_key'));
        try {
            $customer = $this->service->find($key);
            $this->service->delete($key, $this->actorId($principal));
            $_SESSION['_crm_flash'] = sprintf(
                'Customer profile %s and its contact history, rewards, and merge records were deleted. Pickup sheets keep the consignor name as part of the operational record.',
                $customer?->displayName ?? '',
            );
            $this->log($request, $principal, 'pickupsheet.crm_customer_delete', 'accepted', ['resource_id' => substr($key, 0, 24)]);
            return Response::redirect($request->basePath . '/dhl/pickupsheet/customers');
        } catch (InvalidArgumentException $exception) {
            $_SESSION['_crm_errors'] = [$exception->getMessage()];
            $this->log($request, $principal, 'pickupsheet.crm_customer_delete', 'denied', ['reason' => 'validation']);
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            $_SESSION['_crm_errors'] = ['The customer profile could not be deleted. Check MySQL and try again.'];
            $this->log($request, $principal, 'pickupsheet.crm_customer_delete', 'failed');
        }
        return Response::redirect(preg_match('/^[a-f0-9]{64}$/', $key) === 1
            ? $this->profileUrl($request, $key)
            : $request->basePath . '/dhl/pickupsheet/customers');
    }

    public function export(Request $request): Response
    {
        $principal = $this->authorize($request, 'crm_view');
        if ($principal instanceof Response) {
            return $principal;
        }
        if (!$principal->can('export')) {
            $this->log($request, $principal, 'pickupsheet.crm_customer_export', 'denied', ['reason' => 'permission']);
            return Response::html('You do not have permission to export customer data.', 403, $this->privateHeaders());
        }
        try {
            $retryAfter = $this->rateLimiter->consume('pickup-crm-export', $request->clientIdentifier(), 10, 3600);
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            return Response::html('Customer export is temporarily unavailable.', 503, $this->privateHeaders());
        }
        if ($retryAfter > 0) {
            $this->log($request, $principal, 'pickupsheet.crm_customer_export', 'rate_limited', ['retry_after' => $retryAfter]);
            return Response::html('Too many customer exports. Please try again later.', 429, $this->privateHeaders() + ['Retry-After' => (string) $retryAfter]);
        }

        try {
            $result = $this->service->exportRows($this->directoryFilters($request, $principal));
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            return Response::html('Customer data is temporarily unavailable.', 503, $this->privateHeaders());
        }
        $statusLabels = ['lead' => 'Lead', 'active' => 'Active', 'attention' => 'Needs attention', 'inactive' => 'Inactive'];
        $rows = array_map(static fn (CustomerProfile $customer): array => [
            $customer->reference(),
            $customer->displayName,
            $statusLabels[$customer->status] ?? $customer->status,
            $customer->contactName,
            $customer->email,
            $customer->phone,
            $customer->city,
            $customer->address,
            $customer->assignedName,
            $customer->shipmentCount,
            $customer->totalCashXaf,
            $customer->rewardBalance(),
            $customer->lastShipmentOn ?? '',
            $customer->nextFollowUpOn ?? '',
        ], $result['items']);
        $this->log($request, $principal, 'pickupsheet.crm_customer_export', 'accepted', [
            'row_count' => count($rows),
            'truncated' => $result['totalRecords'] > count($rows),
        ]);

        return Response::download(
            (new XlsxWriter())->create(
                ['Customer ID', 'Customer', 'Status', 'Contact', 'Email', 'Phone', 'City', 'Address', 'Owner', 'Shipments', 'Shipment value (XAF)', 'Reward points', 'Last shipment', 'Next follow-up'],
                $rows,
                'TOTAL SHIPMENT VALUE',
                10,
                array_sum(array_map(static fn (CustomerProfile $customer): int => $customer->totalCashXaf, $result['items'])),
                [14, 32, 16, 24, 28, 18, 16, 28, 22, 12, 20, 14, 16, 16],
            ),
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'crm-customers-' . gmdate('Y-m-d') . '.xlsx',
        );
    }

    public function adjustRewards(Request $request): Response
    {
        $principal = $this->authorize($request, 'crm');
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
                $principal->fullName(),
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
            $_SESSION['_crm_reward_form_open'] = true;
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

    private function form(Request $request, RecordsPrincipal $principal, ?CustomerProfile $customer, bool $editing): Response
    {
        $old = $_SESSION['_crm_old'] ?? [];
        $errors = $_SESSION['_crm_errors'] ?? [];
        $flash = $_SESSION['_crm_flash'] ?? null;
        $existingCustomer = $_SESSION['_crm_existing_customer'] ?? null;
        // A rejected save returns with the submitted values, so reopen the form to show them.
        $editing = $editing || (is_array($old) && $old !== []);
        unset($_SESSION['_crm_old'], $_SESSION['_crm_errors'], $_SESSION['_crm_flash'], $_SESSION['_crm_existing_customer']);
        $activityOld = $_SESSION['_crm_activity_old'] ?? [];
        $rewardFormOpen = (bool) ($_SESSION['_crm_reward_form_open'] ?? false);
        unset($_SESSION['_crm_activity_old'], $_SESSION['_crm_reward_form_open']);
        $activities = [];
        $activityCount = 0;
        $showAllActivity = $request->queryString('activity') === 'all';
        $shipments = $this->emptyPage();
        $rewardHistory = $this->emptyPage();
        if ($customer !== null) {
            try {
                $shipments = $this->service->paginatedShipments(
                    $customer->customerKey,
                    $this->pageNumber($request, 'shipment_page'),
                    $this->pageSize($request, 'shipment_per_page'),
                );
                $activities = $this->service->activities($customer->customerKey, $showAllActivity ? 100 : 10);
                $activityCount = $this->service->activityCount($customer->customerKey);
                $rewardHistory = $this->service->paginatedRewardHistory(
                    $customer->customerKey,
                    $this->pageNumber($request, 'points_page'),
                    $this->pageSize($request, 'points_per_page'),
                );
            } catch (RuntimeException $exception) {
                error_log($exception->__toString());
                $errors = [...(is_array($errors) ? $errors : []), 'Shipment or reward history could not be loaded.'];
            }
        }

        $body = $this->view->render('pickupsheet/customer-form', $this->common($request, $principal) + [
            'pageTitle' => $customer === null ? 'Add CRM customer' : 'Customer profile',
            'customer' => $customer,
            'shipments' => $shipments,
            'rewardHistory' => $rewardHistory,
            'actorNames' => $this->actorNames($principal),
            'old' => is_array($old) ? $old : [],
            'errors' => is_array($errors) ? $errors : [],
            'flash' => is_string($flash) ? $flash : null,
            'existingCustomer' => is_array($existingCustomer) ? $existingCustomer : null,
            'editing' => $editing && $principal->can('crm_update'),
            'activities' => $activities,
            'activityCount' => $activityCount,
            'rewardFormOpen' => $rewardFormOpen,
            'showAllActivity' => $showAllActivity,
            'activityOld' => is_array($activityOld) ? $activityOld : [],
            'activityTypes' => CustomerService::ACTIVITY_TYPES,
            'ownerOptions' => $principal->can('crm') ? $this->ownerOptions($principal) : [],
            'canDeleteCustomer' => $principal->can('crm'),
            'canUpdateCustomer' => $principal->can('crm_update'),
            'canCreateCustomers' => $principal->can('crm'),
            'canEditCustomerNames' => $principal->can('crm'),
            'canAdjustRewards' => $principal->can('crm'),
        ]);
        return Response::html($body, 200, $this->privateHeaders());
    }

    private function authorize(Request $request, string $permission, bool $logGranted = true): RecordsPrincipal|Response
    {
        $principal = $this->recordsSession->principal($this->recordsAccess);
        $context = ['action' => $permission];
        if ($principal !== null) {
            $context += [
                'actor_id' => $this->actorId($principal),
                'role' => $principal->role,
                'identity_provider' => $principal->identityProvider,
            ];
            if ($principal->can($permission)) {
                if ($logGranted) {
                    $this->securityLogger->event('pickupsheet.records_access', $request, 'granted', $context);
                }
                return $principal;
            }
            $this->securityLogger->event('pickupsheet.records_access', $request, 'forbidden', $context);
            return Response::html('You do not have permission to access this customer function.', 403, $this->privateHeaders());
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
            'canCreateCustomers' => $principal->can('crm'),
            'canEditCustomerNames' => $principal->can('crm'),
            'canAdjustRewards' => $principal->can('crm'),
            'canExportCustomers' => $principal->can('export'),
        ];
    }

    /** @return array<string, string> */
    private function privateHeaders(): array
    {
        return ['Cache-Control' => 'private, no-store, max-age=0', 'X-Robots-Tag' => 'noindex, nofollow'];
    }

    private function profileUrl(Request $request, string $customerKey): string
    {
        return $request->basePath . '/dhl/pickupsheet/customers/edit?customer=' . rawurlencode($customerKey);
    }

    private function actorId(RecordsPrincipal $principal): string
    {
        return substr(hash('sha256', $principal->username), 0, 24);
    }

    /** @return array<string, mixed> */
    private function customerDirectory(Request $request, RecordsPrincipal $principal): array
    {
        return [
            'customers' => $this->service->paginated($this->directoryFilters($request, $principal), $this->pageNumber($request), $this->pageSize($request)),
        ] + $this->directoryFilterValues($request);
    }

    /** @return array<string, string> filter values as the service expects them */
    private function directoryFilters(Request $request, RecordsPrincipal $principal): array
    {
        $values = $this->directoryFilterValues($request);
        return [
            'search' => $values['search'],
            'status' => $values['statusFilter'],
            'follow_up' => $values['followUpFilter'],
            'owner' => $values['ownerFilter'] === 'me' ? $this->actorId($principal) : $values['ownerFilter'],
            'sort' => $values['sortOrder'],
        ];
    }

    /** @return array{search: string, statusFilter: string, followUpFilter: string, ownerFilter: string, sortOrder: string, sortOptions: array<string, string>} */
    private function directoryFilterValues(Request $request): array
    {
        $owner = $request->queryString('owner');
        $sort = $request->queryString('sort');
        return [
            'search' => $request->queryString('q'),
            'statusFilter' => $request->queryString('status'),
            'followUpFilter' => in_array($request->queryString('follow_up'), ['due', 'scheduled', 'none'], true) ? $request->queryString('follow_up') : '',
            'ownerFilter' => in_array($owner, ['me', 'unassigned'], true) ? $owner : '',
            'sortOrder' => array_key_exists($sort, CustomerService::SORT_OPTIONS) && $sort !== 'priority' ? $sort : '',
            'sortOptions' => CustomerService::SORT_OPTIONS,
        ];
    }

    /**
     * People a customer can be assigned to: the signed-in administrator and every active local
     * operator or administrator account.
     *
     * @return array<string, string> actor id => display name
     */
    private function ownerOptions(RecordsPrincipal $principal): array
    {
        $options = [$this->actorId($principal) => $principal->fullName()];
        if ($this->recordsUsers !== null) {
            try {
                foreach ($this->recordsUsers->accounts($principal) as $account) {
                    if ($account->active && in_array($account->role, ['operator', 'admin'], true)) {
                        $name = trim($account->firstName . ' ' . $account->lastName);
                        $options[substr(hash('sha256', $account->username), 0, 24)] = $name !== '' ? $name : $account->username;
                    }
                }
            } catch (RuntimeException|InvalidArgumentException $exception) {
                error_log('CRM owner options could not be loaded: ' . $exception->getMessage());
            }
        }
        asort($options, SORT_NATURAL | SORT_FLAG_CASE);
        return $options;
    }

    /**
     * @param string $selection an actor id, "none" to unassign, or empty when the form had no owner field
     * @return array{actorId: string, name: string}|null null keeps the current owner
     */
    private function resolveOwner(string $selection, RecordsPrincipal $principal): ?array
    {
        if ($selection === '') {
            return null;
        }
        if ($selection === 'none') {
            return ['actorId' => '', 'name' => ''];
        }
        $options = $this->ownerOptions($principal);
        if (!isset($options[$selection])) {
            throw new InvalidArgumentException('Select a valid customer owner.');
        }
        return ['actorId' => $selection, 'name' => $options[$selection]];
    }

    /**
     * Names for the account codes stored on older reward entries, which predate recorded names.
     *
     * @return array<string, string> actor id => display name
     */
    private function actorNames(RecordsPrincipal $principal): array
    {
        return $principal->can('crm') ? $this->ownerOptions($principal) : [$this->actorId($principal) => $principal->fullName()];
    }

    /** Rate limit and CSRF checks shared by the CRM write actions. */
    private function guardWrite(Request $request, RecordsPrincipal $principal, string $event, string $bucket, int $limit): ?Response
    {
        try {
            $retryAfter = $this->rateLimiter->consume($bucket, $request->clientIdentifier(), $limit, 3600);
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            $this->log($request, $principal, $event, 'failed');
            return Response::html('Customer updates are temporarily unavailable.', 503, $this->privateHeaders());
        }
        if ($retryAfter > 0) {
            $this->log($request, $principal, $event, 'rate_limited', ['retry_after' => $retryAfter]);
            return Response::html('Too many customer updates. Please try again later.', 429, $this->privateHeaders() + ['Retry-After' => (string) $retryAfter]);
        }
        if (!$this->csrf->validate($request->input('_token'))) {
            $this->log($request, $principal, $event, 'denied', ['reason' => 'csrf']);
            return Response::html('Invalid or expired form token.', 419, $this->privateHeaders());
        }
        return null;
    }

    private function customerTablePage(Request $request, string $table): Response
    {
        $principal = $this->authorize($request, 'crm_view', false);
        if ($principal instanceof Response) {
            return $principal;
        }
        $customerKey = strtolower($request->queryString('customer'));
        try {
            if ($this->service->find($customerKey) === null) {
                return Response::html('Customer profile not found.', 404, $this->privateHeaders());
            }
            if ($table === 'shipments') {
                $data = [
                    'shipments' => $this->service->paginatedShipments($customerKey, $this->pageNumber($request, 'shipment_page'), $this->pageSize($request, 'shipment_per_page')),
                    'customerKey' => $customerKey,
                    'currentPointsPage' => $this->pageNumber($request, 'points_page'),
                ];
                $template = 'pickupsheet/_customer-shipments';
            } else {
                $data = [
                    'rewardHistory' => $this->service->paginatedRewardHistory($customerKey, $this->pageNumber($request, 'points_page'), $this->pageSize($request, 'points_per_page')),
                    'actorNames' => $this->actorNames($principal),
                    'customerKey' => $customerKey,
                    'currentShipmentPage' => $this->pageNumber($request, 'shipment_page'),
                ];
                $template = 'pickupsheet/_customer-points';
            }
            return Response::html(
                $this->view->renderPartial($template, $this->common($request, $principal) + $data),
                200,
                $this->privateHeaders(),
            );
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            return Response::html('Customer history could not be loaded.', 503, $this->privateHeaders());
        }
    }

    private function pageNumber(Request $request, string $parameter = 'page'): int
    {
        $page = $request->queryString($parameter, '1');
        return preg_match('/^[1-9][0-9]{0,8}$/', $page) === 1 ? (int) $page : 1;
    }

    private function pageSize(Request $request, string $parameter = 'per_page'): int
    {
        $size = $request->queryString($parameter, '10');
        return in_array($size, ['10', '25', '50'], true) ? (int) $size : 10;
    }

    /** @return array{items: array<never>, page: int, perPage: int, totalRecords: int, totalPages: int} */
    private function emptyPage(): array
    {
        return ['items' => [], 'page' => 1, 'perPage' => 10, 'totalRecords' => 0, 'totalPages' => 1];
    }
}

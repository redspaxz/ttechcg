<?php

declare(strict_types=1);

namespace App\Modules\Contact\UI;

use App\Modules\Contact\Application\InquiryService;
use App\Shared\Http\Request;
use App\Shared\Http\Response;
use App\Shared\Security\Captcha;
use App\Shared\Security\Csrf;
use App\Shared\View\View;
use InvalidArgumentException;
use RuntimeException;

final class ContactController
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly InquiryService $service,
        private readonly View $view,
        private readonly Csrf $csrf,
        private readonly Captcha $captcha,
        private readonly array $config,
        private readonly string $storageMode,
        private readonly bool $contactOperational,
    ) {
    }

    public function index(Request $request): Response
    {
        $flash = $_SESSION['_flash'] ?? null;
        $errors = $_SESSION['_errors'] ?? [];
        $old = $_SESSION['_old'] ?? [];
        unset($_SESSION['_flash'], $_SESSION['_errors'], $_SESSION['_old']);

        return Response::html($this->view->render('contact/index', $this->viewData($request, [
            'pageTitle' => 'Start a conversation',
            'pageDescription' => 'Contact T&Tech in Bamenda or Douala, Cameroon, for network outsourcing, managed infrastructure, and technology solutions.',
            'activePage' => 'contact',
            'csrfToken' => $this->csrf->token(),
            'captcha' => $this->captcha->issue(),
            'flash' => $flash,
            'errors' => is_array($errors) ? $errors : [],
            'old' => is_array($old) ? $old : [],
            'contactOperational' => $this->contactOperational,
        ])));
    }

    public function store(Request $request): Response
    {
        if (!$this->csrf->validate($request->input('_token'))) {
            return Response::html('Invalid or expired form token.', 419);
        }

        $input = [
            'name' => $request->input('name'),
            'email' => $request->input('email'),
            'company' => $request->input('company'),
            'service' => $request->input('service'),
            'message' => $request->input('message'),
            'privacy_consent' => $request->input('privacy_consent'),
        ];

        if (!$this->contactOperational) {
            $_SESSION['_errors'] = ['Online inquiries are temporarily unavailable. Please try again later.'];
            $_SESSION['_old'] = $input;
            return Response::redirect($request->basePath . '/contact');
        }

        if ($request->input('website') !== '') {
            $_SESSION['_flash'] = 'Thanks. Your message has been received.';
            return Response::redirect($request->basePath . '/contact');
        }

        if (!$this->captcha->validate($request->input('captcha_nonce'), $request->input('captcha_answer'))) {
            $_SESSION['_errors'] = ['Please complete the human verification with the correct answer.'];
            $_SESSION['_old'] = $input;
            return Response::redirect($request->basePath . '/contact');
        }

        $lastInquiryAt = (int) ($_SESSION['_last_inquiry_at'] ?? 0);
        if ($lastInquiryAt > 0 && time() - $lastInquiryAt < 15) {
            $_SESSION['_errors'] = ['Please wait a moment before sending another inquiry.'];
            $_SESSION['_old'] = $input;
            return Response::redirect($request->basePath . '/contact');
        }

        try {
            $this->service->submit($input);
            $_SESSION['_flash'] = 'Thanks. Your message has been received. Our team will follow up shortly.';
            $_SESSION['_last_inquiry_at'] = time();
        } catch (InvalidArgumentException $exception) {
            $_SESSION['_errors'] = [$exception->getMessage()];
            $_SESSION['_old'] = $input;
        } catch (RuntimeException $exception) {
            error_log($exception->__toString());
            $_SESSION['_errors'] = ['We could not save your inquiry. Please try again later.'];
            $_SESSION['_old'] = $input;
        }

        return Response::redirect($request->basePath . '/contact');
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function viewData(Request $request, array $data): array
    {
        return array_merge($data, [
            'basePath' => $request->basePath,
            'assetBase' => $request->basePath . '/public/assets',
            'config' => $this->config,
            'storageMode' => $this->storageMode,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Site\UI;

use App\Shared\Http\Request;
use App\Shared\Http\Response;
use App\Shared\View\View;

final class SiteController
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly View $view,
        private readonly array $config,
        private readonly string $storageMode,
        private readonly bool $contactOperational,
    ) {
    }

    public function home(Request $request): Response
    {
        return $this->page($request, 'site/home', 'Network outsourcing and managed solutions', 'home',
            'T&Tech keeps organisations connected and productive through network outsourcing, managed infrastructure, cloud security, and practical business solutions.');
    }

    public function services(Request $request): Response
    {
        return $this->page($request, 'site/services', 'Network and technology services', 'services',
            'Network outsourcing, managed infrastructure, cloud security, continuity, and business systems delivered by one accountable partner.');
    }

    public function products(Request $request): Response
    {
        return $this->page($request, 'site/products', 'Technology products for real operations', 'products',
            'Explore T&Tech products, including BTSPOS: a multi-agency ticketing and transport operations platform.');
    }

    public function about(Request $request): Response
    {
        return $this->page($request, 'site/about', 'An accountable technology operations partner', 'about',
            'T&Tech combines managed network operations and solution delivery to keep organisations connected, resilient, and ready to grow.');
    }

    public function privacy(Request $request): Response
    {
        return $this->page($request, 'site/privacy', 'Privacy notice', 'privacy',
            'How T&Tech Consulting Group handles information submitted through this website.');
    }

    public function health(Request $request): Response
    {
        $healthy = $this->config['environment'] !== 'production' || $this->contactOperational;

        return Response::json([
            'status' => $healthy ? 'ok' : 'degraded',
            'application' => $this->config['name'],
            'storage' => $this->storageMode,
            'contact' => $this->contactOperational ? 'operational' : 'unavailable',
            'time' => date(DATE_ATOM),
        ], $healthy ? 200 : 503);
    }

    public function notFound(Request $request): Response
    {
        return Response::html($this->view->render('site/not-found', [
            'pageTitle' => 'Page not found',
            'pageDescription' => 'The requested T&Tech page could not be found.',
            'activePage' => '',
            'basePath' => $request->basePath,
            'assetBase' => $request->basePath . '/public/assets',
            'config' => $this->config,
            'storageMode' => $this->storageMode,
        ]), 404);
    }

    private function page(Request $request, string $template, string $title, string $activePage, string $description): Response
    {
        return Response::html($this->view->render($template, [
            'pageTitle' => $title,
            'pageDescription' => $description,
            'activePage' => $activePage,
            'basePath' => $request->basePath,
            'assetBase' => $request->basePath . '/public/assets',
            'config' => $this->config,
            'storageMode' => $this->storageMode,
        ]));
    }
}

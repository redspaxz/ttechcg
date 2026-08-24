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
        return $this->page($request, 'site/home', 'Technology that moves the work forward', 'home',
            'T&Tech designs practical digital products, connected operations, and technology systems for ambitious organisations.');
    }

    public function services(Request $request): Response
    {
        return $this->page($request, 'site/services', 'Services built around outcomes', 'services',
            'Product engineering, workflow automation, data and cloud systems, and hands-on technical advisory.');
    }

    public function about(Request $request): Response
    {
        return $this->page($request, 'site/about', 'Clear thinking. Useful technology.', 'about',
            'T&Tech brings strategy and delivery into one accountable consulting partnership.');
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

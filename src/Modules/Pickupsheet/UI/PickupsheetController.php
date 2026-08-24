<?php

declare(strict_types=1);

namespace App\Modules\Pickupsheet\UI;

use App\Shared\Http\Request;
use App\Shared\Http\Response;
use App\Shared\View\View;

final class PickupsheetController
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly View $view,
        private readonly array $config,
        private readonly string $storageMode,
    ) {
    }

    public function show(Request $request): Response
    {
        return Response::html($this->view->render('pickupsheet/show', [
            'pageTitle' => 'Pickupsheet logistics operations',
            'pageDescription' => 'A focused collection-planning interface from T&Tech.',
            'pageRobots' => 'noindex, nofollow',
            'activePage' => 'pickupsheet',
            'basePath' => $request->basePath,
            'assetBase' => $request->basePath . '/public/assets',
            'config' => $this->config,
            'storageMode' => $this->storageMode,
        ]));
    }
}

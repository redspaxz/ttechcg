<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Throwable;

final class Application
{
    public function __construct(private readonly Router $router)
    {
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->router->dispatch($request);
        } catch (Throwable $exception) {
            error_log($exception->__toString());
            return Response::html('<h1>Something went wrong</h1><p>Please try again shortly.</p>', 500);
        }
    }
}


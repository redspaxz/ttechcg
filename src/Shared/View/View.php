<?php

declare(strict_types=1);

namespace App\Shared\View;

use RuntimeException;

final class View
{
    public function __construct(private readonly string $viewsPath)
    {
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = [], string $layout = 'layouts/app'): string
    {
        $content = $this->capture($template, $data);
        return $this->capture($layout, array_merge($data, ['content' => $content]));
    }

    /** @param array<string, mixed> $data */
    private function capture(string $template, array $data): string
    {
        $path = $this->viewsPath . '/' . $template . '.php';
        if (!is_file($path)) {
            throw new RuntimeException('View not found: ' . $template);
        }

        extract($data, EXTR_SKIP);
        ob_start();
        require $path;
        return (string) ob_get_clean();
    }
}


<?php

declare(strict_types=1);

namespace App\Modules\Pickupsheet\Infrastructure;

use App\Modules\Pickupsheet\Domain\CollectionAgentRepository;

final class DemoCollectionAgentRepository implements CollectionAgentRepository
{
    private const SESSION_KEY = '_demo_pickup_collection_agent';

    public function current(): array
    {
        $settings = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($settings)) {
            return ['name' => '', 'updatedAt' => null];
        }
        return [
            'name' => (string) ($settings['name'] ?? ''),
            'updatedAt' => is_string($settings['updatedAt'] ?? null) ? $settings['updatedAt'] : null,
        ];
    }

    public function save(string $name, string $actorId): array
    {
        $_SESSION[self::SESSION_KEY] = ['name' => $name, 'updatedAt' => gmdate('Y-m-d H:i:s')];
        return $this->current();
    }
}

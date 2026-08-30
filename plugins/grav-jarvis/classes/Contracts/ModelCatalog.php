<?php

declare(strict_types=1);

namespace Grav\Plugin\GravJarvis\Contracts;

use InvalidArgumentException;

final readonly class ModelCatalog
{
    public string $providerId;
    /** @var list<ModelDescriptor> */
    public array $models;

    /** @param list<ModelDescriptor> $models */
    public function __construct(string $providerId, array $models = [])
    {
        $providerId = trim($providerId);
        if (!CompletionRequest::validIdentifier($providerId)) {
            throw new InvalidArgumentException('Provider identifiers must be lowercase stable slugs.');
        }
        $indexed = [];
        foreach ($models as $model) {
            if (!$model instanceof ModelDescriptor) {
                throw new InvalidArgumentException('Model catalogs may contain only ModelDescriptor values.');
            }
            if (isset($indexed[$model->id])) {
                throw new InvalidArgumentException('Model identifiers must be unique within a provider catalog.');
            }
            $indexed[$model->id] = $model;
        }
        ksort($indexed, SORT_STRING);

        $this->providerId = $providerId;
        $this->models = array_values($indexed);
    }

    /** @return array{provider_id: string, models: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'provider_id' => $this->providerId,
            'models' => array_map(
                static fn (ModelDescriptor $model): array => $model->toArray(),
                $this->models
            ),
        ];
    }
}

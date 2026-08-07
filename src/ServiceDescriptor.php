<?php

declare(strict_types=1);

namespace App;

final class ServiceDescriptor
{
    /** @return array<string, mixed> */
    public static function payload(): array
    {
        return [
            'service' => 'paint',
            'description' => 'Manage member-owned visual artwork documents and provide a workspace for creating and editing drawings.',
            'supports' => ['drawing workspace', 'drawing documents', 'artwork', 'sketches', 'visual editing', 'paint documents'],
            'returns' => ['objects' => ['Paint Workspace', 'Paint Document']],
            'cost' => 'low',
            'side_effects' => ['creates objects', 'updates objects', 'stores member content'],
            'operations' => [
                'paint.create' => [
                    'description' => 'Create a new Paint document for drawing after the member selects a Paint-owned creation action.',
                    'supports' => ['new drawing', 'new sketch', 'paint document creation', 'start drawing workspace'],
                    'returns' => ['objects' => ['Paint Document']],
                    'cost' => 'low',
                    'side_effects' => ['creates objects', 'stores member content'],
                    'input_schema' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'width' => ['type' => 'integer'],
                            'height' => ['type' => 'integer'],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'paint.read' => [
                    'description' => 'Open an existing Paint document so the member can view or continue editing it.',
                    'supports' => ['open drawing', 'view drawing', 'continue editing', 'paint document'],
                    'returns' => ['objects' => ['Paint Document']],
                    'cost' => 'low',
                    'side_effects' => [],
                    'required' => ['document_id' => 'non_empty_string'],
                    'input_schema' => [
                        'type' => 'object',
                        'properties' => [
                            'document_id' => ['type' => 'string'],
                        ],
                        'required' => ['document_id'],
                        'additionalProperties' => false,
                    ],
                ],
                'paint.search' => [
                    'description' => 'Search Paint-owned visual artwork results, including the drawing workspace for broad creation intent and member documents by title or indexed drawing information.',
                    'supports' => ['paint workspace discovery', 'find drawings', 'search artwork', 'member paint documents'],
                    'returns' => ['objects' => ['Paint Workspace', 'Paint Document']],
                    'cost' => 'low',
                    'side_effects' => [],
                    'required' => ['text' => 'non_empty_string'],
                    'input_schema' => [
                        'type' => 'object',
                        'properties' => [
                            'text' => ['type' => 'string'],
                            'limit' => ['type' => 'integer'],
                        ],
                        'required' => ['text'],
                        'additionalProperties' => false,
                    ],
                ],
                'paint.list' => [
                    'description' => 'Show recent Paint documents owned by the member.',
                    'supports' => ['recent drawings', 'recent artwork', 'member paint documents'],
                    'returns' => ['objects' => ['Paint Document']],
                    'cost' => 'low',
                    'side_effects' => [],
                    'input_schema' => [
                        'type' => 'object',
                        'properties' => [
                            'limit' => ['type' => 'integer'],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'paint.draw' => [
                    'description' => 'Add a completed drawing stroke to an existing Paint document.',
                    'supports' => ['drawing edit', 'add stroke', 'continue drawing'],
                    'returns' => ['objects' => ['Paint Document']],
                    'cost' => 'low',
                    'side_effects' => ['updates objects', 'stores member content'],
                    'required' => ['document_id' => 'non_empty_string', 'stroke' => 'array'],
                    'target_field' => 'document_id',
                    'input_schema' => [
                        'type' => 'object',
                        'properties' => [
                            'document_id' => ['type' => 'string'],
                            'stroke' => ['type' => 'object'],
                        ],
                        'required' => ['document_id', 'stroke'],
                        'additionalProperties' => false,
                    ],
                ],
                'paint.rename' => [
                    'description' => 'Rename an existing Paint document.',
                    'supports' => ['rename drawing', 'change document title', 'paint document organization'],
                    'returns' => ['objects' => ['Paint Document']],
                    'cost' => 'low',
                    'side_effects' => ['updates objects'],
                    'required' => ['document_id' => 'non_empty_string', 'title' => 'non_empty_string'],
                    'target_field' => 'document_id',
                    'input_schema' => [
                        'type' => 'object',
                        'properties' => [
                            'document_id' => ['type' => 'string'],
                            'title' => ['type' => 'string'],
                        ],
                        'required' => ['document_id', 'title'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ];
    }
}

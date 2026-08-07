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
            'description' => 'Create, find, open, and edit drawing documents for member artwork.',
            'supports' => ['drawing documents', 'artwork', 'sketches', 'visual editing', 'paint documents'],
            'returns' => ['objects' => ['Paint Document']],
            'cost' => 'low',
            'side_effects' => ['creates objects', 'updates objects', 'stores member content'],
            'operations' => [
                'paint.create' => [
                    'description' => 'Create a new Paint document for drawing.',
                    'supports' => ['new drawing', 'new sketch', 'paint document creation'],
                    'returns' => ['objects' => ['Paint Document']],
                    'cost' => 'low',
                    'side_effects' => ['creates objects', 'stores member content'],
                ],
                'paint.read' => [
                    'description' => 'Open an existing Paint document so the member can view or continue editing it.',
                    'supports' => ['open drawing', 'view drawing', 'continue editing', 'paint document'],
                    'returns' => ['objects' => ['Paint Document']],
                    'cost' => 'low',
                    'side_effects' => [],
                    'required' => ['document_id' => 'non_empty_string'],
                ],
                'paint.search' => [
                    'description' => 'Search member Paint documents by title or drawing information.',
                    'supports' => ['find drawings', 'search artwork', 'member paint documents'],
                    'returns' => ['objects' => ['Paint Document']],
                    'cost' => 'low',
                    'side_effects' => [],
                    'required' => ['text' => 'non_empty_string'],
                ],
                'paint.list' => [
                    'description' => 'Show recent Paint documents owned by the member.',
                    'supports' => ['recent drawings', 'recent artwork', 'member paint documents'],
                    'returns' => ['objects' => ['Paint Document']],
                    'cost' => 'low',
                    'side_effects' => [],
                ],
                'paint.draw' => [
                    'description' => 'Add a completed drawing stroke to an existing Paint document.',
                    'supports' => ['drawing edit', 'add stroke', 'continue drawing'],
                    'returns' => ['objects' => ['Paint Document']],
                    'cost' => 'low',
                    'side_effects' => ['updates objects', 'stores member content'],
                    'required' => ['document_id' => 'non_empty_string', 'stroke' => 'array'],
                    'target_field' => 'document_id',
                ],
                'paint.rename' => [
                    'description' => 'Rename an existing Paint document.',
                    'supports' => ['rename drawing', 'change document title', 'paint document organization'],
                    'returns' => ['objects' => ['Paint Document']],
                    'cost' => 'low',
                    'side_effects' => ['updates objects'],
                    'required' => ['document_id' => 'non_empty_string', 'title' => 'non_empty_string'],
                    'target_field' => 'document_id',
                ],
            ],
        ];
    }
}

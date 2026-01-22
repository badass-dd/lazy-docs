<?php

return [
    'complexity_threshold' => 5,

    'exclude_patterns' => [
        '*/Api/Admin/*',
        '*/Web/*',
    ],

    'exclude_methods' => [
        '__construct',
        '__invoke',
        'middleware',
        'validate',
        'authorize',
    ],

    'examples' => [
        'id' => '1',
        'uuid' => '550e8400-e29b-41d4-a716-446655440000',
        'email' => 'user@example.com',
        'phone' => '+393331234567',
        'name' => 'John Doe',
        'status' => 'active',
        'price' => '99.99',
        'amount' => '100.00',
        'quantity' => '5',
        'date' => '2026-01-21',
        'date_from' => '2026-01-01',
        'date_to' => '2026-01-31',
        'sort_by' => 'created_at',
        'sort_dir' => 'desc',
        'per_page' => '15',
        'page' => '1',
        'q' => 'search term',
        'limit' => '10',
        'offset' => '0',
        'search' => 'search query',
        'filter' => 'filter value',
        'order' => 'created_at',
        'direction' => 'desc',
    ],

    'messages' => [
        'created' => 'Resource created successfully',
        'updated' => 'Resource updated successfully',
        'deleted' => 'Resource deleted successfully',
        'refunded' => 'Refund processed successfully',
    ],

    'include_implementation_notes' => true,

    'include_authorization_errors' => true,

    'include_rate_limit_info' => true,

    'include_relations' => true,

    'response_format' => 'json',

    'indent' => '    ',

    'max_line_length' => 120,
];

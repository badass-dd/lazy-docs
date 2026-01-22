<?php

namespace Badass\ControllerPhpDocGenerator;

use Faker\Factory as FakerFactory;
use Faker\Generator as FakerGenerator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Controller;
use ReflectionClass;
use ReflectionMethod;

class ControllerDocBlockGenerator
{
    private string $controllerClass;

    private string $methodName;

    private ReflectionMethod $reflectionMethod;

    private ReflectionClass $reflectionClass;

    private FakerGenerator $faker;

    public array $metadata = [
        'complexity_score' => 0,
        'parameters' => [],
        'responses' => [],
        'exceptions' => [],
        'middleware' => [],
        'validations' => [],
        'model_fields' => [],
        'model_relations' => [],
    ];

    private string $methodContent = '';

    private string $controllerContent = '';

    private ?string $modelClass = null;

    public function __construct(string $controllerClass, string $methodName)
    {
        $this->controllerClass = $controllerClass;
        $this->methodName = $methodName;
        $this->faker = FakerFactory::create();

        $this->reflectionClass = new ReflectionClass($controllerClass);
        $this->reflectionMethod = $this->reflectionClass->getMethod($methodName);

        $this->extractMethodContent();
        $this->extractControllerContent();
        $this->analyzeMethod();
        $this->extractValidationRules();
        $this->extractMiddleware();
        $this->extractErrorResponses();
        $this->extractModelInfo();
    }

    /**
     * Generate PHPDoc for method
     */
    public function generate(): string
    {
        $lines = [];
        $lines[] = '/**';

        // Title and Description first
        $description = $this->generateDescription();
        $lines[] = ' * '.$description['title'];
        $lines[] = ' *';

        if (! empty($description['details'])) {
            foreach (explode("\n", $description['details']) as $detail) {
                $lines[] = ' * '.$detail;
            }
            $lines[] = ' *';
        }

        // Implementation notes (transaction warnings, etc.) - before @group
        $noteLines = $this->generateImplementationNotes();
        if (! empty($noteLines)) {
            foreach ($noteLines as $note) {
                $lines[] = ' * '.$note;
            }
            $lines[] = ' *';
        }

        // @group tag (from comment or resource name) - after description and notes
        $group = $this->extractGroupName();
        if ($group) {
            $lines[] = ' * @group '.$group;
            $lines[] = ' *';
        }

        // Authentication
        if (in_array('auth:sanctum', $this->metadata['middleware']) ||
            in_array('auth:api', $this->metadata['middleware']) ||
            in_array('auth', $this->metadata['middleware'])) {
            $lines[] = ' * @authenticated';
            $lines[] = ' *';
        }

        // API endpoint
        $httpMethod = $this->detectHttpMethod();
        $endpoint = $this->detectEndpoint();
        $lines[] = ' * @api {'.strtolower($httpMethod).'} '.$endpoint.' '.$this->getTitleForMethod();
        $lines[] = ' *';

        // Parameters (using correct param type based on HTTP method)
        $paramLines = $this->generateParameterDocs();
        $lines = array_merge($lines, $paramLines);

        // Responses
        $responseLines = $this->generateResponseDocs();
        $lines = array_merge($lines, $responseLines);

        $lines[] = ' */';

        return implode("\n", $lines);
    }

    /**
     * Generate method description
     */
    private function generateDescription(): array
    {
        $methodName = $this->methodName;
        $patterns = [];

        // Detect pattern from method name and content
        if (preg_match('/^(index|all|list|get)$/i', $methodName)) {
            $patterns[] = $this->analyzeListMethod();
        } elseif (preg_match('/^(show|find|retrieve|detail)$/i', $methodName)) {
            $patterns[] = $this->analyzeShowMethod();
        } elseif (preg_match('/^(store|create|add|save)$/i', $methodName)) {
            $patterns[] = $this->analyzeStoreMethod();
        } elseif (preg_match('/^(update|edit|modify)$/i', $methodName)) {
            $patterns[] = $this->analyzeUpdateMethod();
        } elseif (preg_match('/^(destroy|delete|remove)$/i', $methodName)) {
            $patterns[] = $this->analyzeDestroyMethod();
        } else {
            $patterns[] = $this->analyzeCustomMethod();
        }

        $title = $patterns[0]['title'] ?? "Manage {$this->getResourceName()} resource";
        $details = $patterns[0]['details'] ?? '';

        return [
            'title' => $title,
            'details' => $details,
        ];
    }

    /**
     * Extract validation rules from $request->validate() or FormRequest
     */
    private function extractValidationRules(): void
    {
        // Match validate() call with potentially complex array content (handles nested brackets)
        // Pattern matches: $request->validate([ ... ]) or $validator = $request->validate([ ... ])
        if (preg_match('/\$(?:request|validator|\w+)\s*(?:=\s*\$request\s*)?->\s*validate\s*\(\s*\[/s', $this->methodContent)) {
            // Find the start of the array
            $start = strpos($this->methodContent, '->validate([');
            if ($start !== false) {
                $start = strpos($this->methodContent, '[', $start);
                $rulesContent = $this->extractBalancedBrackets($this->methodContent, $start);
                if ($rulesContent) {
                    $this->parseValidationRules($rulesContent);
                }
            }
        }
        // Check for FormRequest in method parameters
        else {
            $params = $this->reflectionMethod->getParameters();
            foreach ($params as $param) {
                $paramType = $param->getType();
                if ($paramType && ! $paramType->isBuiltin()) {
                    $className = $paramType->getName();

                    // Try to resolve the full class name if it's not already fully qualified
                    if (! str_contains($className, '\\')) {
                        // Try to find the class in common namespaces
                        $possibleClasses = [
                            'App\\Http\\Requests\\'.$className,
                            'Illuminate\\Http\\'.$className,
                            $className, // fallback to the original name
                        ];

                        foreach ($possibleClasses as $possibleClass) {
                            if (class_exists($possibleClass) && is_subclass_of($possibleClass, FormRequest::class)) {
                                $this->parseFormRequestRules($possibleClass);
                                break;
                            }
                        }
                    } else {
                        // Already fully qualified
                        if (class_exists($className) && is_subclass_of($className, FormRequest::class)) {
                            $this->parseFormRequestRules($className);
                        }
                    }
                }
            }
        }
    }

    /**
     * Extract content between balanced brackets
     */
    private function extractBalancedBrackets(string $content, int $start): ?string
    {
        if ($content[$start] !== '[') {
            return null;
        }

        $depth = 0;
        $end = $start;
        $length = strlen($content);

        for ($i = $start; $i < $length; $i++) {
            if ($content[$i] === '[') {
                $depth++;
            } elseif ($content[$i] === ']') {
                $depth--;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }

        // Return content inside brackets (excluding the brackets themselves)
        return substr($content, $start + 1, $end - $start - 1);
    }

    /**
     * Parse inline validation rules from string
     */
    private function parseValidationRules(string $rulesString): void
    {
        // Clean up the rules string
        $rulesString = str_replace(["\n", "\r", "\t"], ' ', $rulesString);

        // Match 'field' => 'rules' pattern (string rules)
        if (preg_match_all("/'([^']+)'\s*=>\s*'([^']*)'/", $rulesString, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $field = $match[1];
                $rules = $match[2];
                if (! empty($field) && ! empty($rules)) {
                    $this->metadata['validations'][$field] = $rules;
                }
            }
        }

        // Match 'field' => [...] pattern (array rules) - handles nested content
        if (preg_match_all("/'([^']+)'\s*=>\s*\[/", $rulesString, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $match) {
                $field = $match[1][0];
                $bracketStart = strpos($rulesString, '[', $match[0][1]);
                $arrayContent = $this->extractBalancedBrackets($rulesString, $bracketStart);

                if ($arrayContent) {
                    // Convert array elements to pipe-separated string
                    $rules = $this->parseArrayRules($arrayContent);
                    if (! empty($field) && ! empty($rules)) {
                        $this->metadata['validations'][$field] = $rules;
                    }
                }
            }
        }
    }

    /**
     * Parse array-style validation rules into string format
     */
    private function parseArrayRules(string $arrayContent): string
    {
        $rules = [];

        // Match quoted strings (e.g., 'required', 'string', 'email')
        if (preg_match_all("/'([^']+)'/", $arrayContent, $matches)) {
            $rules = array_merge($rules, $matches[1]);
        }

        // Match Rule:: calls - extract the rule type
        if (preg_match_all('/Rule::(\w+)/i', $arrayContent, $matches)) {
            foreach ($matches[1] as $rule) {
                $rules[] = strtolower($rule);
            }
        }

        return implode('|', $rules);
    }

    /**
     * Parse FormRequest rules
     */
    private function parseFormRequestRules(string $formRequestClass): void
    {
        try {
            $instance = app($formRequestClass);
            $rules = $instance->rules();

            foreach ($rules as $field => $rule) {
                $ruleString = is_array($rule) ? implode('|', $rule) : $rule;
                $this->metadata['validations'][$field] = $ruleString;
            }
        } catch (\Throwable $e) {
            // Silent fail
        }
    }

    /**
     * Extract middleware from controller
     */
    private function extractMiddleware(): void
    {
        // Check __construct for middleware
        if ($this->reflectionClass->hasMethod('__construct')) {
            $constructor = $this->reflectionClass->getMethod('__construct');
            $filename = $constructor->getFileName();
            $startLine = $constructor->getStartLine();
            $endLine = $constructor->getEndLine();

            if ($filename && file_exists($filename)) {
                $lines = file($filename);
                $constructorCode = implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));

                // Find middleware()
                if (preg_match_all("/middleware\s*\(\s*['\"]([^'\"]+)['\"]\s*,?\s*\[\s*'only'\s*=>\s*\[\s*['\"]([^'\"]+)['\"]/", $constructorCode, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        $middleware = $match[1];
                        $methods = explode(',', str_replace([' ', "'", '"'], '', $match[2]));

                        if (in_array($this->methodName, $methods)) {
                            $this->metadata['middleware'][] = $middleware;
                        }
                    }
                }
            }
        }
    }

    /**
     * Extract error responses from controller
     */
    private function extractErrorResponses(): void
    {
        // Find all response()->json() or response() with status codes
        if (preg_match_all("/response\(\)\s*->\s*json\s*\(\s*\[?([^\]]*)\]?\s*,\s*(\d{3})\s*\)/s", $this->methodContent, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $statusCode = (int) $match[2];
                $body = trim($match[1]);

                if ($statusCode >= 400) {
                    $this->metadata['responses'][$statusCode] = $body;
                }
            }
        }

        // Also find abort() calls
        if (preg_match_all("/abort\s*\(\s*(\d{3})\s*,?\s*['\"]([^'\"]*)['\"]?\s*\)/", $this->methodContent, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $statusCode = (int) $match[1];
                $message = $match[2];

                if ($statusCode >= 400) {
                    $this->metadata['responses'][$statusCode] = $message;
                }
            }
        }
    }

    /**
     * Extract model information from method parameters and content
     */
    private function extractModelInfo(): void
    {
        // 1. Detect model from method parameters (route model binding)
        $params = $this->reflectionMethod->getParameters();
        foreach ($params as $param) {
            $paramType = $param->getType();
            if ($paramType && ! $paramType->isBuiltin()) {
                $className = $paramType->getName();

                // Check if it's an Eloquent model
                if (class_exists($className) && is_subclass_of($className, \Illuminate\Database\Eloquent\Model::class)) {
                    $this->modelClass = $className;
                    $this->extractModelFields($className);
                    break;
                }
            }
        }

        // 2. Also detect from controller name pattern (e.g., UserController -> User model)
        if (! $this->modelClass) {
            $resourceName = $this->getResourceName();
            $possibleModels = [
                'App\\Models\\'.$resourceName,
                'App\\'.$resourceName,
            ];

            foreach ($possibleModels as $modelClass) {
                if (class_exists($modelClass) && is_subclass_of($modelClass, \Illuminate\Database\Eloquent\Model::class)) {
                    $this->modelClass = $modelClass;
                    $this->extractModelFields($modelClass);
                    break;
                }
            }
        }

        // 3. Extract loaded relations from method content
        // This will only include relations actually loaded in the method
        $this->extractLoadedRelations();
    }

    /**
     * Extract fillable/visible fields from model
     */
    private function extractModelFields(string $modelClass): void
    {
        try {
            $reflection = new ReflectionClass($modelClass);
            $instance = $reflection->newInstanceWithoutConstructor();

            // Try to get fillable fields
            $fillable = [];
            if ($reflection->hasProperty('fillable')) {
                $prop = $reflection->getProperty('fillable');
                $prop->setAccessible(true);
                $fillable = $prop->getValue($instance) ?? [];
            }

            // Try to get visible fields (if model uses visible instead of hidden)
            $visible = [];
            if ($reflection->hasProperty('visible')) {
                $prop = $reflection->getProperty('visible');
                $prop->setAccessible(true);
                $visible = $prop->getValue($instance) ?? [];
            }

            // Get hidden fields to exclude them
            $hidden = [];
            if ($reflection->hasProperty('hidden')) {
                $prop = $reflection->getProperty('hidden');
                $prop->setAccessible(true);
                $hidden = $prop->getValue($instance) ?? [];
            }

            // Get casts to infer types
            $casts = [];
            if ($reflection->hasMethod('getCasts')) {
                try {
                    $casts = $instance->getCasts() ?? [];
                } catch (\Throwable $e) {
                    // Silent fail
                }
            }

            // Combine fillable and visible, exclude hidden
            $fields = ! empty($visible) ? $visible : $fillable;

            // Add standard fields
            $standardFields = ['id', 'created_at', 'updated_at'];
            $fields = array_merge($standardFields, $fields);
            $fields = array_unique($fields);

            // Remove hidden fields
            $fields = array_diff($fields, $hidden);

            // Build field metadata with types
            foreach ($fields as $field) {
                $type = 'string';

                // Infer type from casts
                if (isset($casts[$field])) {
                    $castType = $casts[$field];
                    if (in_array($castType, ['int', 'integer'])) {
                        $type = 'integer';
                    } elseif (in_array($castType, ['bool', 'boolean'])) {
                        $type = 'boolean';
                    } elseif (in_array($castType, ['float', 'double', 'decimal'])) {
                        $type = 'number';
                    } elseif (in_array($castType, ['array', 'json', 'collection'])) {
                        $type = 'array';
                    } elseif (in_array($castType, ['date', 'datetime', 'timestamp'])) {
                        $type = 'datetime';
                    }
                } else {
                    // Infer type from field name
                    $type = $this->inferTypeFromFieldName($field);
                }

                $this->metadata['model_fields'][$field] = $type;
            }
        } catch (\Throwable $e) {
            // Silent fail - model might not be instantiable
        }
    }

    /**
     * Extract model relations
     */
    private function extractModelRelations(string $modelClass): void
    {
        try {
            $reflection = new ReflectionClass($modelClass);
            $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

            $relationMethods = ['hasOne', 'hasMany', 'belongsTo', 'belongsToMany', 'morphTo', 'morphOne', 'morphMany', 'morphToMany', 'morphedByMany'];

            // Skip framework-level relations from traits
            $skipRelations = ['notifications', 'readNotifications', 'unreadNotifications', 'tokens', 'currentAccessToken'];

            foreach ($methods as $method) {
                // Skip methods not declared in the model itself
                $declaringClass = $method->getDeclaringClass()->getName();
                if ($declaringClass !== $modelClass) {
                    continue;
                }

                // Skip known framework methods
                if (in_array($method->getName(), $skipRelations)) {
                    continue;
                }

                $filename = $method->getFileName();
                if (! $filename || ! file_exists($filename)) {
                    continue;
                }

                $startLine = $method->getStartLine();
                $endLine = $method->getEndLine();
                $lines = file($filename);
                $methodCode = implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));

                // Check if method contains a relation call
                foreach ($relationMethods as $relationType) {
                    if (preg_match('/\$this\s*->\s*'.$relationType.'\s*\(/i', $methodCode)) {
                        $this->metadata['model_relations'][$method->getName()] = $relationType;
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Silent fail
        }
    }

    /**
     * Extract relations loaded in the method via load() or with()
     */
    private function extractLoadedRelations(): void
    {
        // Match $model->load([...]) or ::with([...])
        if (preg_match_all('/(?:->load|::with)\s*\(\s*\[([^\]]+)\]/s', $this->methodContent, $matches)) {
            foreach ($matches[1] as $relationsBlock) {
                // Look for relation keys in the array - match 'relationName' => function pattern
                if (preg_match_all("/['\"](\w+)['\"]\s*=>\s*function/", $relationsBlock, $relationWithCallback)) {
                    foreach ($relationWithCallback[1] as $relationName) {
                        $relationName = trim($relationName);
                        if (! empty($relationName) && ! isset($this->metadata['model_relations'][$relationName])) {
                            $this->metadata['model_relations'][$relationName] = 'loaded';
                        }
                    }
                }

                // Also match simple string relations like 'relationName' (without callback)
                // But skip anything that looks like a table.column reference
                if (preg_match_all("/['\"](\w+)['\"](?!\s*=>)(?!\s*\.)/", $relationsBlock, $simpleRelations)) {
                    foreach ($simpleRelations[1] as $relationName) {
                        $relationName = trim($relationName);
                        // Skip if it looks like a column name (common column patterns)
                        if (in_array($relationName, ['id', 'name', 'select', 'query', 'function'])) {
                            continue;
                        }
                        if (! empty($relationName) && ! isset($this->metadata['model_relations'][$relationName])) {
                            $this->metadata['model_relations'][$relationName] = 'loaded';
                        }
                    }
                }
            }
        }
    }

    /**
     * Infer type from field name
     */
    private function inferTypeFromFieldName(string $field): string
    {
        $fieldLower = strtolower($field);

        if ($field === 'id' || str_ends_with($fieldLower, '_id')) {
            return 'integer';
        }
        if (preg_match('/^(is_|has_|can_|enabled|active|visible|published)/', $fieldLower)) {
            return 'boolean';
        }
        if (preg_match('/_at$|_date$|date_/', $fieldLower) || in_array($fieldLower, ['created_at', 'updated_at', 'deleted_at'])) {
            return 'datetime';
        }
        if (preg_match('/price|amount|cost|total|fee|balance/', $fieldLower)) {
            return 'number';
        }
        if (preg_match('/count|quantity|qty|number|age|year/', $fieldLower)) {
            return 'integer';
        }

        return 'string';
    }

    /**
     * Analyze store/create method
     */
    private function analyzeStoreMethod(): array
    {
        $resourceName = $this->getResourceName();
        $title = "Create a new {$resourceName}";
        $details = '';

        $has_transaction = preg_match('/DB::transaction|transaction/i', $this->methodContent);
        $has_queue = preg_match('/::dispatch|Queue|queue/i', $this->methodContent);
        $has_cache = preg_match('/Cache::|cache\(/i', $this->methodContent);
        $has_validation = preg_match('/validate|validator/i', $this->methodContent);
        $has_auth = preg_match('/authorize|gate/i', $this->methodContent);
        $has_external = preg_match('/Http::|\$this->.*?Service/i', $this->methodContent);
        $has_relations = preg_match('/attach|sync|associate/i', $this->methodContent);

        $features = [];
        if ($has_validation) {
            $features[] = 'request validation';
        }
        if ($has_auth) {
            $features[] = 'authorization checks';
        }
        if ($has_external) {
            $features[] = 'integration with external services';
        }
        if ($has_transaction) {
            $features[] = 'database transaction with automatic rollback';
        }
        if ($has_queue) {
            $features[] = 'asynchronous background jobs';
        }
        if ($has_cache) {
            $features[] = 'cache invalidation';
        }
        if ($has_relations) {
            $features[] = 'relationship management';
        }

        if (! empty($features)) {
            $details = 'This operation includes '.implode(', ', $features).'.';
        }

        $this->metadata['complexity_score'] += 4;
        if ($has_transaction) {
            $this->metadata['complexity_score'] += 5;
        }
        if ($has_queue) {
            $this->metadata['complexity_score'] += 3;
        }
        if ($has_cache) {
            $this->metadata['complexity_score'] += 2;
        }
        if ($has_validation) {
            $this->metadata['complexity_score'] += 2;
        }
        if ($has_relations) {
            $this->metadata['complexity_score'] += 2;
        }
        if ($has_external) {
            $this->metadata['complexity_score'] += 3;
        }

        return [
            'title' => $title,
            'details' => $details,
        ];
    }

    /**
     * Analyze list/index method
     */
    private function analyzeListMethod(): array
    {
        $title = "Retrieve a list of {$this->getResourceNamePlural()}";
        $details = '';

        $has_filter = preg_match('/when\(|where\(|filter/i', $this->methodContent);
        $has_sort = preg_match('/orderBy|sortBy|sort/i', $this->methodContent);
        $has_paginate = preg_match('/paginate|limit|take/i', $this->methodContent);
        $has_search = preg_match('/search|query|find/i', $this->methodContent);
        $has_relations = preg_match('/with\(|load\(/i', $this->methodContent);

        $features = [];
        if ($has_search) {
            $features[] = 'search';
        }
        if ($has_filter) {
            $features[] = 'filtering';
        }
        if ($has_sort) {
            $features[] = 'sorting';
        }
        if ($has_paginate) {
            $features[] = 'pagination';
        }
        if ($has_relations) {
            $features[] = 'eager loading of related resources';
        }

        if (! empty($features)) {
            $details = 'This endpoint supports '.implode(', ', $features).'.';
        }

        $this->metadata['complexity_score'] += 3;
        if ($has_paginate) {
            $this->metadata['complexity_score'] += 2;
        }
        if ($has_filter) {
            $this->metadata['complexity_score'] += 3;
        }
        if ($has_sort) {
            $this->metadata['complexity_score'] += 2;
        }
        if ($has_relations) {
            $this->metadata['complexity_score'] += 2;
        }

        return [
            'title' => $title,
            'details' => $details,
        ];
    }

    /**
     * Analyze show/detail method
     */
    private function analyzeShowMethod(): array
    {
        $resourceName = $this->getResourceName();
        $title = "Retrieve a specific {$resourceName}";
        $details = '';

        $has_relations = preg_match('/with\(|load\(/i', $this->methodContent);
        $has_auth = preg_match('/authorize|gate|ability/i', $this->methodContent);
        $has_soft_delete = preg_match('/withTrashed|onlyTrashed/i', $this->methodContent);

        $features = [];
        if ($has_relations) {
            $features[] = 'including related resources';
        }
        if ($has_auth) {
            $features[] = 'authorization checking';
        }
        if ($has_soft_delete) {
            $features[] = 'respecting soft-deleted records';
        }

        if (! empty($features)) {
            $details = 'This operation includes '.implode(', ', $features).'.';
        }

        $this->metadata['complexity_score'] += 2;
        if ($has_relations) {
            $this->metadata['complexity_score'] += 2;
        }
        if ($has_auth) {
            $this->metadata['complexity_score'] += 2;
        }

        return [
            'title' => $title,
            'details' => $details,
        ];
    }

    /**
     * Analyze update method
     */
    private function analyzeUpdateMethod(): array
    {
        $resourceName = $this->getResourceName();
        $title = "Update an existing {$resourceName}";
        $details = '';

        $has_transaction = preg_match('/DB::transaction|transaction/i', $this->methodContent);
        $has_cache = preg_match('/Cache::|cache\(/i', $this->methodContent);
        $has_validation = preg_match('/validated|validate/i', $this->methodContent);
        $has_auth = preg_match('/authorize|gate/i', $this->methodContent);
        $has_queue = preg_match('/::dispatch|Queue/i', $this->methodContent);

        $features = [];
        if ($has_validation) {
            $features[] = 'validation';
        }
        if ($has_auth) {
            $features[] = 'authorization';
        }
        if ($has_transaction) {
            $features[] = 'atomic transactions';
        }
        if ($has_cache) {
            $features[] = 'cache invalidation';
        }
        if ($has_queue) {
            $features[] = 'async notifications';
        }

        if (! empty($features)) {
            $details = 'This operation supports '.implode(', ', $features).'.';
        }

        $this->metadata['complexity_score'] += 4;
        if ($has_transaction) {
            $this->metadata['complexity_score'] += 4;
        }
        if ($has_cache) {
            $this->metadata['complexity_score'] += 2;
        }
        if ($has_queue) {
            $this->metadata['complexity_score'] += 2;
        }

        return [
            'title' => $title,
            'details' => $details,
        ];
    }

    /**
     * Analyze destroy/delete method
     */
    private function analyzeDestroyMethod(): array
    {
        $resourceName = $this->getResourceName();
        $title = "Delete a {$resourceName}";
        $details = '';

        $has_soft = preg_match('/forceDelete|restore|withTrashed/i', $this->methodContent);
        $has_cascade = preg_match('/each|forEach|loop|->delete/i', $this->methodContent);
        $has_auth = preg_match('/authorize|gate/i', $this->methodContent);
        $has_transaction = preg_match('/DB::transaction|transaction/i', $this->methodContent);
        $has_queue = preg_match('/::dispatch|Queue/i', $this->methodContent);

        $features = [];
        if ($has_soft) {
            $features[] = 'soft delete support';
        }
        if ($has_cascade) {
            $features[] = 'cascading deletions';
        }
        if ($has_auth) {
            $features[] = 'authorization checking';
        }
        if ($has_transaction) {
            $features[] = 'transaction handling';
        }
        if ($has_queue) {
            $features[] = 'async cleanup jobs';
        }

        if (! empty($features)) {
            $details = 'This operation handles '.implode(', ', $features).'.';
        }

        $this->metadata['complexity_score'] += 2;
        if ($has_cascade) {
            $this->metadata['complexity_score'] += 4;
        }
        if ($has_transaction) {
            $this->metadata['complexity_score'] += 3;
        }

        return [
            'title' => $title,
            'details' => $details,
        ];
    }

    /**
     * Analyze custom/non-CRUD method
     */
    private function analyzeCustomMethod(): array
    {
        $methodName = $this->methodName;
        $title = ucfirst(str_replace('_', ' ', $methodName));

        $has_query = preg_match('/where|get|find|query/i', $this->methodContent);
        $has_transaction = preg_match('/DB::transaction|transaction/i', $this->methodContent);
        $has_queue = preg_match('/::dispatch|Queue/i', $this->methodContent);
        $has_external = preg_match('/Http::|\$this->.*?Service/i', $this->methodContent);

        $details = '';
        $features = [];

        if ($has_query) {
            $features[] = 'database query execution';
        }
        if ($has_transaction) {
            $features[] = 'transactional operations';
        }
        if ($has_queue) {
            $features[] = 'background job processing';
        }
        if ($has_external) {
            $features[] = 'external API integration';
        }

        if (! empty($features)) {
            $details = 'This operation performs '.implode(', ', $features).'.';
        }

        $this->metadata['complexity_score'] += 3;
        if ($has_transaction) {
            $this->metadata['complexity_score'] += 4;
        }
        if ($has_external) {
            $this->metadata['complexity_score'] += 3;
        }
        if ($has_queue) {
            $this->metadata['complexity_score'] += 2;
        }

        return [
            'title' => $title,
            'details' => $details,
        ];
    }

    /**
     * Generate parameter documentation
     */
    private function generateParameterDocs(): array
    {
        $lines = [];
        $httpMethod = $this->detectHttpMethod();

        // Determine parameter type based on HTTP method
        // POST, PUT, PATCH use @bodyParam; GET, DELETE use @queryParam
        $isBodyMethod = in_array(strtoupper($httpMethod), ['POST', 'PUT', 'PATCH']);
        $paramTag = $isBodyMethod ? '@bodyParam' : '@queryParam';

        // Collect base array fields and their wildcard counterparts
        $arrayFields = [];
        $wildcardFields = [];

        foreach ($this->metadata['validations'] as $field => $ruleString) {
            if (str_ends_with($field, '.*')) {
                $baseField = str_replace('.*', '', $field);
                $wildcardFields[$baseField] = [
                    'field' => $field,
                    'rules' => $ruleString,
                ];
            } elseif (str_contains($ruleString, 'array')) {
                $arrayFields[$field] = $ruleString;
            }
        }

        // Use extracted validations
        if (! empty($this->metadata['validations'])) {
            foreach ($this->metadata['validations'] as $field => $ruleString) {
                // Skip wildcard fields - they'll be handled with their base array
                if (str_ends_with($field, '.*')) {
                    continue;
                }

                $isRequired = str_contains($ruleString, 'required');
                $type = $this->inferTypeFromRules($ruleString);
                $description = $this->generateFieldDescription($field, $ruleString);
                $requiredText = $isRequired ? 'required' : 'optional';

                // Check if this is an array field with a wildcard counterpart
                if ($type === 'array' && isset($wildcardFields[$field])) {
                    $wildcardRules = $wildcardFields[$field]['rules'];
                    $itemType = $this->inferTypeFromRules($wildcardRules);
                    $arrayExample = $this->generateArrayExample($itemType);
                    $itemExample = $this->generateFakerExample($field.'.*', $itemType, $wildcardRules);

                    $lines[] = " * {$paramTag} {$field} array {$requiredText} {$description} Example: {$arrayExample}";
                    $lines[] = " * {$paramTag} {$field}.* {$itemType} {$requiredText} Array item. Example: {$itemExample}";
                } else {
                    $example = $this->generateFakerExample($field, $type, $ruleString);
                    $lines[] = " * {$paramTag} {$field} {$type} {$requiredText} {$description} Example: {$example}";
                }
            }

            if (! empty($lines)) {
                $lines[] = ' *';
            }
        }

        // Add URL parameters for show/update/destroy methods
        if (in_array($this->methodName, ['show', 'update', 'destroy', 'edit', 'delete'])) {
            $resourceName = strtolower($this->getResourceName());
            array_unshift($lines, " * @urlParam {$resourceName} integer required The {$resourceName} ID. Example: ".$this->faker->numberBetween(1, 100));
            array_unshift($lines, ' *');
        }

        return $lines;
    }

    /**
     * Generate a description for a field based on its name and rules
     */
    private function generateFieldDescription(string $field, string $rules): string
    {
        $fieldName = str_replace(['_', '-'], ' ', $field);
        $fieldName = ucfirst($fieldName);

        // Remove common suffixes for cleaner description
        $fieldName = preg_replace('/\s*(id|ids)$/i', '', $fieldName);
        $fieldName = trim($fieldName);

        if (empty($fieldName)) {
            return '';
        }

        // Build description based on rules
        $descriptions = [];

        if (str_contains($rules, 'email')) {
            return 'Valid email address.';
        }
        if (str_contains($rules, 'max:')) {
            preg_match('/max:(\d+)/', $rules, $matches);
            if (! empty($matches[1])) {
                $descriptions[] = "Maximum {$matches[1]} characters.";
            }
        }
        if (str_contains($rules, 'min:')) {
            preg_match('/min:(\d+)/', $rules, $matches);
            if (! empty($matches[1])) {
                $descriptions[] = "Minimum {$matches[1]} characters.";
            }
        }
        if (str_contains($rules, 'in:')) {
            preg_match('/in:([^|]+)/', $rules, $matches);
            if (! empty($matches[1])) {
                $options = explode(',', $matches[1]);

                return 'Allowed values: '.implode(', ', $options).'.';
            }
        }
        if (str_contains($rules, 'exists:')) {
            preg_match('/exists:(\w+)/', $rules, $matches);
            if (! empty($matches[1])) {
                $table = str_replace('_', ' ', $matches[1]);

                return "Must exist in {$table}.";
            }
        }
        if (str_contains($rules, 'unique:')) {
            return 'Must be unique.';
        }

        if (! empty($descriptions)) {
            return implode(' ', $descriptions);
        }

        return "The {$fieldName}.";
    }

    /**
     * Generate a realistic example using Faker
     */
    private function generateFakerExample(string $field, string $type, string $rules = ''): string
    {
        // Check config first
        $examples = config('phpdoc-generator.examples', []);
        if (isset($examples[$field])) {
            return $examples[$field];
        }

        // Generate based on field name patterns
        $fieldLower = strtolower($field);

        // Name fields
        if (preg_match('/^(first_?name|name|given_?name)$/i', $field)) {
            return $this->faker->firstName();
        }
        if (preg_match('/^(last_?name|surname|family_?name)$/i', $field)) {
            return $this->faker->lastName();
        }
        if (preg_match('/^(full_?name|display_?name)$/i', $field)) {
            return $this->faker->name();
        }

        // Contact fields
        if (str_contains($fieldLower, 'email')) {
            return $this->faker->unique()->safeEmail();
        }
        if (preg_match('/phone|telephone|mobile|cell/i', $field)) {
            return '+39'.$this->faker->numerify('##########');
        }

        // Address fields
        if (str_contains($fieldLower, 'address')) {
            return $this->faker->streetAddress();
        }
        if (str_contains($fieldLower, 'city')) {
            return $this->faker->city();
        }
        if (preg_match('/zip|postal|cap/i', $field)) {
            return $this->faker->postcode();
        }
        if (str_contains($fieldLower, 'country')) {
            return $this->faker->country();
        }

        // Date fields
        if (preg_match('/date|_at$|_on$/i', $field) || $type === 'date') {
            return $this->faker->date('Y-m-d');
        }
        if (str_contains($fieldLower, 'birth')) {
            return $this->faker->date('Y-m-d', '-18 years');
        }

        // ID fields
        if (preg_match('/^id$|_id$/i', $field)) {
            return (string) $this->faker->numberBetween(1, 100);
        }
        if (str_contains($fieldLower, 'uuid')) {
            return $this->faker->uuid();
        }

        // Boolean fields
        if ($type === 'boolean' || preg_match('/^(is_|has_|can_|enabled|active|visible|published)/i', $field)) {
            return $this->faker->boolean() ? 'true' : 'false';
        }

        // Price/amount fields
        if (preg_match('/price|amount|cost|total|fee/i', $field)) {
            return number_format($this->faker->randomFloat(2, 10, 1000), 2, '.', '');
        }

        // Quantity fields
        if (preg_match('/quantity|count|number|qty/i', $field)) {
            return (string) $this->faker->numberBetween(1, 100);
        }

        // URL fields
        if (str_contains($fieldLower, 'url') || str_contains($fieldLower, 'link')) {
            return $this->faker->url();
        }
        if (str_contains($fieldLower, 'website')) {
            return 'https://'.$this->faker->domainName();
        }

        // Image/file fields
        if (preg_match('/image|photo|avatar|picture/i', $field)) {
            return $this->faker->imageUrl(640, 480);
        }

        // Text fields
        if (str_contains($fieldLower, 'description') || str_contains($fieldLower, 'bio')) {
            return $this->faker->sentence(10);
        }
        if (str_contains($fieldLower, 'title') || str_contains($fieldLower, 'subject')) {
            return $this->faker->sentence(4);
        }
        if (str_contains($fieldLower, 'content') || str_contains($fieldLower, 'body') || str_contains($fieldLower, 'text')) {
            return $this->faker->paragraph();
        }
        if (str_contains($fieldLower, 'note') || str_contains($fieldLower, 'comment')) {
            return $this->faker->sentence(8);
        }

        // Status/role fields with 'in:' validation
        if (str_contains($rules, 'in:')) {
            preg_match('/in:([^|]+)/', $rules, $matches);
            if (! empty($matches[1])) {
                $options = explode(',', $matches[1]);

                return $this->faker->randomElement($options);
            }
        }

        // Role/status fields
        if (str_contains($fieldLower, 'role')) {
            return $this->faker->randomElement(['admin', 'user', 'manager', 'editor']);
        }
        if (str_contains($fieldLower, 'status')) {
            return $this->faker->randomElement(['active', 'pending', 'inactive']);
        }

        // Specialization/profession
        if (str_contains($fieldLower, 'specialization') || str_contains($fieldLower, 'profession')) {
            return $this->faker->jobTitle();
        }

        // Gender
        if (str_contains($fieldLower, 'gender') || str_contains($fieldLower, 'sex')) {
            return $this->faker->randomElement(['M', 'F']);
        }

        // Nationality
        if (str_contains($fieldLower, 'nationality')) {
            return $this->faker->country();
        }

        // Fiscal code
        if (str_contains($fieldLower, 'fiscal') || str_contains($fieldLower, 'tax_code')) {
            return strtoupper($this->faker->bothify('??????##?##?###?'));
        }

        // Password
        if (str_contains($fieldLower, 'password')) {
            return 'SecureP@ss123';
        }

        // Cognito/Auth sub fields (UUID-like)
        if (preg_match('/cognito_?sub|user_?sub|sub_?id|external_?id/i', $field)) {
            return $this->faker->uuid();
        }

        // Token/key fields
        if (preg_match('/token|key|secret|api_key/i', $field)) {
            return $this->faker->sha256();
        }

        // Slug
        if (str_contains($fieldLower, 'slug')) {
            return $this->faker->slug(3);
        }

        // Code fields
        if (str_contains($fieldLower, 'code')) {
            return strtoupper($this->faker->lexify('???###'));
        }

        // Array type
        if ($type === 'array') {
            return '[1, 2, 3]';
        }

        // Integer type
        if ($type === 'integer') {
            return (string) $this->faker->numberBetween(1, 100);
        }

        // Default: use faker word
        return $this->faker->word();
    }

    /**
     * Generate array example based on item type
     */
    private function generateArrayExample(string $itemType): string
    {
        if ($itemType === 'integer') {
            $items = [
                $this->faker->numberBetween(1, 10),
                $this->faker->numberBetween(11, 20),
                $this->faker->numberBetween(21, 30),
            ];

            return '['.implode(', ', $items).']';
        }

        if ($itemType === 'string') {
            return '["'.$this->faker->word().'", "'.$this->faker->word().'"]';
        }

        return '[1, 2, 3]';
    }

    /**
     * Generate response documentation with realistic examples
     */
    private function generateResponseDocs(): array
    {
        $lines = [];

        // Success response based on method
        $successStatus = $this->getSuccessHttpStatus();

        // Add success response
        $lines[] = " * @response {$successStatus} {";

        $resourceName = $this->getResourceName();
        $timestamp = $this->faker->dateTimeThisYear()->format('Y-m-d\TH:i:s.000000\Z');

        // Determine which fields to use: validations or model fields
        $fieldsToUse = $this->getResponseFields();

        switch ($this->methodName) {
            case 'index':
            case 'list':
                $lines[] = ' *   "data": [{';
                $lines[] = ' *     "id": '.$this->faker->numberBetween(1, 100).',';
                foreach ($fieldsToUse as $field => $type) {
                    if ($field === 'id' || $field === 'created_at' || $field === 'updated_at') {
                        continue;
                    }
                    $example = $this->generateFakerExample($field, $type, '');
                    $value = $this->formatJsonValue($example, $type);
                    $lines[] = " *     \"{$field}\": {$value},";
                }
                // Add loaded relations
                $this->addRelationsToResponse($lines, '    ');
                $lines[] = " *     \"created_at\": \"{$timestamp}\"";
                $lines[] = ' *   }],';
                $lines[] = ' *   "meta": {"current_page": 1, "per_page": 15, "total": '.$this->faker->numberBetween(10, 200).'}';
                break;
            case 'show':
                $lines[] = ' *   "id": '.$this->faker->numberBetween(1, 100).',';
                foreach ($fieldsToUse as $field => $type) {
                    if ($field === 'id' || $field === 'created_at' || $field === 'updated_at') {
                        continue;
                    }
                    $example = $this->generateFakerExample($field, $type, '');
                    $value = $this->formatJsonValue($example, $type);
                    $lines[] = " *   \"{$field}\": {$value},";
                }
                // Add loaded relations
                $this->addRelationsToResponse($lines, '  ');
                $lines[] = " *   \"created_at\": \"{$timestamp}\",";
                $lines[] = " *   \"updated_at\": \"{$timestamp}\"";
                break;
            case 'store':
            case 'create':
                $lines[] = ' *   "id": '.$this->faker->numberBetween(1, 100).',';
                foreach ($fieldsToUse as $field => $type) {
                    if ($field === 'id' || $field === 'created_at' || $field === 'updated_at') {
                        continue;
                    }
                    $example = $this->generateFakerExample($field, $type, '');
                    $value = $this->formatJsonValue($example, $type);
                    $lines[] = " *   \"{$field}\": {$value},";
                }
                $lines[] = " *   \"created_at\": \"{$timestamp}\"";
                break;
            case 'update':
            case 'edit':
                $lines[] = ' *   "id": '.$this->faker->numberBetween(1, 100).',';
                foreach ($fieldsToUse as $field => $type) {
                    if ($field === 'id' || $field === 'created_at' || $field === 'updated_at') {
                        continue;
                    }
                    $example = $this->generateFakerExample($field, $type, '');
                    $value = $this->formatJsonValue($example, $type);
                    $lines[] = " *   \"{$field}\": {$value},";
                }
                $lines[] = " *   \"updated_at\": \"{$timestamp}\"";
                break;
            case 'destroy':
            case 'delete':
                $lines[] = " *   \"message\": \"{$resourceName} deleted successfully\"";
                break;
            default:
                $lines[] = ' *   "success": true,';
                $lines[] = ' *   "message": "Operation completed successfully"';
        }

        $lines[] = ' * }';
        $lines[] = ' *';

        // Error responses - extract from controller
        if (! empty($this->metadata['responses'])) {
            foreach ($this->metadata['responses'] as $statusCode => $body) {
                $lines[] = " * @response {$statusCode} {";
                $lines[] = ' *   "message": "'.$this->cleanResponseMessage($body).'"';
                $lines[] = ' * }';
                $lines[] = ' *';
            }
        }

        // Standard error responses
        if (in_array($this->methodName, ['show', 'update', 'destroy', 'edit', 'delete'])) {
            if (empty($this->metadata['responses'][404])) {
                $lines[] = ' * @response 404 {';
                $lines[] = " *   \"message\": \"{$resourceName} not found\"";
                $lines[] = ' * }';
                $lines[] = ' *';
            }
        }

        if (in_array($this->methodName, ['store', 'update', 'create', 'edit'])) {
            if (empty($this->metadata['responses'][422])) {
                $lines[] = ' * @response 422 {';
                $lines[] = ' *   "message": "The given data was invalid.",';
                $lines[] = ' *   "errors": {';
                // Use first validation field as example
                $firstField = array_key_first($this->metadata['validations']) ?? 'field_name';
                $lines[] = " *     \"{$firstField}\": [\"The {$firstField} field is required.\"]";
                $lines[] = ' *   }';
                $lines[] = ' * }';
                $lines[] = ' *';
            }
        }

        if (preg_match('/authorize|gate|ability/i', $this->methodContent)) {
            if (empty($this->metadata['responses'][403])) {
                $lines[] = ' * @response 403 {';
                $lines[] = ' *   "message": "This action is unauthorized."';
                $lines[] = ' * }';
                $lines[] = ' *';
            }
        }

        return $lines;
    }

    /**
     * Get fields for response documentation
     * Uses validations if available, otherwise falls back to model fields
     */
    private function getResponseFields(): array
    {
        // If we have validations, convert them to field => type format
        if (! empty($this->metadata['validations'])) {
            $fields = [];
            foreach ($this->metadata['validations'] as $field => $rules) {
                if (! str_ends_with($field, '.*') && ! str_contains($field, '.')) {
                    $fields[$field] = $this->inferTypeFromRules($rules);
                }
            }
            if (! empty($fields)) {
                return $fields;
            }
        }

        // Fall back to model fields
        if (! empty($this->metadata['model_fields'])) {
            return $this->metadata['model_fields'];
        }

        // No fields available
        return [];
    }

    /**
     * Add relation examples to response
     */
    private function addRelationsToResponse(array &$lines, string $indent): void
    {
        if (empty($this->metadata['model_relations'])) {
            return;
        }

        foreach ($this->metadata['model_relations'] as $relationName => $relationType) {
            // Determine if it's a to-many or to-one relation
            $isMany = in_array($relationType, ['hasMany', 'belongsToMany', 'morphMany', 'morphToMany', 'morphedByMany', 'loaded']);

            if ($isMany) {
                // Array of objects
                $lines[] = " *{$indent}\"{$relationName}\": [";
                $lines[] = " *{$indent}  {\"id\": ".$this->faker->numberBetween(1, 50).', "name": "'.$this->faker->word().'"},';
                $lines[] = " *{$indent}  {\"id\": ".$this->faker->numberBetween(51, 100).', "name": "'.$this->faker->word().'"}';
                $lines[] = " *{$indent}],";
            } else {
                // Single object
                $lines[] = " *{$indent}\"{$relationName}\": {\"id\": ".$this->faker->numberBetween(1, 100).', "name": "'.$this->faker->word().'"},';
            }
        }
    }

    /**
     * Format value for JSON based on type
     */
    private function formatJsonValue(string $value, string $type): string
    {
        if ($type === 'integer' || $type === 'number') {
            return is_numeric($value) ? $value : '0';
        }
        if ($type === 'boolean') {
            return ($value === 'true' || $value === '1') ? 'true' : 'false';
        }
        if ($type === 'array') {
            // If it looks like an array, return as-is
            if (str_starts_with($value, '[')) {
                return $value;
            }

            return '[]';
        }

        // String type - wrap in quotes and escape
        return '"'.addslashes($value).'"';
    }

    /**
     * Generate implementation notes (without separator lines)
     */
    private function generateImplementationNotes(): array
    {
        $notes = [];

        if (preg_match('/DB::transaction|DB::beginTransaction|transaction/i', $this->methodContent)) {
            $notes[] = 'This operation is executed within a database transaction with automatic rollback on error.';
        }

        if (preg_match('/::dispatch|Queue/i', $this->methodContent)) {
            $notes[] = 'Background jobs are dispatched asynchronously and may not complete immediately.';
        }

        if (preg_match('/Cache::|cache\(/i', $this->methodContent)) {
            $notes[] = 'Results may be cached. Cache is invalidated on data modification.';
        }

        if (preg_match('/\$this->authorize|gate\(/i', $this->methodContent)) {
            $notes[] = 'Proper authorization is required. Returns 403 Forbidden if unauthorized.';
        }

        if (preg_match('/throttle|rateLimit/i', $this->methodContent)) {
            $notes[] = 'Rate limiting is applied. Exceeding limits returns 429 Too Many Requests.';
        }

        if (preg_match('/softDelete|withTrashed|forceDelete/i', $this->methodContent)) {
            $notes[] = "Soft deletes are supported. Use 'with_trashed' parameter to include deleted records.";
        }

        if (preg_match('/Http::.*post|Http::.*put|Http::.*get/i', $this->methodContent)) {
            $notes[] = 'This operation integrates with external APIs. Network delays may occur.';
        }

        return $notes;
    }

    /**
     * Extract method source code
     */
    private function extractMethodContent(): void
    {
        $filename = $this->reflectionMethod->getFileName();
        $startLine = $this->reflectionMethod->getStartLine();
        $endLine = $this->reflectionMethod->getEndLine();

        if ($filename && file_exists($filename)) {
            $lines = file($filename);
            $methodLines = array_slice($lines, $startLine - 1, $endLine - $startLine + 1);
            $this->methodContent = implode('', $methodLines);
        }
    }

    /**
     * Extract controller source code
     */
    private function extractControllerContent(): void
    {
        $filename = $this->reflectionClass->getFileName();
        if ($filename && file_exists($filename)) {
            $this->controllerContent = file_get_contents($filename);
        }
    }

    /**
     * Analyze method for complexity and metadata
     */
    private function analyzeMethod(): void
    {
        $this->metadata['complexity_score'] = 0;

        $locCount = substr_count($this->methodContent, "\n");
        $this->metadata['complexity_score'] += min(5, $locCount / 5);

        $nestLevel = max(substr_count($this->methodContent, '{'), substr_count($this->methodContent, '}'));
        $this->metadata['complexity_score'] += min(5, $nestLevel / 3);

        if (preg_match_all('/throw new (\w+Exception)/', $this->methodContent, $matches)) {
            $this->metadata['exceptions'] = array_unique($matches[1]);
        }
    }

    /**
     * Helper methods
     */
    private function detectHttpMethod(): string
    {
        if (preg_match('/POST|POST|store|create|add/i', $this->methodName)) {
            return 'POST';
        }
        if (preg_match('/PUT|PATCH|update|edit/i', $this->methodName)) {
            return 'PUT';
        }
        if (preg_match('/DELETE|destroy|delete|remove/i', $this->methodName)) {
            return 'DELETE';
        }

        return 'GET';
    }

    private function detectEndpoint(): string
    {
        $resource = strtolower($this->getResourceNamePlural());

        if (in_array($this->methodName, ['index', 'store'])) {
            return "/api/{$resource}";
        }
        if (in_array($this->methodName, ['show', 'update', 'destroy'])) {
            return "/api/{$resource}/{id}";
        }

        return "/api/{$resource}/".strtolower($this->methodName);
    }

    private function getTitleForMethod(): string
    {
        $resource = $this->getResourceName();
        $method = match ($this->methodName) {
            'index' => 'List',
            'show' => 'Show',
            'store' => 'Store',
            'update' => 'Update',
            'destroy' => 'Destroy',
            default => ucfirst($this->methodName),
        };

        return "$method {$resource}";
    }

    private function extractGroupName(): ?string
    {
        // Extract from class namespace or name
        $className = class_basename($this->controllerClass);

        return str_replace('Controller', '', $className);
    }

    private function getResourceName(): string
    {
        $className = class_basename($this->controllerClass);

        return str_replace('Controller', '', $className);
    }

    private function getResourceNamePlural(): string
    {
        return $this->getResourceName().'s';
    }

    private function inferTypeFromRules(string $rules): string
    {
        if (str_contains($rules, 'integer') || str_contains($rules, 'numeric')) {
            return 'integer';
        }
        if (str_contains($rules, 'email')) {
            return 'string';
        }
        if (str_contains($rules, 'date')) {
            return 'date';
        }
        if (str_contains($rules, 'boolean')) {
            return 'boolean';
        }
        if (str_contains($rules, 'array')) {
            return 'array';
        }
        if (str_contains($rules, 'json')) {
            return 'json';
        }

        return 'string';
    }

    private function getSuccessHttpStatus(): int
    {
        return match ($this->methodName) {
            'store', 'create' => 201,
            'destroy', 'delete' => 204,
            default => 200,
        };
    }

    private function cleanResponseMessage(string $message): string
    {
        // Extract message from array structure if present
        $message = str_replace(['"', "'", '[', ']'], '', $message);
        $message = trim($message);

        return substr($message, 0, 100); // Max 100 chars
    }
}

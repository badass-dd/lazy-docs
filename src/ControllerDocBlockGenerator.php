<?php

namespace Badass\ControllerPhpDocGenerator;

use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use Illuminate\Routing\Controller;
use Illuminate\Foundation\Http\FormRequest;

class ControllerDocBlockGenerator
{
    private string $controllerClass;
    private string $methodName;
    private ReflectionMethod $reflectionMethod;
    private ReflectionClass $reflectionClass;

    public array $metadata = [
        'complexity_score' => 0,
        'parameters' => [],
        'responses' => [],
        'exceptions' => [],
        'middleware' => [],
        'validations' => [],
    ];

    private string $methodContent = '';
    private string $controllerContent = '';

    public function __construct(string $controllerClass, string $methodName)
    {
        $this->controllerClass = $controllerClass;
        $this->methodName = $methodName;

        $this->reflectionClass = new ReflectionClass($controllerClass);
        $this->reflectionMethod = $this->reflectionClass->getMethod($methodName);

        $this->extractMethodContent();
        $this->extractControllerContent();
        $this->analyzeMethod();
        $this->extractValidationRules();
        $this->extractMiddleware();
        $this->extractErrorResponses();
    }

    /**
     * Generate PHPDoc for method
     */
    public function generate(): string
    {
        $lines = [];
        $lines[] = '/**';

        // @group tag (from comment or resource name)
        $group = $this->extractGroupName();
        if ($group) {
            $lines[] = ' * @group ' . $group;
            $lines[] = ' *';
        }

        // Description
        $description = $this->generateDescription();
        $lines[] = ' * ' . $description['title'];
        $lines[] = ' *';

        if (!empty($description['details'])) {
            foreach (explode("\n", $description['details']) as $detail) {
                $lines[] = ' * ' . $detail;
            }
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
        $lines[] = " * @api {" . strtolower($httpMethod) . "} " . $endpoint . " " . $this->getTitleForMethod();
        $lines[] = ' *';

        // Parameters
        $paramLines = $this->generateParameterDocs();
        $lines = array_merge($lines, $paramLines);

        // Responses
        $responseLines = $this->generateResponseDocs();
        $lines = array_merge($lines, $responseLines);

        // Implementation notes
        $noteLines = $this->generateImplementationNotes();
        if (!empty($noteLines)) {
            foreach ($noteLines as $note) {
                $lines[] = ' * ' . $note;
            }
        }

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
        // 1. Check for $request->validate([...])
        if (preg_match('/\$request\s*->\s*validate\s*\(\s*\[([^\]]+)\]\s*\)/s', $this->methodContent, $matches)) {
            $this->parseValidationRules($matches[1]);
        }
        // 2. Check for $validator = $request->validate([...])
        elseif (preg_match('/\$\w+\s*=\s*\$request\s*->\s*validate\s*\(\s*\[([^\]]+)\]\s*\)/s', $this->methodContent, $matches)) {
            $this->parseValidationRules($matches[1]);
        }
        // 3. Check for FormRequest in method parameters
        else {
            $params = $this->reflectionMethod->getParameters();
            foreach ($params as $param) {
                $paramType = $param->getType();
                if ($paramType && !$paramType->isBuiltin()) {
                    $className = $paramType->getName();
                    
                    // Try to resolve the full class name if it's not already fully qualified
                    if (!str_contains($className, '\\')) {
                        // Try to find the class in common namespaces
                        $possibleClasses = [
                            'App\\Http\\Requests\\' . $className,
                            'Illuminate\\Http\\' . $className,
                            $className // fallback to the original name
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
     * Parse inline validation rules from string
     */
    private function parseValidationRules(string $rulesString): void
    {
        // Clean up the rules string
        $rulesString = str_replace(["\n", "\r", "\t"], ' ', $rulesString);
        
        // Match each rule line: 'field' => 'rules'
        if (preg_match_all("/'([^']+)'\s*=>\s*'([^']*)'|'([^']+)'\s*=>\s*\[([^\]]*)\]/", $rulesString, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $field = $match[1] ?? $match[3];
                $rules = $match[2] ?? $match[4];
                
                if (!empty($field) && !empty($rules)) {
                    $this->metadata['validations'][$field] = $rules;
                }
            }
        }
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
                $statusCode = (int)$match[2];
                $body = trim($match[1]);
                
                if ($statusCode >= 400) {
                    $this->metadata['responses'][$statusCode] = $body;
                }
            }
        }

        // Also find abort() calls
        if (preg_match_all("/abort\s*\(\s*(\d{3})\s*,?\s*['\"]([^'\"]*)['\"]?\s*\)/", $this->methodContent, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $statusCode = (int)$match[1];
                $message = $match[2];
                
                if ($statusCode >= 400) {
                    $this->metadata['responses'][$statusCode] = $message;
                }
            }
        }
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
        if ($has_validation) $features[] = "request validation";
        if ($has_auth) $features[] = "authorization checks";
        if ($has_external) $features[] = "integration with external services";
        if ($has_transaction) $features[] = "database transaction with automatic rollback";
        if ($has_queue) $features[] = "asynchronous background jobs";
        if ($has_cache) $features[] = "cache invalidation";
        if ($has_relations) $features[] = "relationship management";

        if (!empty($features)) {
            $details = "This operation includes " . implode(", ", $features) . ".";
        }

        $this->metadata['complexity_score'] += 4;
        if ($has_transaction) $this->metadata['complexity_score'] += 5;
        if ($has_queue) $this->metadata['complexity_score'] += 3;
        if ($has_cache) $this->metadata['complexity_score'] += 2;
        if ($has_validation) $this->metadata['complexity_score'] += 2;
        if ($has_relations) $this->metadata['complexity_score'] += 2;
        if ($has_external) $this->metadata['complexity_score'] += 3;

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
        if ($has_search) $features[] = "search";
        if ($has_filter) $features[] = "filtering";
        if ($has_sort) $features[] = "sorting";
        if ($has_paginate) $features[] = "pagination";
        if ($has_relations) $features[] = "eager loading of related resources";

        if (!empty($features)) {
            $details = "This endpoint supports " . implode(", ", $features) . ".";
        }

        $this->metadata['complexity_score'] += 3;
        if ($has_paginate) $this->metadata['complexity_score'] += 2;
        if ($has_filter) $this->metadata['complexity_score'] += 3;
        if ($has_sort) $this->metadata['complexity_score'] += 2;
        if ($has_relations) $this->metadata['complexity_score'] += 2;

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
        if ($has_relations) $features[] = "including related resources";
        if ($has_auth) $features[] = "authorization checking";
        if ($has_soft_delete) $features[] = "respecting soft-deleted records";

        if (!empty($features)) {
            $details = "This operation includes " . implode(", ", $features) . ".";
        }

        $this->metadata['complexity_score'] += 2;
        if ($has_relations) $this->metadata['complexity_score'] += 2;
        if ($has_auth) $this->metadata['complexity_score'] += 2;

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
        if ($has_validation) $features[] = "validation";
        if ($has_auth) $features[] = "authorization";
        if ($has_transaction) $features[] = "atomic transactions";
        if ($has_cache) $features[] = "cache invalidation";
        if ($has_queue) $features[] = "async notifications";

        if (!empty($features)) {
            $details = "This operation supports " . implode(", ", $features) . ".";
        }

        $this->metadata['complexity_score'] += 4;
        if ($has_transaction) $this->metadata['complexity_score'] += 4;
        if ($has_cache) $this->metadata['complexity_score'] += 2;
        if ($has_queue) $this->metadata['complexity_score'] += 2;

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
        if ($has_soft) $features[] = "soft delete support";
        if ($has_cascade) $features[] = "cascading deletions";
        if ($has_auth) $features[] = "authorization checking";
        if ($has_transaction) $features[] = "transaction handling";
        if ($has_queue) $features[] = "async cleanup jobs";

        if (!empty($features)) {
            $details = "This operation handles " . implode(", ", $features) . ".";
        }

        $this->metadata['complexity_score'] += 2;
        if ($has_cascade) $this->metadata['complexity_score'] += 4;
        if ($has_transaction) $this->metadata['complexity_score'] += 3;

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

        if ($has_query) $features[] = "database query execution";
        if ($has_transaction) $features[] = "transactional operations";
        if ($has_queue) $features[] = "background job processing";
        if ($has_external) $features[] = "external API integration";

        if (!empty($features)) {
            $details = "This operation performs " . implode(", ", $features) . ".";
        }

        $this->metadata['complexity_score'] += 3;
        if ($has_transaction) $this->metadata['complexity_score'] += 4;
        if ($has_external) $this->metadata['complexity_score'] += 3;
        if ($has_queue) $this->metadata['complexity_score'] += 2;

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

        // Use extracted validations
        if (!empty($this->metadata['validations'])) {
            foreach ($this->metadata['validations'] as $field => $ruleString) {
                $isRequired = str_contains($ruleString, 'required');
                $type = $this->inferTypeFromRules($ruleString);
                $example = $this->getExampleForField($field, $type);

                $requiredText = $isRequired ? 'required' : 'optional';
                
                // Handle array fields like programs_id.*
                if (str_ends_with($field, '.*')) {
                    $baseField = str_replace('.*', '', $field);
                    $lines[] = " * @bodyParam {$baseField} array Array of items. Example: [1,2,3]";
                    $lines[] = " * @bodyParam {$field} {$type} {$requiredText} Item ID. Example: {$example}";
                } else {
                    $lines[] = " * @bodyParam {$field} {$type} {$requiredText} Example: {$example}";
                }
            }

            if (!empty($lines)) {
                $lines[] = ' *';
            }
        }

        return $lines;
    }

    /**
     * Generate response documentation
     */
    private function generateResponseDocs(): array
    {
        $lines = [];

        // Success response based on method
        $successStatus = $this->getSuccessHttpStatus();
        
        // Add success response
        $lines[] = " * @response {$successStatus} {";
        
        $resourceName = $this->getResourceName();
        switch ($this->methodName) {
            case 'index':
            case 'list':
                $lines[] = " *   \"data\": [{";
                $lines[] = " *     \"id\": 1,";
                foreach (array_keys($this->metadata['validations']) as $field) {
                    if (!str_ends_with($field, '.*') && !str_contains($field, '.')) {
                        $lines[] = " *     \"" . $field . "\": \"value\",";
                    }
                }
                $lines[] = " *     \"created_at\": \"2026-01-22T10:00:00Z\"";
                $lines[] = " *   }],";
                $lines[] = " *   \"meta\": {\"current_page\": 1, \"per_page\": 15, \"total\": 100}";
                break;
            case 'show':
                $lines[] = " *   \"id\": 1,";
                foreach (array_keys($this->metadata['validations']) as $field) {
                    if (!str_ends_with($field, '.*') && !str_contains($field, '.')) {
                        $lines[] = " *   \"" . $field . "\": \"value\",";
                    }
                }
                $lines[] = " *   \"created_at\": \"2026-01-22T10:00:00Z\"";
                break;
            case 'store':
            case 'create':
                $lines[] = " *   \"id\": 1,";
                foreach (array_keys($this->metadata['validations']) as $field) {
                    if (!str_ends_with($field, '.*') && !str_contains($field, '.')) {
                        $lines[] = " *   \"" . $field . "\": \"value\",";
                    }
                }
                $lines[] = " *   \"created_at\": \"2026-01-22T10:00:00Z\"";
                break;
            case 'update':
            case 'edit':
                $lines[] = " *   \"id\": 1,";
                foreach (array_keys($this->metadata['validations']) as $field) {
                    if (!str_ends_with($field, '.*') && !str_contains($field, '.')) {
                        $lines[] = " *   \"" . $field . "\": \"value\",";
                    }
                }
                $lines[] = " *   \"updated_at\": \"2026-01-22T10:00:00Z\"";
                break;
            case 'destroy':
            case 'delete':
                $lines[] = " *   \"message\": \"" . $resourceName . " deleted successfully\"";
                break;
            default:
                $lines[] = " *   \"success\": true";
        }

        $lines[] = " * }";
        $lines[] = ' *';

        // Error responses - extract from controller
        if (!empty($this->metadata['responses'])) {
            foreach ($this->metadata['responses'] as $statusCode => $body) {
                $lines[] = " * @response {$statusCode} {";
                $lines[] = " *   \"message\": \"" . $this->cleanResponseMessage($body) . "\"";
                $lines[] = " * }";
                $lines[] = ' *';
            }
        }

        // Standard error responses
        if (in_array($this->methodName, ['index', 'show', 'update', 'destroy'])) {
            if (empty($this->metadata['responses'][404])) {
                $lines[] = " * @response 404 {";
                $lines[] = " *   \"message\": \"Resource not found\"";
                $lines[] = " * }";
                $lines[] = ' *';
            }
        }

        if (in_array($this->methodName, ['store', 'update'])) {
            if (empty($this->metadata['responses'][422])) {
                $lines[] = " * @response 422 {";
                $lines[] = " *   \"message\": \"The given data was invalid.\",";
                $lines[] = " *   \"errors\": {";
                $lines[] = " *     \"field_name\": [\"The field is required\"]";
                $lines[] = " *   }";
                $lines[] = " * }";
                $lines[] = ' *';
            }
        }

        if (preg_match('/authorize|gate|ability/i', $this->methodContent)) {
            if (empty($this->metadata['responses'][403])) {
                $lines[] = " * @response 403 {";
                $lines[] = " *   \"message\": \"This action is unauthorized\"";
                $lines[] = " * }";
                $lines[] = ' *';
            }
        }

        return $lines;
    }

    /**
     * Generate implementation notes
     */
    private function generateImplementationNotes(): array
    {
        $lines = [];

        $notes = [];

        if (preg_match('/DB::transaction|transaction/i', $this->methodContent)) {
            $notes[] = "⚠️ This operation is executed within a database transaction with automatic rollback on error.";
        }

        if (preg_match('/::dispatch|Queue/i', $this->methodContent)) {
            $notes[] = "🔄 Background jobs are dispatched asynchronously and may not complete immediately.";
        }

        if (preg_match('/Cache::|cache\(/i', $this->methodContent)) {
            $notes[] = "💾 Results may be cached. Cache is invalidated on data modification.";
        }

        if (preg_match('/\$this->authorize|gate\(/i', $this->methodContent)) {
            $notes[] = "🔐 Proper authorization is required. Returns 403 Forbidden if unauthorized.";
        }

        if (preg_match('/throttle|rateLimit/i', $this->methodContent)) {
            $notes[] = "⏱️ Rate limiting is applied. Exceeding limits returns 429 Too Many Requests.";
        }

        if (preg_match('/softDelete|withTrashed|forceDelete/i', $this->methodContent)) {
            $notes[] = "🗑️ Soft deletes are supported. Use 'with_trashed' parameter to include deleted records.";
        }

        if (preg_match('/Http::.*post|Http::.*put|Http::.*get/i', $this->methodContent)) {
            $notes[] = "🌐 This operation integrates with external APIs. Network delays may occur.";
        }

        if (!empty($notes)) {
            $lines[] = ' * ' . str_repeat('—', 70);
            foreach ($notes as $note) {
                $lines[] = ' * ' . $note;
            }
        }

        return $lines;
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
        
        return "/api/{$resource}/" . strtolower($this->methodName);
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
        return $this->getResourceName() . 's';
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

    private function getExampleForField(string $field, string $type): string
    {
        $examples = config('phpdoc-generator.examples', []);

        if (isset($examples[$field])) {
            return $examples[$field];
        }

        if ($type === 'integer') {
            return '1';
        }
        if ($type === 'date') {
            return '2026-01-22';
        }
        if ($type === 'boolean') {
            return 'true';
        }
        if ($type === 'array') {
            return '[1,2,3]';
        }

        if (str_contains($field, 'email')) {
            return 'user@example.com';
        }
        if (str_contains($field, 'name')) {
            return 'John Doe';
        }
        if (str_contains($field, 'price') || str_contains($field, 'amount')) {
            return '99.99';
        }
        if (str_contains($field, 'id') || str_contains($field, 'sub')) {
            return '1';
        }
        if (str_contains($field, 'phone') || str_contains($field, 'telephone')) {
            return '+393331234567';
        }
        if (str_contains($field, 'enabled') || str_contains($field, 'admin')) {
            return 'true';
        }

        return 'example_value';
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

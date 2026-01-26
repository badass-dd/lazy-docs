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
        'success_responses' => [],
        'exceptions' => [],
        'middleware' => [],
        'validations' => [],
        'model_fields' => [],
        'model_relations' => [],
        'existing_phpdoc' => [],
    ];

    private string $methodContent = '';

    private string $controllerContent = '';

    private ?string $modelClass = null;

    private array $existingDocTags = [];

    private bool $mergeMode = false;

    public function __construct(string $controllerClass, string $methodName, bool $mergeMode = false)
    {
        $this->controllerClass = $controllerClass;
        $this->methodName = $methodName;
        $this->faker = FakerFactory::create();
        $this->mergeMode = $mergeMode;

        $this->reflectionClass = new ReflectionClass($controllerClass);
        $this->reflectionMethod = $this->reflectionClass->getMethod($methodName);

        $this->extractMethodContent();
        $this->extractControllerContent();
        $this->extractExistingPhpDoc();
        $this->analyzeMethod();
        $this->extractValidationRules();
        $this->extractMiddleware();
        $this->extractErrorResponses();
        $this->extractSuccessResponses();
        $this->extractModelInfo();
    }

    /**
     * Extract existing PHPDoc from method (for merge mode)
     */
    private function extractExistingPhpDoc(): void
    {
        $docComment = $this->reflectionMethod->getDocComment();
        if (! $docComment) {
            return;
        }

        // Parse existing @response tags
        if (preg_match_all('/@response\s+(\d{3})\s+(\{[\s\S]*?\n\s*\*\s*\}|\[[\s\S]*?\n\s*\*\s*\])/m', $docComment, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $statusCode = (int) $match[1];
                $body = $this->cleanDocCommentBlock($match[2]);
                $this->existingDocTags['response'][$statusCode] = $body;
            }
        }

        // Parse existing @bodyParam tags
        if (preg_match_all('/@bodyParam\s+(\S+)\s+(\S+)\s+(required|optional)?\s*(.*?)(?=\n\s*\*\s*@|\n\s*\*\/)/s', $docComment, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $this->existingDocTags['bodyParam'][$match[1]] = [
                    'type' => $match[2],
                    'required' => $match[3] ?? 'optional',
                    'description' => trim($match[4]),
                ];
            }
        }

        // Parse existing @queryParam tags
        if (preg_match_all('/@queryParam\s+(\S+)\s+(\S+)\s+(required|optional)?\s*(.*?)(?=\n\s*\*\s*@|\n\s*\*\/)/s', $docComment, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $this->existingDocTags['queryParam'][$match[1]] = [
                    'type' => $match[2],
                    'required' => $match[3] ?? 'optional',
                    'description' => trim($match[4]),
                ];
            }
        }

        // Parse existing @group tag
        if (preg_match('/@group\s+(.+)$/m', $docComment, $match)) {
            $this->existingDocTags['group'] = trim($match[1]);
        }

        // Parse existing title (first line after /**)
        if (preg_match('/\/\*\*\s*\n\s*\*\s*([^@\n][^\n]*)/s', $docComment, $match)) {
            $title = trim($match[1]);
            if (! empty($title) && $title !== '*') {
                $this->existingDocTags['title'] = $title;
            }
        }

        // Parse description (lines after title before first @tag)
        if (preg_match('/\/\*\*\s*\n\s*\*\s*[^@\n][^\n]*\n((?:\s*\*\s*[^@\n][^\n]*\n)*)/s', $docComment, $match)) {
            $description = trim(preg_replace('/^\s*\*\s*/m', '', $match[1]));
            if (! empty($description)) {
                $this->existingDocTags['description'] = $description;
            }
        }

        // Parse @authenticated tag
        if (preg_match('/@authenticated/', $docComment)) {
            $this->existingDocTags['authenticated'] = true;
        }

        $this->metadata['existing_phpdoc'] = $this->existingDocTags;
    }

    /**
     * Clean PHPDoc comment block (remove * prefixes)
     */
    private function cleanDocCommentBlock(string $block): string
    {
        $lines = explode("\n", $block);
        $cleaned = [];
        foreach ($lines as $line) {
            $cleaned[] = preg_replace('/^\s*\*\s?/', '', $line);
        }

        return trim(implode("\n", $cleaned));
    }

    /**
     * Extract actual success responses from method code
     */
    private function extractSuccessResponses(): void
    {
        // Pattern 1: return response()->json(['key' => 'value']) - simple array without status code (defaults to 200)
        // This captures the most common Laravel pattern
        if (preg_match_all('/return\s+response\(\)\s*->\s*json\s*\(\s*(\[[^\]]*\])\s*\)\s*;/s', $this->methodContent, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $arrayContent = $match[1];
                $parsedResponse = $this->parsePhpArrayToJson($arrayContent);
                if ($parsedResponse && ! isset($this->metadata['success_responses'][200])) {
                    $this->metadata['success_responses'][200] = $parsedResponse;
                }
            }
        }

        // Pattern 2: return response()->json(['key' => 'value'], 200) - with explicit status code
        if (preg_match_all('/return\s+response\(\)\s*->\s*json\s*\(\s*(\[[^\]]*\])\s*,\s*(\d{3})\s*\)\s*;/s', $this->methodContent, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $arrayContent = $match[1];
                $statusCode = (int) $match[2];

                // Only capture success responses (2xx)
                if ($statusCode >= 200 && $statusCode < 300) {
                    $parsedResponse = $this->parsePhpArrayToJson($arrayContent);
                    if ($parsedResponse) {
                        $this->metadata['success_responses'][$statusCode] = $parsedResponse;
                    }
                }
            }
        }

        // Pattern 3: return response()->json($variable) - variable response
        if (preg_match_all('/return\s+response\(\)\s*->\s*json\s*\(\s*(\$\w+)\s*(?:,\s*(\d{3}))?\s*\)\s*;/s', $this->methodContent, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $varName = $match[1];
                $statusCode = isset($match[2]) ? (int) $match[2] : 200;

                if ($statusCode >= 200 && $statusCode < 300) {
                    $varContent = $this->traceVariableContent($varName);
                    if ($varContent) {
                        $this->metadata['success_responses'][$statusCode] = $varContent;
                    }
                }
            }
        }

        // Pattern 4: return $model or return $collection (Eloquent returns)
        if (preg_match('/return\s+(\$\w+)\s*;/', $this->methodContent, $match)) {
            $varName = $match[1];
            if (! isset($this->metadata['success_responses'][200])) {
                $varContent = $this->traceVariableContent($varName);
                if ($varContent) {
                    $this->metadata['success_responses'][200] = $varContent;
                }
            }
        }
    }

    /**
     * Parse PHP array syntax to JSON-like structure
     */
    private function parsePhpArrayToJson(string $arrayContent): ?array
    {
        $result = [];

        // Clean up the array content
        $arrayContent = trim($arrayContent);
        if (str_starts_with($arrayContent, '[')) {
            $arrayContent = substr($arrayContent, 1);
        }
        if (str_ends_with($arrayContent, ']')) {
            $arrayContent = substr($arrayContent, 0, -1);
        }

        // Match 'key' => value patterns (handles strings, null, true, false, numbers, variables)
        if (preg_match_all("/['\"](\w+)['\"]\s*=>\s*(.+?)(?=,\s*['\"]|\s*\]|\s*$)/ms", $arrayContent, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = $match[1];
                $value = trim($match[2], " \t\n\r\0\x0B,");

                // Handle null
                if ($value === 'null') {
                    $result[$key] = null;
                    continue;
                }

                // Handle true/false
                if ($value === 'true') {
                    $result[$key] = true;
                    continue;
                }
                if ($value === 'false') {
                    $result[$key] = false;
                    continue;
                }

                // Handle numeric values
                if (is_numeric($value)) {
                    $result[$key] = $value + 0; // Convert to number
                    continue;
                }

                // Handle string literals (single or double quoted)
                if (preg_match('/^[\'"](.+)[\'"]$/s', $value, $strMatch)) {
                    $strValue = $strMatch[1];
                    // Clean up interpolated variables in strings like "Invoice non trovata: {$e->getMessage()}"
                    $strValue = preg_replace('/\{\$\w+->getMessage\(\)\}/', '{error_message}', $strValue);
                    $strValue = preg_replace('/\$\w+->getMessage\(\)/', '{error_message}', $strValue);
                    $result[$key] = $strValue;
                    continue;
                }

                // Handle exception message calls
                if (preg_match('/\$\w+->getMessage\(\)/', $value)) {
                    $result[$key] = '{error_message}';
                    continue;
                }

                // Handle other variables
                if (str_starts_with($value, '$')) {
                    $result[$key] = $this->inferVariableType($value);
                    continue;
                }

                // Default: use as-is
                $result[$key] = $value;
            }
        }

        return ! empty($result) ? $result : null;
    }

    /**
     * Infer variable type from its name and context
     */
    private function inferVariableType(string $varName): mixed
    {
        $varName = ltrim($varName, '$');

        // Common patterns
        if (preg_match('/message|msg|text/i', $varName)) {
            return 'Message text';
        }
        if (preg_match('/error|err/i', $varName)) {
            return 'Error description';
        }
        if (preg_match('/id|Id|ID/', $varName)) {
            return 1;
        }
        if (preg_match('/count|total|num/i', $varName)) {
            return 0;
        }
        if (preg_match('/success|ok|result/i', $varName)) {
            return true;
        }

        return 'value';
    }

    /**
     * Try to trace what a variable contains
     */
    private function traceVariableContent(string $varName): ?array
    {
        $varName = preg_quote(ltrim($varName, '$'), '/');

        // Check if variable is assigned from response()->json()
        if (preg_match('/\$'.$varName.'\s*=\s*(\[[\s\S]*?\])\s*;/s', $this->methodContent, $match)) {
            return $this->parsePhpArrayToJson($match[1]);
        }

        // Check if it's a collection/query result (return $pazienti, $performance, etc.)
        if (preg_match('/\$'.$varName.'\s*=\s*.*?(?:->get\(|->select\(|DB::select)/s', $this->methodContent)) {
            return ['_type' => 'query_result', '_variable' => $varName];
        }

        return null;
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
        // Pattern 1: Find all response()->json([...], statusCode) with inline array
        if (preg_match_all("/response\(\)\s*->\s*json\s*\(\s*\[([^\]]+)\]\s*,\s*(\d{3})\s*\)/s", $this->methodContent, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $statusCode = (int) $match[2];
                $arrayContent = trim($match[1]);

                if ($statusCode >= 400) {
                    // Parse the array content to extract key and message
                    $errorResponse = $this->parseErrorArrayContent($arrayContent, $statusCode);
                    $this->metadata['responses'][$statusCode] = $errorResponse;
                }
            }
        }

        // Pattern 2: Find response()->json($variable, statusCode) - variable response
        if (preg_match_all('/return\s+response\(\)\s*->\s*json\s*\(\s*(\$\w+)\s*,\s*(\d{3})\s*\)\s*;/s', $this->methodContent, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $varName = $match[1];
                $statusCode = (int) $match[2];

                if ($statusCode >= 400 && ! isset($this->metadata['responses'][$statusCode])) {
                    $varContent = $this->traceVariableContent($varName);
                    if ($varContent && is_array($varContent)) {
                        $this->metadata['responses'][$statusCode] = $varContent;
                    }
                }
            }
        }

        // Also find abort() calls
        if (preg_match_all("/abort\s*\(\s*(\d{3})\s*,?\s*['\"]([^'\"]*)['\"]?\s*\)/", $this->methodContent, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $statusCode = (int) $match[1];
                $message = $match[2];

                if ($statusCode >= 400) {
                    $this->metadata['responses'][$statusCode] = ['message' => $message];
                }
            }
        }
    }

    /**
     * Parse error array content to extract key-value pairs
     */
    private function parseErrorArrayContent(string $arrayContent, int $statusCode): array
    {
        $result = [];

        // Pattern to match 'key' => 'value' or 'key' => 'value' . $var->method()
        // We want to extract the key and the static part of the message
        if (preg_match_all("/['\"](\w+)['\"]\s*=>\s*['\"]([^'\"]*)['\"](?:\s*\.\s*[^\,\]]+)?/", $arrayContent, $keyValueMatches, PREG_SET_ORDER)) {
            foreach ($keyValueMatches as $kv) {
                $key = $kv[1];
                $value = $kv[2];

                // For error messages that have dynamic parts (like $e->getMessage()),
                // we use a generic placeholder description
                if ($statusCode >= 500 && str_contains($arrayContent, '$e->getMessage()')) {
                    $result[$key] = $value.'{exception_message}';
                } elseif (str_contains($arrayContent, '$e->getMessage()') || str_contains($arrayContent, '->getMessage()')) {
                    $result[$key] = $value.'{error_message}';
                } else {
                    $result[$key] = $value;
                }
            }
        }

        // If no key-value pairs found, return a generic structure
        if (empty($result)) {
            if ($statusCode >= 500) {
                $result['error'] = 'Internal server error';
            } elseif ($statusCode === 404) {
                $result['message'] = 'Resource not found';
            } elseif ($statusCode === 403) {
                $result['message'] = 'Forbidden';
            } elseif ($statusCode === 401) {
                $result['message'] = 'Unauthenticated';
            } else {
                $result['error'] = 'An error occurred';
            }
        }

        return $result;
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

            // If no fillable/visible, try to extract from PHPDoc @property annotations
            if (empty($fields)) {
                $fields = $this->extractFieldsFromPhpDoc($reflection);
            }

            // If still no fields, try to get from database schema
            if (empty($fields)) {
                $fields = $this->extractFieldsFromDatabase($instance);
            }

            // Check if fields is associative (from PHPDoc/DB) or sequential (from fillable)
            $isAssociative = ! empty($fields) && ! array_is_list($fields);

            if ($isAssociative) {
                // Fields already have types, just ensure standard fields exist
                if (! isset($fields['id'])) {
                    $fields = ['id' => 'integer'] + $fields;
                }
                if (! isset($fields['created_at'])) {
                    $fields['created_at'] = 'datetime';
                }
                if (! isset($fields['updated_at'])) {
                    $fields['updated_at'] = 'datetime';
                }
            } else {
                // Sequential array - add standard fields
                $standardFields = ['id', 'created_at', 'updated_at'];
                $fields = array_merge($standardFields, $fields);
                $fields = array_unique($fields);
            }

            // Remove hidden fields
            if ($isAssociative) {
                foreach ($hidden as $hiddenField) {
                    unset($fields[$hiddenField]);
                }
            } else {
                $fields = array_diff($fields, $hidden);
            }

            // Build field metadata with types
            foreach ($fields as $field => $type) {
                // If $fields is a sequential array (from fillable), convert to associative
                if (is_int($field)) {
                    $field = $type;
                    $type = null;
                }

                // Determine type
                if ($type === null || ! is_string($type)) {
                    // Infer type from casts first
                    if (isset($casts[$field])) {
                        $type = $this->inferTypeFromCast($casts[$field]);
                    } else {
                        // Infer type from field name
                        $type = $this->inferTypeFromFieldName($field);
                    }
                }

                $this->metadata['model_fields'][$field] = $type;
            }
        } catch (\Throwable $e) {
            // Silent fail - model might not be instantiable
        }
    }

    /**
     * Extract fields from PHPDoc @property annotations
     */
    private function extractFieldsFromPhpDoc(ReflectionClass $reflection): array
    {
        $docComment = $reflection->getDocComment();
        if (! $docComment) {
            return [];
        }

        $fields = [];

        // Match @property type $name patterns
        if (preg_match_all('/@property\s+([^\s]+)\s+\$(\w+)/', $docComment, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $phpType = $match[1];
                $fieldName = $match[2];

                // Convert PHP types to our type system
                $type = $this->convertPhpTypeToFieldType($phpType);
                $fields[$fieldName] = $type;
            }
        }

        return $fields;
    }

    /**
     * Extract fields from database schema
     */
    private function extractFieldsFromDatabase($modelInstance): array
    {
        try {
            $table = $modelInstance->getTable();
            $connection = $modelInstance->getConnection();
            $columns = $connection->getSchemaBuilder()->getColumnListing($table);

            $fields = [];
            foreach ($columns as $column) {
                try {
                    $columnType = $connection->getSchemaBuilder()->getColumnType($table, $column);
                    $fields[$column] = $this->convertDbTypeToFieldType($columnType);
                } catch (\Throwable $e) {
                    $fields[$column] = 'string';
                }
            }

            return $fields;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Convert PHP type annotation to field type
     */
    private function convertPhpTypeToFieldType(string $phpType): string
    {
        // Remove nullable indicator
        $phpType = ltrim($phpType, '?');

        // Handle union types (take first non-null)
        if (str_contains($phpType, '|')) {
            $types = explode('|', $phpType);
            foreach ($types as $t) {
                if (strtolower($t) !== 'null') {
                    $phpType = $t;
                    break;
                }
            }
        }

        $phpType = strtolower($phpType);

        return match ($phpType) {
            'int', 'integer' => 'integer',
            'bool', 'boolean' => 'boolean',
            'float', 'double' => 'number',
            'array' => 'array',
            '\illuminate\support\carbon', 'carbon', 'datetime', '\datetime' => 'datetime',
            default => 'string',
        };
    }

    /**
     * Convert database column type to field type
     */
    private function convertDbTypeToFieldType(string $dbType): string
    {
        $dbType = strtolower($dbType);

        return match (true) {
            in_array($dbType, ['int', 'integer', 'bigint', 'smallint', 'tinyint', 'mediumint']) => 'integer',
            in_array($dbType, ['bool', 'boolean']) => 'boolean',
            in_array($dbType, ['float', 'double', 'decimal', 'real']) => 'number',
            in_array($dbType, ['json', 'jsonb']) => 'array',
            in_array($dbType, ['date', 'datetime', 'timestamp', 'time']) => 'datetime',
            default => 'string',
        };
    }

    /**
     * Infer type from Laravel cast
     */
    private function inferTypeFromCast(string $castType): string
    {
        $castType = strtolower($castType);

        // Handle cast classes and parameters like 'decimal:2'
        if (str_contains($castType, ':')) {
            $castType = explode(':', $castType)[0];
        }

        return match ($castType) {
            'int', 'integer' => 'integer',
            'bool', 'boolean' => 'boolean',
            'float', 'double', 'decimal', 'real' => 'number',
            'array', 'json', 'collection', 'object' => 'array',
            'date', 'datetime', 'timestamp', 'immutable_date', 'immutable_datetime' => 'datetime',
            default => 'string',
        };
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
     * Now analyzes actual return statements in the code
     */
    private function generateResponseDocs(): array
    {
        $lines = [];
        $resourceName = $this->getResourceName();
        $timestamp = $this->faker->dateTimeThisYear()->format('Y-m-d\TH:i:s.000000\Z');
        $generatedStatusCodes = [];

        // In merge mode, first add existing responses that are well-formatted
        if ($this->mergeMode && ! empty($this->existingDocTags['response'])) {
            foreach ($this->existingDocTags['response'] as $statusCode => $body) {
                // Reformat the body with proper PHPDoc formatting
                $lines[] = " * @response {$statusCode} {";
                $bodyLines = explode("\n", trim($body, "{}[]"));
                foreach ($bodyLines as $bodyLine) {
                    $bodyLine = trim($bodyLine);
                    if (! empty($bodyLine)) {
                        $lines[] = " *   {$bodyLine}";
                    }
                }
                $lines[] = ' * }';
                $lines[] = ' *';
                $generatedStatusCodes[] = $statusCode;
            }
        }

        // Add new responses (both in merge mode and normal mode)
        // In merge mode, this adds responses not already present in existing doc
        // Check if we have actual success responses extracted from code
        if (! empty($this->metadata['success_responses'])) {
            foreach ($this->metadata['success_responses'] as $statusCode => $responseData) {
                if (in_array($statusCode, $generatedStatusCodes)) {
                    continue;
                }
                if (is_array($responseData)) {
                    // Check if it's a query result marker
                    if (isset($responseData['_type']) && $responseData['_type'] === 'query_result') {
                        // Generate response based on method analysis
                        $lines = array_merge($lines, $this->generateQueryResultResponse($statusCode));
                    } else {
                        // Use the actual parsed response structure
                        $lines[] = " * @response {$statusCode} {";
                        foreach ($responseData as $key => $value) {
                            $formattedValue = $this->formatResponseValue($value);
                            $lines[] = " *   \"{$key}\": {$formattedValue}";
                        }
                        $lines[] = ' * }';
                        $lines[] = ' *';
                    }
                    $generatedStatusCodes[] = $statusCode;
                }
            }
        } elseif (! $this->mergeMode) {
            // Fallback to method-based generation only when not in merge mode
            $lines = array_merge($lines, $this->generateMethodBasedResponse($resourceName, $timestamp));
        }

        // Add error responses extracted from controller
        if (! empty($this->metadata['responses'])) {
            foreach ($this->metadata['responses'] as $statusCode => $body) {
                if (in_array($statusCode, $generatedStatusCodes)) {
                    continue;
                }
                $lines[] = " * @response {$statusCode} {";
                if (is_array($body)) {
                    // New format: array with key-value pairs
                    $lastKey = array_key_last($body);
                    foreach ($body as $key => $value) {
                        $comma = $key !== $lastKey ? ',' : '';
                        $lines[] = " *   \"{$key}\": \"{$value}\"{$comma}";
                    }
                } else {
                    // Legacy format: string message
                    $lines[] = ' *   "error": "'.$this->cleanResponseMessage($body).'"';
                }
                $lines[] = ' * }';
                $lines[] = ' *';
                $generatedStatusCodes[] = $statusCode;
            }
        }

        // Add standard error responses based on method type (only if not already present)
        $this->addMissingErrorResponses($lines, $resourceName, $generatedStatusCodes);

        return $lines;
    }

    /**
     * Format a response value for JSON output
     */
    private function formatResponseValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_numeric($value)) {
            return (string) $value;
        }
        if (is_array($value)) {
            return json_encode($value);
        }

        return '"'.addslashes((string) $value).'"';
    }

    /**
     * Generate response for query results (arrays/collections)
     */
    private function generateQueryResultResponse(int $statusCode): array
    {
        $lines = [];
        $fieldsToUse = $this->getResponseFields();
        $timestamp = $this->faker->dateTimeThisYear()->format('Y-m-d\TH:i:s.000000\Z');

        $lines[] = " * @response {$statusCode} [";
        $lines[] = ' *   {';

        if (! empty($fieldsToUse)) {
            $lines[] = ' *     "id": '.$this->faker->numberBetween(1, 100).',';
            foreach ($fieldsToUse as $field => $type) {
                if ($field === 'id' || $field === 'created_at' || $field === 'updated_at') {
                    continue;
                }
                $example = $this->generateFakerExample($field, $type, '');
                $value = $this->formatJsonValue($example, $type);
                $lines[] = " *     \"{$field}\": {$value},";
            }
            $this->addRelationsToResponse($lines, '    ');
            $lines[] = " *     \"created_at\": \"{$timestamp}\"";
        } else {
            // Generic structure
            $lines[] = ' *     "id": 1,';
            $lines[] = ' *     "name": "Example"';
        }

        $lines[] = ' *   }';
        $lines[] = ' * ]';
        $lines[] = ' *';

        return $lines;
    }

    /**
     * Generate method-based response (fallback for standard CRUD methods)
     */
    private function generateMethodBasedResponse(string $resourceName, string $timestamp): array
    {
        $lines = [];
        $successStatus = $this->getSuccessHttpStatus();
        $fieldsToUse = $this->getResponseFields();

        switch ($this->methodName) {
            case 'index':
            case 'list':
                $lines[] = " * @response {$successStatus} {";
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
                $this->addRelationsToResponse($lines, '    ');
                $lines[] = " *     \"created_at\": \"{$timestamp}\"";
                $lines[] = ' *   }],';
                $lines[] = ' *   "meta": {"current_page": 1, "per_page": 15, "total": '.$this->faker->numberBetween(10, 200).'}';
                $lines[] = ' * }';
                $lines[] = ' *';
                break;

            case 'show':
                $lines[] = " * @response {$successStatus} {";
                $lines[] = ' *   "id": '.$this->faker->numberBetween(1, 100).',';
                foreach ($fieldsToUse as $field => $type) {
                    if ($field === 'id' || $field === 'created_at' || $field === 'updated_at') {
                        continue;
                    }
                    $example = $this->generateFakerExample($field, $type, '');
                    $value = $this->formatJsonValue($example, $type);
                    $lines[] = " *   \"{$field}\": {$value},";
                }
                $this->addRelationsToResponse($lines, '  ');
                $lines[] = " *   \"created_at\": \"{$timestamp}\",";
                $lines[] = " *   \"updated_at\": \"{$timestamp}\"";
                $lines[] = ' * }';
                $lines[] = ' *';
                break;

            case 'store':
            case 'create':
                $lines[] = " * @response {$successStatus} {";
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
                $lines[] = ' * }';
                $lines[] = ' *';
                break;

            case 'update':
            case 'edit':
                $lines[] = " * @response {$successStatus} {";
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
                $lines[] = ' * }';
                $lines[] = ' *';
                break;

            case 'destroy':
            case 'delete':
                $lines[] = " * @response {$successStatus} {";
                $lines[] = " *   \"message\": \"{$resourceName} deleted successfully\"";
                $lines[] = ' * }';
                $lines[] = ' *';
                break;

            default:
                // For custom methods, try to infer from actual code
                $lines = array_merge($lines, $this->generateCustomMethodResponse($successStatus));
                break;
        }

        return $lines;
    }

    /**
     * Generate response for custom (non-CRUD) methods by analyzing code
     */
    private function generateCustomMethodResponse(int $successStatus): array
    {
        $lines = [];

        // Try to extract actual response structure from return statements
        if (preg_match('/return\s+response\(\)\s*->\s*json\s*\(\s*\[([^\]]*)\]/s', $this->methodContent, $match)) {
            $arrayContent = '['.$match[1].']';
            $parsed = $this->parsePhpArrayToJson($arrayContent);

            if ($parsed && ! empty($parsed)) {
                $lines[] = " * @response {$successStatus} {";
                $isFirst = true;
                $keys = array_keys($parsed);
                $lastKey = end($keys);

                foreach ($parsed as $key => $value) {
                    $formattedValue = $this->formatResponseValue($value);
                    $comma = ($key !== $lastKey) ? ',' : '';
                    $lines[] = " *   \"{$key}\": {$formattedValue}{$comma}";
                }
                $lines[] = ' * }';
                $lines[] = ' *';

                return $lines;
            }
        }

        // Check if method returns a variable that's a collection/array
        if (preg_match('/return\s+response\(\)\s*->\s*json\s*\(\s*\$(\w+)/s', $this->methodContent, $match)) {
            $varName = $match[1];

            // Check if it's empty check before returning
            if (preg_match('/if\s*\(\s*empty\s*\(\s*\$'.$varName.'\s*\)\s*\)/', $this->methodContent)) {
                // This is likely returning an array/collection
                $lines = array_merge($lines, $this->generateQueryResultResponse($successStatus));

                return $lines;
            }
        }

        // Default fallback - try to generate something meaningful
        $lines[] = " * @response {$successStatus} {";

        // Check for common patterns in method content
        if (preg_match('/\[\s*[\'"]message[\'"]\s*=>\s*[\'"]([^"\']+)[\'"]/s', $this->methodContent, $msgMatch)) {
            $lines[] = ' *   "message": "'.$msgMatch[1].'"';
        } elseif (preg_match('/\[\s*[\'"]error[\'"]\s*=>\s*[\'"]([^"\']+)[\'"]/s', $this->methodContent, $errMatch)) {
            $lines[] = ' *   "message": "Operation completed successfully"';
        } else {
            // Generic success response
            $lines[] = ' *   "message": "Operation completed successfully"';
        }

        $lines[] = ' * }';
        $lines[] = ' *';

        return $lines;
    }

    /**
     * Add missing standard error responses based on method type
     */
    private function addMissingErrorResponses(array &$lines, string $resourceName, array $existingStatusCodes = []): void
    {
        // 404 for methods that work with specific resources
        if (in_array($this->methodName, ['show', 'update', 'destroy', 'edit', 'delete'])) {
            if (empty($this->metadata['responses'][404]) && ! in_array(404, $existingStatusCodes)) {
                $lines[] = ' * @response 404 {';
                $lines[] = " *   \"message\": \"{$resourceName} not found\"";
                $lines[] = ' * }';
                $lines[] = ' *';
            }
        }

        // 422 for methods that accept input
        if (in_array($this->methodName, ['store', 'update', 'create', 'edit'])) {
            if (empty($this->metadata['responses'][422]) && ! in_array(422, $existingStatusCodes)) {
                $lines[] = ' * @response 422 {';
                $lines[] = ' *   "message": "The given data was invalid.",';
                $lines[] = ' *   "errors": {';
                $firstField = array_key_first($this->metadata['validations']) ?? 'field_name';
                $lines[] = " *     \"{$firstField}\": [\"The {$firstField} field is required.\"]";
                $lines[] = ' *   }';
                $lines[] = ' * }';
                $lines[] = ' *';
            }
        }

        // 403 for methods with authorization
        if (preg_match('/authorize|gate|ability/i', $this->methodContent)) {
            if (empty($this->metadata['responses'][403]) && ! in_array(403, $existingStatusCodes)) {
                $lines[] = ' * @response 403 {';
                $lines[] = ' *   "message": "This action is unauthorized."';
                $lines[] = ' * }';
                $lines[] = ' *';
            }
        }

        // 500 - check if method has try-catch
        if (preg_match('/catch\s*\(\s*\\\\?Throwable|\s*catch\s*\(\s*\\\\?Exception/i', $this->methodContent)) {
            if (empty($this->metadata['responses'][500]) && ! in_array(500, $existingStatusCodes)) {
                $lines[] = ' * @response 500 {';
                $lines[] = ' *   "error": "Internal server error"';
                $lines[] = ' * }';
                $lines[] = ' *';
            }
        }
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

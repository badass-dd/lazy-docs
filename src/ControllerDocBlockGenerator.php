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
        $this->extractQueryParameters();
        $this->extractMiddleware();
        $this->extractErrorResponses();
        $this->extractSuccessResponses();
        $this->extractModelInfo();
        $this->extractFileUploads();
        $this->extractAuthorization();
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
        // Pattern 0: return response()->download() - file download response
        // This should be detected first to generate proper binary response documentation
        if (preg_match('/return\s+response\(\)\s*->\s*download\s*\(/s', $this->methodContent)) {
            if (! isset($this->metadata['success_responses'][200])) {
                $this->metadata['success_responses'][200] = [
                    '_type' => 'binary_download',
                ];
            }
        }

        // Pattern 1: return response()->json(['key' => 'value']) - simple array without status code (defaults to 200)
        // This captures the most common Laravel pattern
        // Use negative lookahead to exclude cases with explicit status code
        if (preg_match_all('/return\s+response\(\)\s*->\s*json\s*\(\s*(\[[^\]]*\])\s*\)(?!\s*,\s*\d{3})\s*;/s', $this->methodContent, $matches, PREG_SET_ORDER)) {
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

        // Pattern 3b: return response()->json($model->load('rel1', 'rel2.nested'), statusCode?) - model with eager loaded relations
        // Status code is optional - defaults to 200
        if (preg_match_all('/return\s+response\(\)\s*->\s*json\s*\(\s*\$(\w+)->load\s*\(\s*([^)]+)\s*\)(?:\s*,\s*(\d{3}))?\s*\)\s*;/s', $this->methodContent, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $modelVarName = $match[1];
                $loadContent = $match[2];
                $statusCode = isset($match[3]) ? (int) $match[3] : 200;

                if ($statusCode >= 200 && $statusCode < 300 && ! isset($this->metadata['success_responses'][$statusCode])) {
                    // Try to get the model type from the variable
                    $varContent = $this->traceVariableContent('$'.$modelVarName);
                    $modelClass = $varContent['_model'] ?? null;

                    // If we can't trace the variable, try to infer from variable name
                    if (! $modelClass) {
                        $modelClass = ucfirst($modelVarName);
                    }

                    $modelFields = $this->extractModelFields($modelClass);
                    if (! empty($modelFields)) {
                        // Parse the eager relations from load()
                        $eagerRelations = $this->parseEagerLoadRelations($loadContent, $modelClass);

                        $this->metadata['success_responses'][$statusCode] = [
                            '_type' => 'model',
                            '_model' => $modelClass,
                            '_fields' => $modelFields,
                            '_eager_relations' => $eagerRelations,
                        ];
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

        // Pattern 4b: return $model->load('rel1', 'rel2.nested') - direct model return with eager loading
        if (preg_match('/return\s+\$(\w+)->load\s*\(\s*([^)]+)\s*\)\s*;/s', $this->methodContent, $match)) {
            $modelVarName = $match[1];
            $loadContent = $match[2];

            if (! isset($this->metadata['success_responses'][200])) {
                // Try to get the model type from the variable
                $varContent = $this->traceVariableContent('$'.$modelVarName);
                $modelClass = $varContent['_model'] ?? null;

                // If we can't trace the variable, try to infer from variable name
                if (! $modelClass) {
                    $modelClass = ucfirst($modelVarName);
                }

                $modelFields = $this->extractModelFields($modelClass);
                if (! empty($modelFields)) {
                    // Parse the eager relations from load()
                    $eagerRelations = $this->parseEagerLoadRelations($loadContent, $modelClass);

                    $this->metadata['success_responses'][200] = [
                        '_type' => 'model',
                        '_model' => $modelClass,
                        '_fields' => $modelFields,
                        '_eager_relations' => $eagerRelations,
                    ];
                }
            }
        }

        // Pattern 5: return Model::with([...])->get/find/etc - eager loading (MUST be before Pattern 6)
        if (preg_match('/return\s+([A-Z][a-zA-Z0-9_]*)::with\s*\(([^)]+)\)\s*->\s*(?:get|all)\s*\(\s*\)\s*;/s', $this->methodContent, $match)) {
            $modelClass = $match[1];
            $withContent = $match[2];
            if (! isset($this->metadata['success_responses'][200])) {
                $modelFields = $this->extractModelFields($modelClass);
                if (! empty($modelFields)) {
                    // Extract relations from with() call
                    $eagerRelations = $this->parseEagerLoadRelations($withContent, $modelClass);

                    $this->metadata['success_responses'][200] = [
                        '_type' => 'collection',
                        '_model' => $modelClass,
                        '_fields' => $modelFields,
                        '_with_relations' => true,
                        '_eager_relations' => $eagerRelations,
                    ];
                }
            }
        }

        // Pattern 6: return Model::collectionMethods() - direct Eloquent collection return (without with())
        // Supports: get, all, where()->get(), latest()->get(), oldest()->get(), orderBy()->get()
        // Also handles chained methods like Model::where(...)->orderBy(...)->get()
        $collectionMethods = 'get|all';
        if (preg_match('/return\s+([A-Z][a-zA-Z0-9_]*)::(?:[^;]*?)(?:'.$collectionMethods.')\s*\(\s*\)\s*;/s', $this->methodContent, $match)) {
            $modelClass = $match[1];
            if (! isset($this->metadata['success_responses'][200])) {
                $modelFields = $this->extractModelFields($modelClass);
                if (! empty($modelFields)) {
                    $this->metadata['success_responses'][200] = [
                        '_type' => 'collection',
                        '_model' => $modelClass,
                        '_fields' => $modelFields,
                    ];
                }
            }
        }

        // Pattern 6: return Model::singleModelMethods() - direct Eloquent single model return
        // Supports: find, findOrFail, findOr, first, firstOrFail, firstWhere, firstOrCreate,
        // firstOrNew, sole, findOrNew, where()->first(), latest()->first(), etc.
        $singleModelMethods = 'findOrFail|find|findOr|findOrNew|first|firstOrFail|firstWhere|firstOrCreate|firstOrNew|sole';
        if (preg_match('/return\s+([A-Z][a-zA-Z0-9_]*)::(?:[^;]*?)(?:'.$singleModelMethods.')\s*\([^)]*\)\s*;/s', $this->methodContent, $match)) {
            $modelClass = $match[1];
            if (! isset($this->metadata['success_responses'][200])) {
                $modelFields = $this->extractModelFields($modelClass);
                if (! empty($modelFields)) {
                    $this->metadata['success_responses'][200] = [
                        '_type' => 'model',
                        '_model' => $modelClass,
                        '_fields' => $modelFields,
                    ];
                }
            }
        }

        // Pattern 7: return Model::paginate() / simplePaginate() / cursorPaginate() - paginated results
        // Detect specific pagination type for proper response structure
        if (preg_match('/return\s+([A-Z][a-zA-Z0-9_]*)::(?:[^;]*?)(paginate|simplePaginate|cursorPaginate)\s*\([^)]*\)\s*;/s', $this->methodContent, $match)) {
            $modelClass = $match[1];
            $paginationType = $match[2];
            if (! isset($this->metadata['success_responses'][200])) {
                $modelFields = $this->extractModelFields($modelClass);
                if (! empty($modelFields)) {
                    $this->metadata['success_responses'][200] = [
                        '_type' => $paginationType === 'simplePaginate' ? 'simple_paginated' :
                                  ($paginationType === 'cursorPaginate' ? 'cursor_paginated' : 'paginated'),
                        '_model' => $modelClass,
                        '_fields' => $modelFields,
                    ];
                }
            }
        }

        // Pattern 8: return Model::pluck() - returns array of single column values
        if (preg_match('/return\s+([A-Z][a-zA-Z0-9_]*)::(?:[^;]*?)pluck\s*\(\s*[\'"](\w+)[\'"]/s', $this->methodContent, $match)) {
            $modelClass = $match[1];
            $columnName = $match[2];
            if (! isset($this->metadata['success_responses'][200])) {
                $this->metadata['success_responses'][200] = [
                    '_type' => 'pluck',
                    '_model' => $modelClass,
                    '_column' => $columnName,
                ];
            }
        }

        // Pattern 9: return Model::count() / exists() / doesntExist() / max() / min() / avg() / sum() - scalar returns
        $scalarMethods = 'count|exists|doesntExist|max|min|avg|sum|average';
        if (preg_match('/return\s+([A-Z][a-zA-Z0-9_]*)::(?:[^;]*?)(?:'.$scalarMethods.')\s*\([^)]*\)\s*;/s', $this->methodContent, $match)) {
            $modelClass = $match[1];
            if (! isset($this->metadata['success_responses'][200])) {
                // Determine the return type based on method
                if (preg_match('/(?:exists|doesntExist)\s*\(/', $this->methodContent)) {
                    $this->metadata['success_responses'][200] = [
                        '_type' => 'scalar',
                        '_scalar_type' => 'boolean',
                    ];
                } else {
                    $this->metadata['success_responses'][200] = [
                        '_type' => 'scalar',
                        '_scalar_type' => 'integer',
                    ];
                }
            }
        }

        // Pattern 10: return Model::value() - returns single column value
        if (preg_match('/return\s+([A-Z][a-zA-Z0-9_]*)::(?:[^;]*?)value\s*\(\s*[\'"](\w+)[\'"]/s', $this->methodContent, $match)) {
            $modelClass = $match[1];
            $columnName = $match[2];
            if (! isset($this->metadata['success_responses'][200])) {
                $this->metadata['success_responses'][200] = [
                    '_type' => 'value',
                    '_model' => $modelClass,
                    '_column' => $columnName,
                ];
            }
        }

        // Pattern 11: return Model::findMany([...]) - returns collection of models by IDs
        if (preg_match('/return\s+([A-Z][a-zA-Z0-9_]*)::(?:[^;]*?)findMany\s*\(/s', $this->methodContent, $match)) {
            $modelClass = $match[1];
            if (! isset($this->metadata['success_responses'][200])) {
                $modelFields = $this->extractModelFields($modelClass);
                if (! empty($modelFields)) {
                    $this->metadata['success_responses'][200] = [
                        '_type' => 'collection',
                        '_model' => $modelClass,
                        '_fields' => $modelFields,
                    ];
                }
            }
        }

        // Pattern 12: return $model->relationMethod or $model->relation - relation access
        if (preg_match('/return\s+\$(\w+)->(\w+)(?:\(\))?(?:->get\(\))?\s*;/', $this->methodContent, $match)) {
            $varName = $match[1];
            $relationName = $match[2];
            // Try to detect if it's a relationship by checking model assignment
            if (! isset($this->metadata['success_responses'][200])) {
                $varContent = $this->traceVariableContent('$'.$varName);
                if ($varContent && isset($varContent['_type'])) {
                    // It's likely a relationship call
                    $this->metadata['success_responses'][200] = [
                        '_type' => 'relation',
                        '_relation' => $relationName,
                        '_parent_model' => $varContent['_model'] ?? null,
                    ];
                }
            }
        }

        // Pattern 14: return new ModelResource($model) - API Resource single model
        if (preg_match('/return\s+new\s+([A-Z][a-zA-Z0-9_]*)Resource\s*\(\s*\$(\w+)\s*\)\s*;/s', $this->methodContent, $match)) {
            $resourceClass = $match[1];
            $modelVar = $match[2];
            if (! isset($this->metadata['success_responses'][200])) {
                $resourceFields = $this->extractApiResourceFields($resourceClass.'Resource');
                if (! empty($resourceFields)) {
                    $this->metadata['success_responses'][200] = [
                        '_type' => 'api_resource',
                        '_resource_class' => $resourceClass.'Resource',
                        '_fields' => $resourceFields,
                    ];
                } else {
                    // Fallback to model fields
                    $modelFields = $this->extractModelFields($resourceClass);
                    if (! empty($modelFields)) {
                        $this->metadata['success_responses'][200] = [
                            '_type' => 'model',
                            '_model' => $resourceClass,
                            '_fields' => $modelFields,
                        ];
                    }
                }
            }
        }

        // Pattern 15: return ModelResource::collection($models) - API Resource collection
        if (preg_match('/return\s+([A-Z][a-zA-Z0-9_]*)Resource::collection\s*\(\s*\$(\w+)\s*\)\s*;/s', $this->methodContent, $match)) {
            $resourceClass = $match[1];
            $collectionVar = $match[2];
            if (! isset($this->metadata['success_responses'][200])) {
                $resourceFields = $this->extractApiResourceFields($resourceClass.'Resource');
                // Check if it's paginated
                $isPaginated = (bool) preg_match('/\$'.preg_quote($collectionVar, '/').'\\s*=\\s*[^;]*(?:paginate|simplePaginate|cursorPaginate)\s*\(/s', $this->methodContent);
                if (! empty($resourceFields)) {
                    $this->metadata['success_responses'][200] = [
                        '_type' => $isPaginated ? 'api_resource_paginated' : 'api_resource_collection',
                        '_resource_class' => $resourceClass.'Resource',
                        '_fields' => $resourceFields,
                    ];
                } else {
                    $modelFields = $this->extractModelFields($resourceClass);
                    if (! empty($modelFields)) {
                        $this->metadata['success_responses'][200] = [
                            '_type' => $isPaginated ? 'paginated' : 'collection',
                            '_model' => $resourceClass,
                            '_fields' => $modelFields,
                        ];
                    }
                }
            }
        }

        // Pattern 16: return new ModelCollection($models) - API Resource Collection class
        if (preg_match('/return\s+new\s+([A-Z][a-zA-Z0-9_]*)Collection\s*\(\s*\$(\w+)\s*\)\s*;/s', $this->methodContent, $match)) {
            $collectionClass = $match[1];
            $collectionVar = $match[2];
            if (! isset($this->metadata['success_responses'][200])) {
                // Try to find the corresponding Resource class
                $resourceFields = $this->extractApiResourceFields($collectionClass.'Resource');
                $isPaginated = (bool) preg_match('/\$'.preg_quote($collectionVar, '/').'\\s*=\\s*[^;]*(?:paginate|simplePaginate|cursorPaginate)\s*\(/s', $this->methodContent);
                if (! empty($resourceFields)) {
                    $this->metadata['success_responses'][200] = [
                        '_type' => $isPaginated ? 'api_resource_paginated' : 'api_resource_collection',
                        '_resource_class' => $collectionClass.'Resource',
                        '_fields' => $resourceFields,
                    ];
                }
            }
        }

        // Pattern 17: Detect withCount, withSum, withAvg, etc. in queries
        $this->extractAggregateFields();
    }

    /**
     * Extract fields from Laravel API Resource class
     */
    private function extractApiResourceFields(string $resourceClass): array
    {
        $fields = [];

        // Try common namespaces for Resources
        $namespaces = [
            'App\\Http\\Resources\\',
            'App\\Resources\\',
        ];

        $fullClassName = null;
        foreach ($namespaces as $namespace) {
            $testClass = $namespace.$resourceClass;
            if (class_exists($testClass)) {
                $fullClassName = $testClass;
                break;
            }
        }

        if (! $fullClassName) {
            return $fields;
        }

        try {
            $reflection = new \ReflectionClass($fullClassName);

            // Find toArray method
            if ($reflection->hasMethod('toArray')) {
                $method = $reflection->getMethod('toArray');
                $filename = $method->getFileName();
                $startLine = $method->getStartLine();
                $endLine = $method->getEndLine();

                if ($filename && file_exists($filename)) {
                    $lines = file($filename);
                    $methodContent = implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));

                    // Parse the return array in toArray
                    $fields = $this->parseResourceToArrayMethod($methodContent);
                }
            }
        } catch (\Throwable $e) {
            // Silent fail
        }

        return $fields;
    }

    /**
     * Parse the toArray method of an API Resource to extract fields
     */
    private function parseResourceToArrayMethod(string $methodContent): array
    {
        $fields = [];

        // Match return [...] statement
        if (preg_match('/return\s*\[([^\]]+(?:\[[^\]]*\][^\]]*)*)\]\s*;/s', $methodContent, $match)) {
            $arrayContent = $match[1];

            // Parse 'key' => $this->value or 'key' => $this->attribute patterns
            if (preg_match_all("/['\"](\w+)['\"]\s*=>\s*\\\$this->(\w+)/", $arrayContent, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $key = $m[1];
                    $attribute = $m[2];
                    $fields[$key] = $this->inferFieldTypeFromName($attribute);
                }
            }

            // Parse 'key' => value patterns (literal values)
            if (preg_match_all("/['\"](\w+)['\"]\s*=>\s*['\"]([^'\"]+)['\"]/", $arrayContent, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $key = $m[1];
                    $value = $m[2];
                    if (! isset($fields[$key])) {
                        $fields[$key] = $value;
                    }
                }
            }

            // Parse whenLoaded('relation') - conditional relations
            if (preg_match_all("/['\"](\w+)['\"]\s*=>\s*\\\$this->whenLoaded\s*\(\s*['\"](\w+)['\"]/", $arrayContent, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $key = $m[1];
                    $relation = $m[2];
                    $fields[$key] = ['_whenLoaded' => $relation, '_type' => 'relation'];
                }
            }

            // Parse when() conditional fields
            if (preg_match_all("/['\"](\w+)['\"]\s*=>\s*\\\$this->when\s*\(/", $arrayContent, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $key = $m[1];
                    if (! isset($fields[$key])) {
                        $fields[$key] = ['_conditional' => true, '_type' => 'mixed'];
                    }
                }
            }

            // Parse new RelatedResource patterns
            if (preg_match_all("/['\"](\w+)['\"]\s*=>\s*new\s+([A-Z]\w+)Resource/", $arrayContent, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $key = $m[1];
                    $relatedResource = $m[2];
                    $fields[$key] = ['_resource' => $relatedResource.'Resource', '_type' => 'object'];
                }
            }

            // Parse RelatedResource::collection patterns
            if (preg_match_all("/['\"](\w+)['\"]\s*=>\s*([A-Z]\w+)Resource::collection/", $arrayContent, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $key = $m[1];
                    $relatedResource = $m[2];
                    $fields[$key] = ['_resource_collection' => $relatedResource.'Resource', '_type' => 'array'];
                }
            }
        }

        return $fields;
    }

    /**
     * Extract aggregate fields (withCount, withSum, etc.) from method content
     */
    private function extractAggregateFields(): void
    {
        $aggregates = [];

        // Pattern: withCount('relation') or withCount(['relation1', 'relation2'])
        if (preg_match_all("/withCount\s*\(\s*['\"](\w+)['\"]\s*\)/", $this->methodContent, $matches)) {
            foreach ($matches[1] as $relation) {
                $aggregates[$relation.'_count'] = 'integer';
            }
        }
        if (preg_match_all("/withCount\s*\(\s*\[([^\]]+)\]/", $this->methodContent, $matches)) {
            foreach ($matches[1] as $arrayContent) {
                if (preg_match_all("/['\"](\w+)['\"]/", $arrayContent, $relationMatches)) {
                    foreach ($relationMatches[1] as $relation) {
                        $aggregates[$relation.'_count'] = 'integer';
                    }
                }
            }
        }

        // Pattern: withSum('relation', 'column')
        if (preg_match_all("/withSum\s*\(\s*['\"](\w+)['\"]\s*,\s*['\"](\w+)['\"]\s*\)/", $this->methodContent, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $relation = $m[1];
                $column = $m[2];
                $aggregates[$relation.'_sum_'.$column] = 'number';
            }
        }

        // Pattern: withAvg('relation', 'column')
        if (preg_match_all("/withAvg\s*\(\s*['\"](\w+)['\"]\s*,\s*['\"](\w+)['\"]\s*\)/", $this->methodContent, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $relation = $m[1];
                $column = $m[2];
                $aggregates[$relation.'_avg_'.$column] = 'number';
            }
        }

        // Pattern: withMin('relation', 'column')
        if (preg_match_all("/withMin\s*\(\s*['\"](\w+)['\"]\s*,\s*['\"](\w+)['\"]\s*\)/", $this->methodContent, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $relation = $m[1];
                $column = $m[2];
                $aggregates[$relation.'_min_'.$column] = 'mixed';
            }
        }

        // Pattern: withMax('relation', 'column')
        if (preg_match_all("/withMax\s*\(\s*['\"](\w+)['\"]\s*,\s*['\"](\w+)['\"]\s*\)/", $this->methodContent, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $relation = $m[1];
                $column = $m[2];
                $aggregates[$relation.'_max_'.$column] = 'mixed';
            }
        }

        // Pattern: withExists('relation')
        if (preg_match_all("/withExists\s*\(\s*['\"](\w+)['\"]\s*\)/", $this->methodContent, $matches)) {
            foreach ($matches[1] as $relation) {
                $aggregates[$relation.'_exists'] = 'boolean';
            }
        }

        // Store aggregates in metadata for later use in response generation
        if (! empty($aggregates)) {
            $this->metadata['_aggregates'] = $aggregates;
        }
    }

    /**
     * Infer field type from attribute name
     */
    private function inferFieldTypeFromName(string $name): mixed
    {
        $nameLower = strtolower($name);

        // ID fields
        if ($name === 'id' || str_ends_with($nameLower, '_id')) {
            return $this->faker->numberBetween(1, 1000);
        }

        // Date/time fields
        if (preg_match('/(_at|_date|date_|created|updated|deleted)/', $nameLower)) {
            return $this->faker->dateTimeThisYear()->format('Y-m-d H:i:s');
        }

        // Email fields
        if (str_contains($nameLower, 'email')) {
            return $this->faker->email();
        }

        // Name fields
        if (preg_match('/(name|nome|title|titolo)/', $nameLower)) {
            return $this->faker->words(2, true);
        }

        // Boolean fields
        if (preg_match('/(is_|has_|can_|active|enabled|visible|published)/', $nameLower)) {
            return $this->faker->boolean();
        }

        // Numeric fields
        if (preg_match('/(amount|price|total|count|quantity|number|num_)/', $nameLower)) {
            return $this->faker->numberBetween(1, 100);
        }

        // Default to the attribute name as placeholder
        return "example_$name";
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
        // Remove $ prefix if present
        $varName = ltrim($varName, '$');

        // Handle ->fresh() or ->refresh() calls - reference to model
        if (preg_match('/(\w+)->(?:fresh|refresh)\(\)/', $varName, $match)) {
            $modelVar = $match[1];

            return $this->getModelStructure($modelVar);
        }

        // Handle direct model variable with ->fresh() in the value
        if (str_contains($varName, '->fresh()') || str_contains($varName, '->refresh()')) {
            $modelVar = preg_replace('/->(?:fresh|refresh)\(\).*/', '', $varName);

            return $this->getModelStructure($modelVar);
        }

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

        // Check if it's a known model variable
        $modelStructure = $this->getModelStructure($varName);
        if (is_array($modelStructure) && ! empty($modelStructure)) {
            return $modelStructure;
        }

        return 'value';
    }

    /**
     * Get model structure from variable name
     */
    private function getModelStructure(string $varName): mixed
    {
        // Try to find model assignment: $invoice = Invoice::...
        $varNameClean = preg_replace('/->.*/', '', $varName);

        // Pattern 1: Variable assigned from Model::query
        if (preg_match('/\$'.preg_quote($varNameClean, '/').'\\s*=\\s*([A-Z][a-zA-Z0-9_]*)::/', $this->methodContent, $match)) {
            $modelClass = $match[1];

            // Try to get actual model fields
            $modelFields = $this->extractModelFields($modelClass);
            if (! empty($modelFields)) {
                // Check if there's a ->load() call on this variable to include relations
                $this->detectLoadedRelationsForVariable($varNameClean);

                return [
                    '_type' => 'model',
                    '_model' => $modelClass,
                    '_fields' => $modelFields,
                ];
            }

            // Fallback to placeholder structure
            return [
                'id' => 1,
                'type' => $modelClass,
                '_note' => "Full {$modelClass} resource",
            ];
        }

        // Pattern 2: Variable is a method parameter with type hint (e.g., Team $team)
        $params = $this->reflectionMethod->getParameters();
        foreach ($params as $param) {
            if ($param->getName() === $varNameClean) {
                $paramType = $param->getType();
                if ($paramType && ! $paramType->isBuiltin()) {
                    $className = $paramType->getName();
                    // Extract just the class name without namespace
                    $shortName = class_basename($className);

                    // Check if it's a Model
                    if (class_exists($className) && is_subclass_of($className, 'Illuminate\Database\Eloquent\Model')) {
                        $modelFields = $this->extractModelFields($shortName);
                        if (! empty($modelFields)) {
                            // Check if there's a ->load() call on this variable
                            $this->detectLoadedRelationsForVariable($varNameClean);

                            return [
                                '_type' => 'model',
                                '_model' => $shortName,
                                '_fields' => $modelFields,
                            ];
                        }
                    }
                }
            }
        }

        return 'value';
    }

    /**
     * Detect relations loaded on a specific variable via ->load()
     */
    private function detectLoadedRelationsForVariable(string $varName): void
    {
        // Pattern: $varName->load('relation1', 'relation2') or $varName->load(['relation1', 'relation2'])
        if (preg_match('/\$'.preg_quote($varName, '/').'\s*->\s*load\s*\(\s*([^)]+)\s*\)/', $this->methodContent, $match)) {
            $loadContent = $match[1];

            // Extract relation names from the load call
            if (preg_match_all("/['\"](\w+)['\"]/", $loadContent, $relationMatches)) {
                foreach ($relationMatches[1] as $relation) {
                    // Default to 'loaded' which is treated as a many-relation in addRelationsToResponse
                    $this->metadata['model_relations'][$relation] = 'loaded';
                }
            }
        }
    }

    /**
     * Extract fields from an Eloquent model class
     */
    private function extractModelFields(string $modelClass): array
    {
        $fields = [];

        // Try common model namespaces
        $namespaces = [
            'App\\Models\\',
            'App\\',
        ];

        $fullClassName = null;
        foreach ($namespaces as $namespace) {
            $testClass = $namespace.$modelClass;
            if (class_exists($testClass)) {
                $fullClassName = $testClass;
                break;
            }
        }

        if (! $fullClassName) {
            return $fields;
        }

        try {
            $reflection = new \ReflectionClass($fullClassName);
            $modelInstance = $reflection->newInstanceWithoutConstructor();

            // Get fillable fields
            $fillable = [];
            if ($reflection->hasProperty('fillable')) {
                $fillableProp = $reflection->getProperty('fillable');
                $fillableProp->setAccessible(true);
                $fillable = $fillableProp->getValue($modelInstance) ?? [];
            }

            // Get hidden fields (should not be in API responses)
            $hidden = [];
            if ($reflection->hasProperty('hidden')) {
                $hiddenProp = $reflection->getProperty('hidden');
                $hiddenProp->setAccessible(true);
                $hidden = $hiddenProp->getValue($modelInstance) ?? [];
            }

            // Get casts for type information
            $casts = [];
            if ($reflection->hasProperty('casts')) {
                $castsProp = $reflection->getProperty('casts');
                $castsProp->setAccessible(true);
                $casts = $castsProp->getValue($modelInstance) ?? [];
            }
            // Also check casts() method for Laravel 11+ style
            if ($reflection->hasMethod('casts')) {
                try {
                    $castsMethod = $reflection->getMethod('casts');
                    if ($castsMethod->isPublic() || $castsMethod->isProtected()) {
                        $castsMethod->setAccessible(true);
                        $methodCasts = $castsMethod->invoke($modelInstance);
                        if (is_array($methodCasts)) {
                            $casts = array_merge($casts, $methodCasts);
                        }
                    }
                } catch (\Throwable $e) {
                    // Ignore errors from casts() method
                }
            }

            // Get appends (computed attributes)
            $appends = [];
            if ($reflection->hasProperty('appends')) {
                $appendsProp = $reflection->getProperty('appends');
                $appendsProp->setAccessible(true);
                $appends = $appendsProp->getValue($modelInstance) ?? [];
            }

            // Always include id
            $fields['id'] = 1;

            // Add fillable fields with appropriate fake values (excluding hidden)
            foreach ($fillable as $field) {
                if (in_array($field, $hidden)) {
                    continue; // Skip hidden fields
                }
                $type = $casts[$field] ?? 'string';
                $fields[$field] = $this->generateFakeValueForModelField($field, $type);
            }

            // Add appended attributes (excluding hidden)
            foreach ($appends as $append) {
                if (in_array($append, $hidden)) {
                    continue;
                }
                $fields[$append] = $this->generateFakeValueForModelField($append, 'string');
            }

            // Add timestamps if model uses them
            if (! $reflection->hasProperty('timestamps') || $reflection->getProperty('timestamps')->getValue($modelInstance) !== false) {
                $fields['created_at'] = date('Y-m-d H:i:s');
                $fields['updated_at'] = date('Y-m-d H:i:s');
            }

        } catch (\Throwable $e) {
            // Silent fail, return empty array
        }

        return $fields;
    }

    /**
     * Generate fake value for a model field based on name and type
     */
    private function generateFakeValueForModelField(string $field, string $type): mixed
    {
        $fieldLower = strtolower($field);

        // Handle specific computed/appended fields first
        if ($fieldLower === 'full_invoice_number') {
            return 'P/'.date('Y').'/0001';
        }
        if (preg_match('/reminder_description/', $fieldLower)) {
            return 'Abbonamento dal 01/01/'.date('Y').' al 31/12/'.date('Y');
        }

        // Check vat_rate early (before cast type handling)
        if (preg_match('/^vat_rate$|^aliquota$|^iva$/', $fieldLower)) {
            return $this->faker->randomElement(['4.00', '10.00', '22.00']);
        }

        // Handle cast types
        if (str_contains($type, 'date')) {
            return date('Y-m-d');
        }
        if ($type === 'datetime') {
            return date('Y-m-d H:i:s');
        }
        if ($type === 'float' || $type === 'decimal' || $type === 'double') {
            // Check if it's a price/amount field (but not vat_rate)
            if (preg_match('/price|amount|total|net/', $fieldLower)) {
                return number_format($this->faker->randomFloat(2, 10, 500), 2, '.', '');
            }

            return round($this->faker->randomFloat(2, 0, 100), 2);
        }
        if ($type === 'integer' || $type === 'int') {
            if (preg_match('/_id$|^id_/', $fieldLower)) {
                return $this->faker->numberBetween(1, 100);
            }
            if (preg_match('/year|anno/', $fieldLower)) {
                return (int) date('Y');
            }
            if (preg_match('/number|numero/', $fieldLower)) {
                return $this->faker->numberBetween(1, 9999);
            }

            return $this->faker->numberBetween(1, 100);
        }
        if ($type === 'boolean' || $type === 'bool') {
            return $this->faker->boolean();
        }

        // Handle by field name patterns
        if (preg_match('/_id$|^id_/', $fieldLower)) {
            return $this->faker->numberBetween(1, 100);
        }
        if (preg_match('/^cf$|codice_fiscale/', $fieldLower)) {
            return strtoupper($this->faker->bothify('??????##?##?###?'));
        }
        if (preg_match('/partita_iva|p_iva|piva/', $fieldLower)) {
            return $this->faker->numerify('###########');
        }
        if (preg_match('/email|pec/', $fieldLower)) {
            return $this->faker->email();
        }
        if (preg_match('/nome$|^nome|first_name/', $fieldLower)) {
            return strtoupper($this->faker->firstName());
        }
        if (preg_match('/cognome|last_name|surname/', $fieldLower)) {
            return strtoupper($this->faker->lastName());
        }
        if (preg_match('/indirizzo|address|via/', $fieldLower)) {
            return 'Via '.$this->faker->streetName().' '.$this->faker->buildingNumber();
        }
        if (preg_match('/comune|city|citta/', $fieldLower)) {
            return $this->faker->city();
        }
        if (preg_match('/^cap$|postal|zip/', $fieldLower)) {
            return $this->faker->numerify('#####');
        }
        if (preg_match('/provincia|province/', $fieldLower)) {
            return strtoupper($this->faker->randomLetter().$this->faker->randomLetter());
        }
        if (preg_match('/codice_univoco|sdi/', $fieldLower)) {
            return strtoupper($this->faker->bothify('???????'));
        }
        if (preg_match('/status|stato/', $fieldLower)) {
            return $this->faker->randomElement(['da pagare', 'pagata', 'annullata', 'attiva']);
        }
        if (preg_match('/prefix|prefisso/', $fieldLower)) {
            return $this->faker->randomElement(['P', 'F', 'NC']);
        }
        if (preg_match('/year|anno/', $fieldLower)) {
            return (int) date('Y');
        }
        if (preg_match('/number|numero/', $fieldLower)) {
            return $this->faker->numberBetween(1, 9999);
        }
        if (preg_match('/file_path|path|file/', $fieldLower)) {
            return 'medici/CFUSER01/fatture/P'.date('Y').'000001.pdf';
        }
        if (preg_match('/document_type|tipo_documento/', $fieldLower)) {
            return 'TD01';
        }
        if (preg_match('/reason|motivo|reason/', $fieldLower)) {
            return null;
        }
        if (preg_match('/reference|riferimento/', $fieldLower)) {
            return null;
        }
        if (preg_match('/description|descrizione/', $fieldLower)) {
            return $this->faker->sentence(3);
        }
        // Check vat_rate before price patterns (since vat_rate contains 'rate')
        if (preg_match('/^vat_rate$|^aliquota$|^iva$/', $fieldLower)) {
            return $this->faker->randomElement(['4.00', '10.00', '22.00']);
        }
        if (preg_match('/price|prezzo|amount|importo|total|totale|net_price/', $fieldLower)) {
            return number_format($this->faker->randomFloat(2, 10, 500), 2, '.', '');
        }
        if (preg_match('/date|data/', $fieldLower)) {
            return date('Y-m-d');
        }

        // Boolean-like field names (is_*, has_*, *_enabled, *_active, etc.)
        if (preg_match('/^is_|^has_|^can_|_enabled$|_active$|_verified$|_confirmed$/', $fieldLower)) {
            return $this->faker->boolean();
        }

        // Integer-like field names (week, index, type, count, level, order, position, etc.)
        if (preg_match('/^week$|^index$|_type$|_count$|^level$|^order$|^position$|^priority$|^sequence$|^sort$|^rank$/', $fieldLower)) {
            return $this->faker->numberBetween(1, 10);
        }

        // State field - typically integer
        if (preg_match('/^state$|^status$/', $fieldLower)) {
            return $this->faker->numberBetween(0, 5);
        }

        // Default string
        return $this->faker->word();
    }

    /**
     * Try to trace what a variable contains
     */
    private function traceVariableContent(string $varName): ?array
    {
        $varNameClean = ltrim($varName, '$');
        $varNameEscaped = preg_quote($varNameClean, '/');

        // FIRST: Check if variable is a method parameter with a Model type hint
        // This handles cases like: public function attachUsers(Request $request, Team $team)
        $params = $this->reflectionMethod->getParameters();
        foreach ($params as $param) {
            if ($param->getName() === $varNameClean) {
                $paramType = $param->getType();
                if ($paramType && ! $paramType->isBuiltin()) {
                    $className = $paramType->getName();
                    // Check if it's a Model
                    if (class_exists($className) && is_subclass_of($className, 'Illuminate\Database\Eloquent\Model')) {
                        $shortName = class_basename($className);
                        $modelFields = $this->extractModelFields($shortName);
                        if (! empty($modelFields)) {
                            // Detect loaded relations
                            $this->detectLoadedRelationsForVariable($varNameClean);

                            return [
                                '_type' => 'model',
                                '_model' => $shortName,
                                '_fields' => $modelFields,
                            ];
                        }
                    }
                }
            }
        }

        // Check if variable is assigned from response()->json()
        if (preg_match('/\$'.$varNameEscaped.'\s*=\s*(\[[\s\S]*?\])\s*;/s', $this->methodContent, $match)) {
            return $this->parsePhpArrayToJson($match[1]);
        }

        // Check if variable is a transformation of another variable (collect($sourceVar)->groupBy->map)
        if (preg_match('/\$'.$varNameEscaped.'\s*=\s*collect\s*\(\s*\$(\w+)\s*\)\s*->\s*groupBy/s', $this->methodContent, $match)) {
            $sourceVar = $match[1];

            // Get SQL fields from the source variable
            $sqlFields = $this->extractSqlSelectFields($sourceVar);

            // Get the transformation details
            $transformation = $this->detectCollectionTransformationForVar($varName, $sourceVar);

            $result = [
                '_type' => 'query_result',
                '_variable' => $varNameClean,
                '_source_variable' => $sourceVar,
                '_paginated' => false,
            ];

            if (! empty($sqlFields)) {
                $result['_sql_fields'] = $sqlFields;
            }

            if ($transformation) {
                $result['_transformation'] = $transformation;
            }

            return $result;
        }

        // Check for Model::singleModelMethods() pattern (find, findOrFail, first, create, etc.)
        // Supports both retrieval and creation methods that return a single model instance
        // Note: We don't try to match the closing parenthesis because create/firstOrCreate may have nested arrays
        $singleModelMethods = 'findOrFail|find|findOr|findOrNew|first|firstOrFail|firstWhere|firstOrCreate|firstOrNew|sole|create|updateOrCreate|forceCreate';
        if (preg_match('/\$'.$varNameEscaped.'\s*=\s*([A-Z][a-zA-Z0-9_]*)::(?:.*?)(?:'.$singleModelMethods.')\s*\(/s', $this->methodContent, $match)) {
            $modelClass = $match[1];
            $modelFields = $this->extractModelFields($modelClass);
            if (! empty($modelFields)) {
                return [
                    '_type' => 'model',
                    '_model' => $modelClass,
                    '_fields' => $modelFields,
                ];
            }
        }

        // Check for Model::paginate() / simplePaginate() / cursorPaginate() pattern
        $paginationMethods = 'paginate|simplePaginate|cursorPaginate';
        if (preg_match('/\$'.$varNameEscaped.'\s*=\s*([A-Z][a-zA-Z0-9_]*)::(?:[^;]*?)(?:'.$paginationMethods.')\s*\([^)]*\)\s*;/s', $this->methodContent, $match)) {
            $modelClass = $match[1];
            $modelFields = $this->extractModelFields($modelClass);
            if (! empty($modelFields)) {
                return [
                    '_type' => 'paginated',
                    '_model' => $modelClass,
                    '_fields' => $modelFields,
                    '_paginated' => true,
                ];
            }
        }

        // Check for Model::pluck() pattern
        if (preg_match('/\$'.$varNameEscaped.'\s*=\s*([A-Z][a-zA-Z0-9_]*)::(?:[^;]*?)pluck\s*\(\s*[\'"](\w+)[\'"]/s', $this->methodContent, $match)) {
            $modelClass = $match[1];
            $columnName = $match[2];

            return [
                '_type' => 'pluck',
                '_model' => $modelClass,
                '_column' => $columnName,
            ];
        }

        // Check for Model::value() pattern
        if (preg_match('/\$'.$varNameEscaped.'\s*=\s*([A-Z][a-zA-Z0-9_]*)::(?:[^;]*?)value\s*\(\s*[\'"](\w+)[\'"]/s', $this->methodContent, $match)) {
            $modelClass = $match[1];
            $columnName = $match[2];

            return [
                '_type' => 'value',
                '_model' => $modelClass,
                '_column' => $columnName,
            ];
        }

        // Check for scalar methods: count, exists, doesntExist, max, min, avg, sum
        $scalarMethods = 'count|exists|doesntExist|max|min|avg|sum|average';
        if (preg_match('/\$'.$varNameEscaped.'\s*=\s*([A-Z][a-zA-Z0-9_]*)::(?:[^;]*?)('.$scalarMethods.')\s*\([^)]*\)\s*;/s', $this->methodContent, $match)) {
            $method = $match[2];
            if (in_array($method, ['exists', 'doesntExist'])) {
                return [
                    '_type' => 'scalar',
                    '_scalar_type' => 'boolean',
                ];
            }

            return [
                '_type' => 'scalar',
                '_scalar_type' => 'integer',
            ];
        }

        // Check if it's a collection/query result (Model::get(), Model::all(), etc.)
        // Supports chained methods like Model::where(...)->orderBy(...)->get()
        $collectionMethods = 'get|all|findMany';
        if (preg_match('/\$'.$varNameEscaped.'\s*=\s*([A-Z][a-zA-Z0-9_]*)::(?:[^;]*?)(?:'.$collectionMethods.')\s*\(/s', $this->methodContent, $match)) {
            $modelClass = $match[1];
            $modelFields = $this->extractModelFields($modelClass);
            if (! empty($modelFields)) {
                return [
                    '_type' => 'collection',
                    '_model' => $modelClass,
                    '_fields' => $modelFields,
                    '_paginated' => false,
                ];
            }
        }

        // Check for auth()->user() pattern - returns authenticated User model
        // MUST be checked BEFORE the legacy ->get()/->select() pattern which is too greedy
        if (preg_match('/\$'.$varNameEscaped.'\s*=\s*auth\s*\(\s*\)\s*->\s*user\s*\(\s*\)/s', $this->methodContent)) {
            $modelFields = $this->extractModelFields('User');
            if (! empty($modelFields)) {
                // Check if the variable is loaded with relations: $user->load([...])
                $eagerRelations = [];
                if (preg_match('/\$'.$varNameEscaped.'\s*->\s*load\s*\(\s*\[([^\]]+)\]/s', $this->methodContent, $loadMatch)) {
                    $eagerRelations = $this->parseEagerLoadRelations($loadMatch[1], 'User');
                }

                return [
                    '_type' => 'model',
                    '_model' => 'User',
                    '_fields' => $modelFields,
                    '_eager_relations' => $eagerRelations,
                ];
            }
        }

        // Check for $varData = $sourceVar->toArray() pattern - traces back to source model
        // MUST be checked BEFORE the legacy ->get()/->select() pattern which is too greedy
        if (preg_match('/\$'.$varNameEscaped.'\s*=\s*\$(\w+)\s*->\s*toArray\s*\(\s*\)/s', $this->methodContent, $match)) {
            $sourceVar = $match[1];
            // Recursively trace the source variable to find the model
            $sourceContent = $this->traceVariableContent('$'.$sourceVar);
            if ($sourceContent && isset($sourceContent['_type']) && $sourceContent['_type'] === 'model') {
                // Return the same model info but indicate it's been converted to array
                return $sourceContent;
            }
        }

        // Check if it's a collection/query result (legacy patterns: ->get(), DB::select, etc.)
        if (preg_match('/\$'.$varNameEscaped.'\s*=\s*.*?(?:->get\(|->select\(|DB::select|DB::connection\([^)]+\)\s*->\s*select)/s', $this->methodContent)) {
            $result = [
                '_type' => 'query_result',
                '_variable' => $varNameClean,
                '_paginated' => $this->usesPagination(),
            ];

            // Try to extract SQL fields from DB::select queries
            $sqlFields = $this->extractSqlSelectFields($varNameClean);
            if (! empty($sqlFields)) {
                $result['_sql_fields'] = $sqlFields;
            }

            // Check if the result is transformed with groupBy/map (produces nested structure)
            $transformation = $this->detectCollectionTransformation($varNameClean);
            if ($transformation) {
                $result['_transformation'] = $transformation;
            }

            return $result;
        }

        // Check for simple map transformation: $var = $source->map(function($item) { return [...]; })
        // This handles patterns like: $data = $teams->map(function ($team) { return ['id' => $team->id, ...]; });
        if (preg_match('/\$'.$varNameEscaped.'\s*=\s*\$(\w+)\s*->\s*map\s*\(\s*function\s*\(\s*\$\w+\s*\)\s*\{/s', $this->methodContent, $match)) {
            $sourceVar = $match[1];
            $mapFields = $this->extractMapReturnFields($varNameClean);

            if (! empty($mapFields)) {
                return [
                    '_type' => 'mapped_collection',
                    '_source_variable' => $sourceVar,
                    '_fields' => $mapFields,
                ];
            }
        }

        return null;
    }

    /**
     * Extract fields from a map function's return array.
     * Handles: $var = $source->map(function ($item) { return ['field' => $item->field, ...]; });
     */
    private function extractMapReturnFields(string $varName): array
    {
        $varName = preg_quote(ltrim($varName, '$'), '/');

        // Find the map function and extract the return array
        $pattern = '/\$'.$varName.'\s*=\s*\$\w+\s*->\s*map\s*\(\s*function\s*\(\s*\$(\w+)\s*\)\s*\{\s*return\s*\[/s';

        if (preg_match($pattern, $this->methodContent, $match, PREG_OFFSET_CAPTURE)) {
            $itemVar = $match[1][0];
            $startOffset = $match[0][1] + strlen($match[0][0]);

            // Extract the balanced array content
            $arrayContent = $this->extractBalancedBrackets($this->methodContent, $startOffset - 1);

            if ($arrayContent) {
                return $this->parseMapArrayFields($arrayContent, $itemVar);
            }
        }

        return [];
    }

    /**
     * Parse the fields from a map return array.
     * Input: ['id' => $team->id, 'name' => $team->name, 'total_users' => $team->users_count]
     * Output: ['id' => 'integer', 'name' => 'string', 'total_users' => 'integer']
     */
    private function parseMapArrayFields(string $arrayContent, string $itemVar): array
    {
        $fields = [];

        // Remove outer brackets
        $arrayContent = trim($arrayContent);
        if (str_starts_with($arrayContent, '[')) {
            $arrayContent = substr($arrayContent, 1);
        }
        if (str_ends_with($arrayContent, ']')) {
            $arrayContent = substr($arrayContent, 0, -1);
        }

        // Match patterns like: 'key' => $var->property or 'key' => $var->method()
        // Also match: 'key' => value (literal values)
        $itemVarPattern = preg_quote($itemVar, '/');
        $pattern = "/['\"](\w+)['\"]\s*=>\s*(?:\\\${$itemVarPattern}->(\w+)(?:_count)?|\\\${$itemVarPattern}->(\w+)\(\)|([^,\]]+))/";

        if (preg_match_all($pattern, $arrayContent, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $fieldName = $match[1];
                $property = $match[2] ?? $match[3] ?? null;
                $literalValue = $match[4] ?? null;

                // Infer type from field name or property
                $type = $this->inferFieldType($fieldName, $property, $literalValue);
                $fields[$fieldName] = $type;
            }
        }

        return $fields;
    }

    /**
     * Infer field type from field name, property name, or literal value
     */
    private function inferFieldType(string $fieldName, ?string $property, ?string $literalValue): string
    {
        $fieldLower = strtolower($fieldName);

        // Check for common patterns
        if (preg_match('/^id$|_id$/', $fieldLower)) {
            return 'integer';
        }
        if (preg_match('/total_|_count$|count_|num_|number_/', $fieldLower)) {
            return 'integer';
        }
        if (preg_match('/^is_|^has_|^can_|_flag$|active|enabled|visible/', $fieldLower)) {
            return 'boolean';
        }
        if (preg_match('/email/', $fieldLower)) {
            return 'string';
        }
        if (preg_match('/date|_at$|time|created|updated|deleted/', $fieldLower)) {
            return 'datetime';
        }
        if (preg_match('/price|amount|total|sum|cost|fee|balance/', $fieldLower)) {
            return 'number';
        }

        // Default to string
        return 'string';
    }

    /**
     * Detect collection transformation for a specific target variable from a source
     */
    private function detectCollectionTransformationForVar(string $targetVar, string $sourceVar): ?array
    {
        // Pattern: $targetVar = collect($sourceVar)->groupBy('field')->map(function ($items, $key) { return [...]; })->values()
        // Use a simpler pattern to find the map function start, then extract balanced brackets
        $startPattern = '/\$'.preg_quote($targetVar, '/').'\s*=\s*collect\s*\(\s*\$'.preg_quote($sourceVar, '/').'\s*\)\s*->\s*groupBy\s*\(\s*[\'"](\w+)[\'"]\s*\)\s*->\s*map\s*\(\s*function\s*\(\s*\$(\w+)\s*,\s*\$(\w+)\s*\)\s*\{\s*return\s*\[/s';

        if (preg_match($startPattern, $this->methodContent, $match, PREG_OFFSET_CAPTURE)) {
            $groupByField = $match[1][0];
            $itemsVar = $match[2][0];
            $keyVar = $match[3][0];
            $startOffset = $match[0][1] + strlen($match[0][0]);

            // Extract the balanced array content
            $returnStructure = $this->extractBalancedBrackets($this->methodContent, $startOffset - 1);

            if ($returnStructure) {
                // Remove the outer brackets
                $returnStructure = trim($returnStructure);
                if (str_starts_with($returnStructure, '[') && str_ends_with($returnStructure, ']')) {
                    $returnStructure = substr($returnStructure, 1, -1);
                }

                // Parse the return structure
                $structure = $this->parseMapReturnStructure($returnStructure, $sourceVar);

                return [
                    'type' => 'groupBy_map',
                    'groupBy' => $groupByField,
                    'structure' => $structure,
                ];
            }
        }

        return null;
    }

    /**
     * Extract content within balanced brackets starting from a position
     */
    private function extractBalancedBrackets(string $content, int $startPos): ?string
    {
        if ($content[$startPos] !== '[') {
            return null;
        }

        $depth = 0;
        $length = strlen($content);
        $start = $startPos;

        for ($i = $startPos; $i < $length; $i++) {
            $char = $content[$i];

            if ($char === '[') {
                $depth++;
            } elseif ($char === ']') {
                $depth--;
                if ($depth === 0) {
                    return substr($content, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    /**
     * Detect collection transformations like groupBy()->map()
     */
    private function detectCollectionTransformation(string $varName): ?array
    {
        // Look for pattern: $transformed = collect($varName)->groupBy('field')->map(...)
        // or: return response()->json($grouped) where $grouped = collect($varName)->groupBy(...)

        // Find if there's a transformation of this variable
        $pattern = '/\$(\w+)\s*=\s*collect\s*\(\s*\$'.preg_quote($varName, '/').'\s*\)\s*->\s*groupBy\s*\(\s*[\'"](\w+)[\'"]\s*\)\s*->\s*map\s*\(\s*function\s*\(\s*\$\w+\s*,\s*\$\w+\s*\)\s*\{\s*return\s*\[([\s\S]*?)\]\s*;\s*\}\s*\)/s';

        if (preg_match($pattern, $this->methodContent, $match)) {
            $transformedVar = $match[1];
            $groupByField = $match[2];
            $returnStructure = $match[3];

            // Parse the return structure to understand the final JSON shape
            $structure = $this->parseMapReturnStructure($returnStructure, $varName);

            return [
                'type' => 'groupBy_map',
                'groupBy' => $groupByField,
                'transformed_var' => $transformedVar,
                'structure' => $structure,
            ];
        }

        return null;
    }

    /**
     * Parse the return structure from a map() callback
     */
    private function parseMapReturnStructure(string $structure, string $originalVar): array
    {
        $result = [];

        // First, look for nested map patterns: 'key' => $var->map(function...)->values()
        // Allow for optional ->values() after the closure
        $nestedPattern = '/[\'"](\w+)[\'"]\s*=>\s*\$(\w+)->\s*map\s*\(\s*function\s*\(\s*\$(\w+)\s*\)\s*\{\s*return\s*\[([\s\S]*?)\]\s*;\s*\}\s*\)(?:->\s*values\s*\(\s*\))?/s';
        if (preg_match_all($nestedPattern, $structure, $nestedMatches, PREG_SET_ORDER)) {
            foreach ($nestedMatches as $nestedMatch) {
                $key = $nestedMatch[1];
                $itemVar = $nestedMatch[3];
                $nestedStructure = $nestedMatch[4];
                $result[$key] = $this->parseNestedMapFields($nestedStructure, $itemVar);
            }
        }

        // Then look for simple key-value pairs (avoiding the nested ones we already parsed)
        if (preg_match_all('/[\'"](\w+)[\'"]\s*=>\s*(\$\w+)(?:,|\s*$)/s', $structure, $simpleMatches, PREG_SET_ORDER)) {
            foreach ($simpleMatches as $match) {
                $key = $match[1];
                if (! isset($result[$key])) {
                    $result[$key] = $this->inferValueType($match[2]);
                }
            }
        }

        return $result;
    }

    /**
     * Parse nested map fields
     */
    private function parseNestedMapFields(string $structure, string $itemVar): array
    {
        $fields = [];

        // Match 'field' => $item->field patterns
        if (preg_match_all('/[\'"](\w+)[\'"]\s*=>\s*\$'.preg_quote($itemVar, '/').'->(\w+)/s', $structure, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $outputField = $match[1];
                $sourceField = $match[2];
                $fields[$outputField] = $sourceField;
            }
        }

        return $fields;
    }

    /**
     * Infer value type from PHP expression
     */
    private function inferValueType(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^\$\w+$/', $value)) {
            return 'variable';
        }
        if (preg_match('/^\(int\)|->values\(\)/', $value)) {
            return 'array';
        }

        return 'mixed';
    }

    /**
     * Extract field names from SQL SELECT statement
     */
    private function extractSqlSelectFields(string $varName): array
    {
        $fields = [];

        // Match DB::select with the SQL query - handle both single and double quotes, and heredoc
        if (preg_match('/\$'.preg_quote($varName, '/').'\s*=\s*DB::select\s*\(\s*["\']?\s*SELECT\s+([\s\S]*?)\s+FROM\s/si', $this->methodContent, $match)) {
            $selectPart = $match[1];
            $fields = $this->parseSelectFields($selectPart);
        }

        // Match DB::connection('...')->select() pattern
        if (empty($fields) && preg_match('/\$'.preg_quote($varName, '/').'\s*=\s*DB::connection\s*\([^)]+\)\s*->\s*select\s*\(\s*["\']?\s*SELECT\s+([\s\S]*?)\s+FROM\s/si', $this->methodContent, $match)) {
            $selectPart = $match[1];
            $fields = $this->parseSelectFields($selectPart);
        }

        // Match CTE (WITH ... AS ... SELECT) pattern - DB::connection()->select()
        if (empty($fields) && preg_match('/\$'.preg_quote($varName, '/').'\s*=\s*DB::connection\s*\([^)]+\)\s*->\s*select\s*\(\s*["\']?\s*WITH\s+[\s\S]*?\)\s*SELECT\s+([\s\S]*?)\s+FROM\s/si', $this->methodContent, $match)) {
            $selectPart = $match[1];
            $fields = $this->parseSelectFields($selectPart);
        }

        // Match CTE (WITH ... AS ... SELECT) pattern - DB::select()
        if (empty($fields) && preg_match('/\$'.preg_quote($varName, '/').'\s*=\s*DB::select\s*\(\s*["\']?\s*WITH\s+[\s\S]*?\)\s*SELECT\s+([\s\S]*?)\s+FROM\s/si', $this->methodContent, $match)) {
            $selectPart = $match[1];
            $fields = $this->parseSelectFields($selectPart);
        }

        // Also try to match ->select() method chains
        if (empty($fields) && preg_match('/\$'.preg_quote($varName, '/').'\s*=\s*.*?->select\s*\(\s*["\']([^"\']+)/s', $this->methodContent, $match)) {
            $selectPart = $match[1];
            $fields = $this->parseSelectFields($selectPart);
        }

        return $fields;
    }

    /**
     * Parse SELECT fields from SQL query
     */
    private function parseSelectFields(string $selectPart): array
    {
        $fields = [];

        // Remove newlines and normalize whitespace
        $selectPart = preg_replace('/\s+/', ' ', $selectPart);

        // Split by comma, but be careful with functions that contain commas
        $parts = $this->splitSelectFields($selectPart);

        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) {
                continue;
            }

            // Extract alias (AS alias or just the field name)
            $fieldName = null;
            $fieldType = 'string';

            // Pattern: ... AS "alias with special chars" (quoted alias with special characters)
            if (preg_match('/\s+AS\s+"([^"]+)"\s*$/i', $part, $aliasMatch)) {
                $fieldName = $aliasMatch[1];
            }
            // Pattern: ... AS alias_name (case insensitive, simple identifier)
            elseif (preg_match('/\s+AS\s+["\']?(\w+)["\']?\s*$/i', $part, $aliasMatch)) {
                $fieldName = $aliasMatch[1];
            }
            // Pattern: table.column or just column (without AS)
            elseif (preg_match('/(?:[\w.]+\.)?(\w+)\s*$/', $part, $colMatch)) {
                $fieldName = $colMatch[1];
            }

            if ($fieldName) {
                // Infer type from field name
                $fieldType = $this->inferTypeFromFieldName($fieldName);
                $fields[$fieldName] = $fieldType;
            }
        }

        return $fields;
    }

    /**
     * Split SELECT fields handling nested parentheses
     */
    private function splitSelectFields(string $selectPart): array
    {
        $fields = [];
        $current = '';
        $depth = 0;

        for ($i = 0; $i < strlen($selectPart); $i++) {
            $char = $selectPart[$i];

            if ($char === '(') {
                $depth++;
                $current .= $char;
            } elseif ($char === ')') {
                $depth--;
                $current .= $char;
            } elseif ($char === ',' && $depth === 0) {
                $fields[] = trim($current);
                $current = '';
            } else {
                $current .= $char;
            }
        }

        if (! empty(trim($current))) {
            $fields[] = trim($current);
        }

        return $fields;
    }

    /**
     * Infer type from field name using common naming conventions (EN/IT)
     */
    private function inferTypeFromFieldName(string $fieldName): string
    {
        $fieldLower = strtolower($fieldName);

        // Fields starting with % (percentage fields)
        if (str_starts_with($fieldName, '%') || str_starts_with($fieldName, '% ')) {
            return 'number';
        }

        // ID fields (id, user_id, id_user, id_utente, codice)
        if (preg_match('/^id$|_id$|^id_|^codice$/', $fieldLower)) {
            return 'integer';
        }

        // Patient count fields
        if (preg_match('/^numero_pazienti$|^n_pazienti$|^pazienti_count$|^num_pazienti$/', $fieldLower)) {
            return 'integer';
        }

        // Date/time fields (EN: _at, date, time / IT: data, ora, giorno)
        if (preg_match('/_at$|_date$|^date_|_time$|^time_|timestamp|datetime|created|updated|deleted|^data_|_data$|^data$|^ora_|_ora$|giorno|mese|anno|scadenza|nascita|rilevazione|registrazione/', $fieldLower)) {
            return 'datetime';
        }

        // Boolean fields (EN: is_, has_, can_ / IT: è_, ha_, può_, attivo, abilitato, visibile)
        if (preg_match('/^is_|^has_|^can_|^was_|^will_|^should_|_flag$|active$|enabled$|visible$|published$|verified$|confirmed$|^attivo|^abilitato|^visibile|^pubblicato|^verificato|^confermato|^eliminato|^archiviato|^bloccato|^sospeso/', $fieldLower)) {
            return 'boolean';
        }

        // Email fields
        if (preg_match('/email|e_mail|posta|mail/', $fieldLower)) {
            return 'email';
        }

        // Phone fields (EN: phone, mobile / IT: telefono, cellulare, fisso)
        if (preg_match('/phone|mobile|cell|tel$|telephone|fax|telefono|cellulare|fisso/', $fieldLower)) {
            return 'phone';
        }

        // Numeric/currency fields (EN + IT: prezzo, importo, totale, quantità, valore, percentuale, etc.)
        if (preg_match('/price|amount|total|sum|cost|fee|balance|rate|score|percent|ratio|count|qty|quantity|number|num_|_num$|age|year|month|day|hour|minute|second|weight|height|size|length|width|depth|latitude|longitude|lat$|lng$|lon$|prezzo|importo|totale|costo|saldo|tariffa|punteggio|percentuale|rapporto|conteggio|quantita|numero|eta|peso|altezza|dimensione|lunghezza|larghezza|profondita|valore|numeratore|denominatore|minimo|massimo|media|somma/', $fieldLower)) {
            return 'number';
        }

        // URL fields (EN + IT: sito, collegamento)
        if (preg_match('/url|link|href|website|site|sito|collegamento/', $fieldLower)) {
            return 'url';
        }

        return 'string';
    }

    /**
     * Check if the method uses pagination
     */
    private function usesPagination(): bool
    {
        return (bool) preg_match('/->paginate\(|->simplePaginate\(|->cursorPaginate\(/', $this->methodContent);
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

        // Authentication - check route and controller middleware for any auth patterns
        if ($this->requiresAuthentication()) {
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
     * Extract validation rules from $request->validate(), Validator::make(), or FormRequest
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
        // Match Validator::make($request->all(), [...]) pattern
        elseif (preg_match('/Validator::make\s*\(\s*\$\w+(?:->all\(\))?\s*,\s*\[/s', $this->methodContent)) {
            // Find the second array (the rules array) in Validator::make()
            if (preg_match('/Validator::make\s*\(\s*\$\w+(?:->all\(\))?\s*,\s*\[/s', $this->methodContent, $match, PREG_OFFSET_CAPTURE)) {
                $matchPos = $match[0][1];
                $matchText = $match[0][0];
                $start = $matchPos + strlen($matchText) - 1; // Position of the opening bracket
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
        // First, extract middleware from the route definition (Laravel router)
        $this->extractRouteMiddleware();

        // Also check __construct for controller-level middleware
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
                            if (! in_array($middleware, $this->metadata['middleware'])) {
                                $this->metadata['middleware'][] = $middleware;
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Extract middleware from Laravel route definition
     */
    private function extractRouteMiddleware(): void
    {
        try {
            $routes = app('router')->getRoutes();

            foreach ($routes as $route) {
                $action = $route->getAction();

                // Check if the route uses this controller and method
                $expectedAction = $this->controllerClass.'@'.$this->methodName;
                $controllerAction = $action['controller'] ?? ($action['uses'] ?? null);

                if ($controllerAction === $expectedAction) {
                    // Get middleware from route
                    $middleware = $route->gatherMiddleware();

                    foreach ($middleware as $mw) {
                        // Handle middleware that might be a class name or alias
                        if (is_string($mw) && ! in_array($mw, $this->metadata['middleware'])) {
                            $this->metadata['middleware'][] = $mw;
                        }
                    }

                    break; // Found the route, no need to continue
                }
            }
        } catch (\Throwable $e) {
            // Silent fail - route discovery might not work in all contexts
        }
    }

    /**
     * Check if middleware indicates authentication is required
     * Supports standard Laravel auth middleware and custom auth middleware
     */
    private function isAuthMiddleware(string $middleware): bool
    {
        // Standard Laravel auth middleware
        $standardAuth = [
            'auth',
            'auth:sanctum',
            'auth:api',
            'auth:web',
        ];

        if (in_array($middleware, $standardAuth)) {
            return true;
        }

        // Check for auth: prefix with any guard
        if (str_starts_with($middleware, 'auth:')) {
            return true;
        }

        // Check for custom auth middleware patterns (case insensitive)
        // Common patterns: auth.custom, auth.cognito, authenticate, etc.
        $lowercaseMiddleware = strtolower($middleware);

        // Patterns that indicate authentication
        $authPatterns = [
            'auth.',           // auth.cognito, auth.cognitoUsers, etc.
            'authenticate',    // AuthenticateMiddleware
            'verify.token',    // Token verification
            'jwt',             // JWT authentication
            'passport',        // Laravel Passport
        ];

        foreach ($authPatterns as $pattern) {
            if (str_contains($lowercaseMiddleware, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if any middleware in the list requires authentication
     */
    private function requiresAuthentication(): bool
    {
        foreach ($this->metadata['middleware'] as $middleware) {
            if ($this->isAuthMiddleware($middleware)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract query parameters from method code
     * Detects $request->query(), $request->input(), $request->get(), etc.
     */
    private function extractQueryParameters(): void
    {
        // Extract query params for GET-like methods or any method using query params
        $isGetMethod = in_array($this->methodName, ['index', 'show', 'search', 'list', 'filter']) ||
                       str_contains(strtolower($this->methodName), 'search') ||
                       str_contains(strtolower($this->methodName), 'find') ||
                       str_contains(strtolower($this->methodName), 'list') ||
                       str_contains(strtolower($this->methodName), 'filter') ||
                       str_contains(strtolower($this->methodName), 'get');

        // Also check if method uses query() function
        $usesQuery = (bool) preg_match('/\$request->query\s*\(/', $this->methodContent);

        if (! $isGetMethod && ! $usesQuery) {
            return;
        }

        $queryParams = [];

        // Pattern 1: $request->query('param') or $request->query('param', 'default')
        if (preg_match_all("/\\\$request->query\s*\(\s*['\"](\w+)['\"](?:\s*,\s*([^)]+))?\s*\)/", $this->methodContent, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $param = $m[1];
                $default = isset($m[2]) ? trim($m[2], "' \"") : null;
                $queryParams[$param] = [
                    'type' => $this->inferParamTypeFromDefault($default),
                    'required' => false,
                    'default' => $default,
                ];
            }
        }

        // Pattern 2: $request->input('param') or $request->input('param', default)
        if (preg_match_all("/\\\$request->input\s*\(\s*['\"](\w+)['\"](?:\s*,\s*([^)]+))?\s*\)/", $this->methodContent, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $param = $m[1];
                $default = isset($m[2]) ? trim($m[2], "' \"") : null;
                if (! isset($queryParams[$param])) {
                    $queryParams[$param] = [
                        'type' => $this->inferParamTypeFromDefault($default),
                        'required' => false,
                        'default' => $default,
                    ];
                }
            }
        }

        // Pattern 3: $request->get('param')
        if (preg_match_all("/\\\$request->get\s*\(\s*['\"](\w+)['\"]\s*\)/", $this->methodContent, $matches)) {
            foreach ($matches[1] as $param) {
                if (! isset($queryParams[$param])) {
                    $queryParams[$param] = [
                        'type' => 'string',
                        'required' => false,
                    ];
                }
            }
        }

        // Pattern 4: $request->has('param') - often indicates optional param
        if (preg_match_all("/\\\$request->has\s*\(\s*['\"](\w+)['\"]\s*\)/", $this->methodContent, $matches)) {
            foreach ($matches[1] as $param) {
                if (! isset($queryParams[$param])) {
                    $queryParams[$param] = [
                        'type' => 'string',
                        'required' => false,
                    ];
                }
            }
        }

        // Pattern 5: $request->filled('param') - non-empty param
        if (preg_match_all("/\\\$request->filled\s*\(\s*['\"](\w+)['\"]\s*\)/", $this->methodContent, $matches)) {
            foreach ($matches[1] as $param) {
                if (! isset($queryParams[$param])) {
                    $queryParams[$param] = [
                        'type' => 'string',
                        'required' => false,
                    ];
                }
            }
        }

        // Pattern 6: when($request->param) or when($request->has('param'), ...) - conditional queries
        if (preg_match_all("/when\s*\(\s*\\\$request->(\w+)/", $this->methodContent, $matches)) {
            foreach ($matches[1] as $param) {
                if ($param !== 'user' && ! isset($queryParams[$param])) {
                    $queryParams[$param] = [
                        'type' => 'string',
                        'required' => false,
                    ];
                }
            }
        }

        // Pattern 7: Common filter/sort parameters
        if (preg_match('/orderBy\s*\(\s*\$request/', $this->methodContent)) {
            if (! isset($queryParams['sort_by'])) {
                $queryParams['sort_by'] = [
                    'type' => 'string',
                    'required' => false,
                    'description' => 'Field to sort by',
                ];
            }
            if (! isset($queryParams['sort_dir'])) {
                $queryParams['sort_dir'] = [
                    'type' => 'string',
                    'required' => false,
                    'description' => 'Sort direction (asc/desc)',
                ];
            }
        }

        // Pattern 8: Pagination parameters
        if (preg_match('/paginate\s*\(\s*\$request/', $this->methodContent)) {
            if (! isset($queryParams['per_page'])) {
                $queryParams['per_page'] = [
                    'type' => 'integer',
                    'required' => false,
                    'description' => 'Number of items per page',
                ];
            }
        }

        // Pattern 9: Search parameter
        if (preg_match('/where\s*\([^)]*like[^)]*\$request/', $this->methodContent, $m) ||
            preg_match('/search|keyword|q\b/', implode('', array_keys($queryParams)))) {
            if (! isset($queryParams['search']) && ! isset($queryParams['q'])) {
                // Check if there's a search-like variable
                if (preg_match('/\$(?:search|keyword|q)\s*=\s*\$request/', $this->methodContent)) {
                    $queryParams['search'] = [
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Search term',
                    ];
                }
            }
        }

        // Store in metadata
        if (! empty($queryParams)) {
            $this->metadata['query_params'] = $queryParams;
        }
    }

    /**
     * Infer parameter type from default value
     */
    private function inferParamTypeFromDefault(?string $default): string
    {
        if ($default === null) {
            return 'string';
        }

        if (is_numeric($default)) {
            return str_contains($default, '.') ? 'number' : 'integer';
        }

        if (in_array(strtolower($default), ['true', 'false'])) {
            return 'boolean';
        }

        if ($default === 'null') {
            return 'string';
        }

        return 'string';
    }

    /**
     * Extract file upload parameters from method code
     */
    private function extractFileUploads(): void
    {
        $fileParams = [];

        // Pattern 1: $request->file('param')
        if (preg_match_all("/\\\$request->file\s*\(\s*['\"](\w+)['\"]\s*\)/", $this->methodContent, $matches)) {
            foreach ($matches[1] as $param) {
                $fileParams[$param] = [
                    'type' => 'file',
                    'required' => true,
                ];
            }
        }

        // Pattern 2: $request->hasFile('param')
        if (preg_match_all("/\\\$request->hasFile\s*\(\s*['\"](\w+)['\"]\s*\)/", $this->methodContent, $matches)) {
            foreach ($matches[1] as $param) {
                if (! isset($fileParams[$param])) {
                    $fileParams[$param] = [
                        'type' => 'file',
                        'required' => false, // hasFile implies it might not be present
                    ];
                } else {
                    $fileParams[$param]['required'] = false;
                }
            }
        }

        // Pattern 3: Validation rules for files (from FormRequest or inline)
        if (! empty($this->metadata['validations'])) {
            foreach ($this->metadata['validations'] as $field => $rules) {
                if (preg_match('/(file|image|mimes|mimetypes)/', $rules)) {
                    if (! isset($fileParams[$field])) {
                        $fileParams[$field] = [
                            'type' => 'file',
                            'required' => str_contains($rules, 'required'),
                            'mimes' => $this->extractMimesFromRules($rules),
                        ];
                    }
                }
            }
        }

        // Store in metadata
        if (! empty($fileParams)) {
            $this->metadata['file_uploads'] = $fileParams;
        }
    }

    /**
     * Extract mime types from validation rules
     */
    private function extractMimesFromRules(string $rules): ?string
    {
        if (preg_match('/mimes:([^|]+)/', $rules, $m)) {
            return $m[1];
        }
        if (preg_match('/mimetypes:([^|]+)/', $rules, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Extract authorization checks from method code
     */
    private function extractAuthorization(): void
    {
        $authChecks = [];

        // Pattern 1: $this->authorize('action', Model::class)
        if (preg_match_all("/\\\$this->authorize\s*\(\s*['\"](\w+)['\"]/", $this->methodContent, $matches)) {
            foreach ($matches[1] as $action) {
                $authChecks[] = [
                    'type' => 'policy',
                    'action' => $action,
                ];
            }
        }

        // Pattern 2: Gate::allows('action') or Gate::denies('action')
        if (preg_match_all("/Gate::(allows|denies|check)\s*\(\s*['\"]([^'\"]+)['\"]/", $this->methodContent, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $authChecks[] = [
                    'type' => 'gate',
                    'method' => $m[1],
                    'ability' => $m[2],
                ];
            }
        }

        // Pattern 3: $user->can('action') or $user->cannot('action')
        if (preg_match_all("/->can(?:not)?\s*\(\s*['\"]([^'\"]+)['\"]/", $this->methodContent, $matches)) {
            foreach ($matches[1] as $ability) {
                $authChecks[] = [
                    'type' => 'can',
                    'ability' => $ability,
                ];
            }
        }

        // Pattern 4: abort_if(!$user->can(...), 403)
        if (preg_match('/abort(?:_if|_unless)?\s*\([^,]*,\s*403/', $this->methodContent)) {
            $authChecks[] = [
                'type' => 'abort',
                'status' => 403,
            ];
        }

        // If we have auth checks, add 403 error response
        if (! empty($authChecks)) {
            $this->metadata['authorization'] = $authChecks;

            // Auto-add 403 response if not already present
            if (! isset($this->metadata['responses'][403])) {
                $this->metadata['responses'][403] = [
                    'message' => 'This action is unauthorized.',
                ];
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

        // Pattern to match 'key' => 'value' or 'key' => "value" (handling escaped quotes)
        // This pattern handles: 'error' => 'Message with \'escaped\' quotes'
        // And: 'error' => "Message with \"escaped\" quotes"
        if (preg_match_all("/['\"](\w+)['\"]\s*=>\s*'((?:[^'\\\\]|\\\\.)*)'/s", $arrayContent, $singleQuoteMatches, PREG_SET_ORDER)) {
            foreach ($singleQuoteMatches as $kv) {
                $key = $kv[1];
                $value = stripslashes($kv[2]); // Convert \' to '
                $this->addErrorValue($result, $key, $value, $arrayContent, $statusCode);
            }
        }

        // Also match double-quoted strings
        if (preg_match_all('/[\'"](\w+)[\'"]\s*=>\s*"((?:[^"\\\\]|\\\\.)*)"/s', $arrayContent, $doubleQuoteMatches, PREG_SET_ORDER)) {
            foreach ($doubleQuoteMatches as $kv) {
                $key = $kv[1];
                if (! isset($result[$key])) { // Don't overwrite if already captured
                    $value = stripslashes($kv[2]);
                    $this->addErrorValue($result, $key, $value, $arrayContent, $statusCode);
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
     * Add error value to result array with exception message handling
     */
    private function addErrorValue(array &$result, string $key, string $value, string $arrayContent, int $statusCode): void
    {
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
                    $this->storeModelFieldsInMetadata($className);
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
                    $this->storeModelFieldsInMetadata($modelClass);
                    break;
                }
            }
        }

        // 3. Extract loaded relations from method content
        // This will only include relations actually loaded in the method
        $this->extractLoadedRelations();
    }

    /**
     * Store model fields in metadata (uses extractModelFields internally)
     */
    private function storeModelFieldsInMetadata(string $modelClass): void
    {
        // Get short class name if full namespace is provided
        $shortName = class_exists($modelClass) ? $modelClass : basename(str_replace('\\', '/', $modelClass));

        // Try to resolve full class name
        $fullClassName = null;
        if (class_exists($modelClass)) {
            $fullClassName = $modelClass;
        } else {
            $namespaces = ['App\\Models\\', 'App\\'];
            foreach ($namespaces as $namespace) {
                $testClass = $namespace.$shortName;
                if (class_exists($testClass)) {
                    $fullClassName = $testClass;
                    break;
                }
            }
        }

        if (! $fullClassName) {
            return;
        }

        $fields = $this->extractModelFields(basename(str_replace('\\', '/', $fullClassName)));

        // Store in metadata with types
        foreach ($fields as $field => $value) {
            $type = $this->inferTypeFromFieldName($field);
            if (is_numeric($value)) {
                $type = is_int($value) ? 'integer' : 'number';
            } elseif (is_bool($value)) {
                $type = 'boolean';
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $value)) {
                $type = str_contains((string) $value, ':') ? 'datetime' : 'date';
            }
            $this->metadata['model_fields'][$field] = $type;
        }
    }

    /**
     * Extract fields from PHPDoc @property annotations
     */
    private function extractFieldsFromPhpDoc(\ReflectionClass $reflection): array
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
        // Pattern 1: Match $model->load([...]) or ::with([...]) - array syntax
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

        // Pattern 2: Match $model->load('rel1', 'rel2') - comma-separated string arguments
        // This handles: $team->load('users', 'patients') or $model->load('relation')
        if (preg_match_all('/->load\s*\(\s*([^)\[\]]+)\)/s', $this->methodContent, $matches)) {
            foreach ($matches[1] as $argsBlock) {
                // Skip if it contains [ which means it's array syntax (handled above)
                if (str_contains($argsBlock, '[')) {
                    continue;
                }
                // Match quoted strings: 'relationName' or "relationName"
                if (preg_match_all("/['\"](\w+)['\"]/", $argsBlock, $relationMatches)) {
                    foreach ($relationMatches[1] as $relationName) {
                        $relationName = trim($relationName);
                        if (! empty($relationName) && ! isset($this->metadata['model_relations'][$relationName])) {
                            $this->metadata['model_relations'][$relationName] = 'loaded';
                        }
                    }
                }
            }
        }

        // Pattern 3: Match return response()->json($model->load('rel1', 'rel2'), ...)
        // This handles inline load in return statements, including nested relations like 'questionnaires.answers'
        if (preg_match_all('/return\s+response\(\)\s*->\s*json\s*\(\s*\$(\w+)->load\s*\(\s*([^)]+)\)/s', $this->methodContent, $matches)) {
            $modelVarName = $matches[1][0] ?? null;
            foreach ($matches[2] as $argsBlock) {
                // Match relation names including nested (e.g., 'questionnaires.answers')
                if (preg_match_all("/['\"]([a-zA-Z_.]+)['\"]/", $argsBlock, $relationMatches)) {
                    foreach ($relationMatches[1] as $relationPath) {
                        $relationPath = trim($relationPath);
                        if (! empty($relationPath)) {
                            // For nested relations, parse and build the structure
                            $this->parseAndStoreNestedRelation($relationPath, $modelVarName);
                        }
                    }
                }
            }
        }
    }

    /**
     * Parse a nested relation path and store it in metadata
     * e.g., 'questionnaires.answers' becomes nested structure
     */
    private function parseAndStoreNestedRelation(string $relationPath, ?string $modelVarName): void
    {
        $parts = explode('.', $relationPath);
        $rootRelation = $parts[0];

        // Store the root relation
        if (! isset($this->metadata['model_relations'][$rootRelation])) {
            $this->metadata['model_relations'][$rootRelation] = 'loaded';
        }

        // If there are nested relations, store them for the eager loading system
        if (count($parts) > 1 && ! empty($this->metadata['model_class'])) {
            // Build nested eager relations structure
            if (! isset($this->metadata['eager_relations'])) {
                $this->metadata['eager_relations'] = [];
            }

            $eagerRelations = $this->parseEagerLoadRelations("'{$relationPath}'", $this->metadata['model_class']);
            $this->metadata['eager_relations'] = array_merge_recursive(
                $this->metadata['eager_relations'],
                $eagerRelations
            );
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

        // Add query parameters for GET methods
        if (! empty($this->metadata['query_params'])) {
            foreach ($this->metadata['query_params'] as $param => $config) {
                $type = $config['type'] ?? 'string';
                $required = ($config['required'] ?? false) ? 'required' : 'optional';
                $description = $config['description'] ?? "Filter by {$param}.";
                $example = $this->generateQueryParamExample($param, $type, $config['default'] ?? null);
                $lines[] = " * @queryParam {$param} {$type} {$required} {$description} Example: {$example}";
            }
            if (! empty($lines)) {
                $lines[] = ' *';
            }
        }

        // Add file upload parameters
        if (! empty($this->metadata['file_uploads'])) {
            foreach ($this->metadata['file_uploads'] as $param => $config) {
                $required = ($config['required'] ?? true) ? 'required' : 'optional';
                $mimes = $config['mimes'] ?? null;
                $description = $mimes ? "Accepted types: {$mimes}." : 'File upload.';
                $lines[] = " * @bodyParam {$param} file {$required} {$description}";
            }
            if (! empty($lines)) {
                $lines[] = ' *';
            }
        }

        return $lines;
    }

    /**
     * Generate example value for a query parameter
     */
    private function generateQueryParamExample(string $param, string $type, ?string $default): string
    {
        if ($default !== null && $default !== 'null') {
            return $default;
        }

        $paramLower = strtolower($param);

        // Common patterns
        if ($type === 'integer') {
            if (str_contains($paramLower, 'page')) {
                return '1';
            }
            if (str_contains($paramLower, 'per_page') || str_contains($paramLower, 'limit')) {
                return '15';
            }

            return (string) $this->faker->numberBetween(1, 100);
        }

        if ($type === 'boolean') {
            return 'true';
        }

        // Search/filter params
        if (str_contains($paramLower, 'search') || str_contains($paramLower, 'q') || str_contains($paramLower, 'keyword')) {
            return 'example';
        }

        // Sort params
        if (str_contains($paramLower, 'sort') || str_contains($paramLower, 'order')) {
            if (str_contains($paramLower, 'dir')) {
                return 'asc';
            }

            return 'created_at';
        }

        // Status/type params
        if (str_contains($paramLower, 'status')) {
            return 'active';
        }
        if (str_contains($paramLower, 'type')) {
            return 'default';
        }

        return $this->faker->word();
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
                $bodyLines = explode("\n", trim($body, '{}[]'));
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
                        $isPaginated = $responseData['_paginated'] ?? false;
                        $sqlFields = $responseData['_sql_fields'] ?? [];
                        $transformation = $responseData['_transformation'] ?? null;
                        $lines = array_merge($lines, $this->generateQueryResultResponse($statusCode, $isPaginated, $sqlFields, $transformation));
                    } elseif (isset($responseData['_type']) && $responseData['_type'] === 'collection') {
                        // Generate collection response (array of models) from Model::get() / Model::all()
                        $modelFields = $responseData['_fields'] ?? [];
                        $eagerRelations = $responseData['_eager_relations'] ?? [];
                        $lines = array_merge($lines, $this->generateCollectionResponse($statusCode, $modelFields, $eagerRelations));
                    } elseif (isset($responseData['_type']) && $responseData['_type'] === 'model') {
                        // Generate single model response from Model::findOrFail() / Model::find() / ->load()
                        $modelFields = $responseData['_fields'] ?? [];
                        $eagerRelations = $responseData['_eager_relations'] ?? [];
                        $lines = array_merge($lines, $this->generateSingleModelResponse($statusCode, $modelFields, $eagerRelations));
                    } elseif (isset($responseData['_type']) && $responseData['_type'] === 'paginated') {
                        // Generate paginated response from Model::paginate()
                        $modelFields = $responseData['_fields'] ?? [];
                        $lines = array_merge($lines, $this->generatePaginatedResponse($statusCode, $modelFields));
                    } elseif (isset($responseData['_type']) && $responseData['_type'] === 'simple_paginated') {
                        // Generate simplePaginate response (no total count)
                        $modelFields = $responseData['_fields'] ?? [];
                        $lines = array_merge($lines, $this->generateSimplePaginatedResponse($statusCode, $modelFields));
                    } elseif (isset($responseData['_type']) && $responseData['_type'] === 'cursor_paginated') {
                        // Generate cursorPaginate response (cursor-based)
                        $modelFields = $responseData['_fields'] ?? [];
                        $lines = array_merge($lines, $this->generateCursorPaginatedResponse($statusCode, $modelFields));
                    } elseif (isset($responseData['_type']) && $responseData['_type'] === 'pluck') {
                        // Generate pluck response (array of single column values)
                        $columnName = $responseData['_column'] ?? 'value';
                        $lines = array_merge($lines, $this->generatePluckResponse($statusCode, $columnName));
                    } elseif (isset($responseData['_type']) && $responseData['_type'] === 'value') {
                        // Generate value response (single column value)
                        $columnName = $responseData['_column'] ?? 'value';
                        $lines = array_merge($lines, $this->generateValueResponse($statusCode, $columnName));
                    } elseif (isset($responseData['_type']) && $responseData['_type'] === 'scalar') {
                        // Generate scalar response (count, exists, etc.)
                        $scalarType = $responseData['_scalar_type'] ?? 'integer';
                        $lines = array_merge($lines, $this->generateScalarResponse($statusCode, $scalarType));
                    } elseif (isset($responseData['_type']) && $responseData['_type'] === 'binary_download') {
                        // Generate binary download response (response()->download())
                        $lines = array_merge($lines, $this->generateBinaryDownloadResponse($statusCode));
                    } elseif (isset($responseData['_type']) && $responseData['_type'] === 'relation') {
                        // Generate relation response
                        $relationName = $responseData['_relation'] ?? 'items';
                        $lines = array_merge($lines, $this->generateRelationResponse($statusCode, $relationName));
                    } elseif (isset($responseData['_type']) && $responseData['_type'] === 'mapped_collection') {
                        // Generate mapped collection response (transformed with ->map())
                        $fields = $responseData['_fields'] ?? [];
                        $lines = array_merge($lines, $this->generateMappedCollectionResponse($statusCode, $fields));
                    } elseif (isset($responseData['_type']) && $responseData['_type'] === 'api_resource') {
                        // Generate single API Resource response
                        $fields = $responseData['_fields'] ?? [];
                        $lines = array_merge($lines, $this->generateApiResourceResponse($statusCode, $fields));
                    } elseif (isset($responseData['_type']) && $responseData['_type'] === 'api_resource_collection') {
                        // Generate API Resource collection response
                        $fields = $responseData['_fields'] ?? [];
                        $lines = array_merge($lines, $this->generateApiResourceCollectionResponse($statusCode, $fields));
                    } elseif (isset($responseData['_type']) && $responseData['_type'] === 'api_resource_paginated') {
                        // Generate paginated API Resource response
                        $fields = $responseData['_fields'] ?? [];
                        $lines = array_merge($lines, $this->generateApiResourcePaginatedResponse($statusCode, $fields));
                    } else {
                        // Use the actual parsed response structure
                        $lines[] = " * @response {$statusCode} {";
                        $keys = array_keys($responseData);
                        $lastKey = end($keys);
                        foreach ($responseData as $key => $value) {
                            $comma = ($key !== $lastKey) ? ',' : '';
                            if (is_array($value)) {
                                // Check if it's a model structure that needs to be expanded
                                if (isset($value['_type']) && $value['_type'] === 'model' && isset($value['_fields'])) {
                                    // Expand the model fields
                                    $lines[] = " *   \"{$key}\": {";
                                    $modelFields = $value['_fields'];
                                    $fieldKeys = array_keys($modelFields);
                                    $lastFieldKey = end($fieldKeys);
                                    foreach ($modelFields as $fieldName => $fieldValue) {
                                        $fieldComma = ($fieldName !== $lastFieldKey) ? ',' : '';
                                        $formattedFieldValue = $this->formatResponseValue($fieldValue);
                                        $lines[] = " *     \"{$fieldName}\": {$formattedFieldValue}{$fieldComma}";
                                    }
                                    $lines[] = " *   }{$comma}";
                                } else {
                                    // Format nested object on multiple lines
                                    $lines[] = " *   \"{$key}\": {";
                                    $nestedKeys = array_keys($value);
                                    $lastNestedKey = end($nestedKeys);
                                    foreach ($value as $nestedKey => $nestedValue) {
                                        $nestedComma = ($nestedKey !== $lastNestedKey) ? ',' : '';
                                        $formattedNestedValue = $this->formatResponseValue($nestedValue);
                                        $lines[] = " *     \"{$nestedKey}\": {$formattedNestedValue}{$nestedComma}";
                                    }
                                    $lines[] = " *   }{$comma}";
                                }
                            } else {
                                $formattedValue = $this->formatResponseValue($value);
                                $lines[] = " *   \"{$key}\": {$formattedValue}{$comma}";
                            }
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
        if (is_null($value)) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        // Only treat as number if it's actually an int or float type, not a numeric string
        if (is_int($value) || is_float($value)) {
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
    private function generateQueryResultResponse(int $statusCode, bool $isPaginated = false, array $sqlFields = [], ?array $transformation = null): array
    {
        $lines = [];

        // If there's a transformation (groupBy->map), generate the transformed structure
        if ($transformation && $transformation['type'] === 'groupBy_map') {
            return $this->generateTransformedResponse($statusCode, $sqlFields, $transformation);
        }

        // Prefer SQL fields if available, otherwise fall back to model/validation fields
        $fieldsToUse = ! empty($sqlFields) ? $sqlFields : $this->getResponseFields();
        $timestamp = $this->faker->dateTimeThisYear()->format('Y-m-d\TH:i:s.000000\Z');

        if ($isPaginated) {
            // Paginated response with data/meta structure
            $lines[] = " * @response {$statusCode} {";
            $lines[] = ' *   "data": [{';

            if (! empty($fieldsToUse)) {
                $fieldLines = $this->generateFieldLines($fieldsToUse, '    ', $timestamp);
                $lines = array_merge($lines, $fieldLines);
            } else {
                $lines[] = ' *     "id": 1,';
                $lines[] = ' *     "name": "Example"';
            }

            $lines[] = ' *   }],';
            $lines[] = ' *   "meta": {"current_page": 1, "per_page": 15, "total": '.$this->faker->numberBetween(10, 200).'}';
            $lines[] = ' * }';
        } else {
            // Simple array response without pagination wrapper
            $lines[] = " * @response {$statusCode} [";
            $lines[] = ' *   {';

            if (! empty($fieldsToUse)) {
                $fieldLines = $this->generateFieldLines($fieldsToUse, '    ', $timestamp);
                $lines = array_merge($lines, $fieldLines);
            } else {
                $lines[] = ' *     "id": 1,';
                $lines[] = ' *     "name": "Example"';
            }

            $lines[] = ' *   }';
            $lines[] = ' * ]';
        }
        $lines[] = ' *';

        return $lines;
    }

    /**
     * Generate response for Eloquent collection (array of models) from Model::get() / Model::all()
     */
    private function generateCollectionResponse(int $statusCode, array $modelFields, array $eagerRelations = []): array
    {
        $lines = [];

        $lines[] = " * @response {$statusCode} [";
        $lines[] = ' *   {';

        if (! empty($modelFields)) {
            $fieldLines = $this->generateModelFieldLines($modelFields, '    ', ! empty($eagerRelations));
            $lines = array_merge($lines, $fieldLines);
        } else {
            $lines[] = ' *     "id": 1,';
            $lines[] = ' *     "name": "Example"';
        }

        // Add eager-loaded relations
        if (! empty($eagerRelations)) {
            $this->addEagerRelationsToResponse($lines, $eagerRelations, '    ');
        }

        $lines[] = ' *   }';
        $lines[] = ' * ]';
        $lines[] = ' *';

        return $lines;
    }

    /**
     * Add eager-loaded relations to response with nested structure
     */
    private function addEagerRelationsToResponse(array &$lines, array $relations, string $indent): void
    {
        $relationNames = array_keys($relations);
        $lastRelation = end($relationNames);

        foreach ($relations as $relationName => $relationData) {
            $isMany = in_array($relationData['type'] ?? 'hasMany', ['hasMany', 'belongsToMany', 'morphMany', 'morphToMany']);
            $isLast = ($relationName === $lastRelation);
            $comma = $isLast ? '' : ',';
            $fields = $relationData['fields'] ?? [];
            $nested = $relationData['nested'] ?? [];

            if ($isMany) {
                $lines[] = " *{$indent}\"{$relationName}\": [";
                $lines[] = " *{$indent}  {";
                $this->addFormattedFieldLines($lines, $fields, $indent.'    ', ! empty($nested));
                if (! empty($nested)) {
                    $this->addEagerRelationsToResponse($lines, $nested, $indent.'    ');
                }
                $lines[] = " *{$indent}  }";
                $lines[] = " *{$indent}]{$comma}";
            } else {
                $lines[] = " *{$indent}\"{$relationName}\": {";
                $this->addFormattedFieldLines($lines, $fields, $indent.'  ', ! empty($nested));
                if (! empty($nested)) {
                    $this->addEagerRelationsToResponse($lines, $nested, $indent.'  ');
                }
                $lines[] = " *{$indent}}{$comma}";
            }
        }
    }

    /**
     * Generate response for mapped collection (transformed with ->map())
     * This handles patterns like: $collection->map(function($item) { return ['id' => ..., 'name' => ...]; })
     */
    private function generateMappedCollectionResponse(int $statusCode, array $fields): array
    {
        $lines = [];

        $lines[] = " * @response {$statusCode} [";
        $lines[] = ' *   {';

        if (! empty($fields)) {
            $fieldKeys = array_keys($fields);
            $lastKey = end($fieldKeys);

            foreach ($fields as $fieldName => $fieldType) {
                $comma = ($fieldName !== $lastKey) ? ',' : '';
                $exampleValue = $this->generateExampleValueForMappedField($fieldName, $fieldType);
                $lines[] = " *    \"{$fieldName}\": {$exampleValue}{$comma}";
            }
        } else {
            $lines[] = ' *     "id": 1,';
            $lines[] = ' *     "name": "Example"';
        }

        $lines[] = ' *   }';
        $lines[] = ' * ]';
        $lines[] = ' *';

        return $lines;
    }

    /**
     * Generate example value for a mapped field based on field name and type
     */
    private function generateExampleValueForMappedField(string $fieldName, string $fieldType): string
    {
        $fieldLower = strtolower($fieldName);

        // Generate appropriate example value based on type and field name
        return match ($fieldType) {
            'integer' => $this->generateIntegerExample($fieldLower),
            'boolean' => 'true',
            'number' => '"99.99"',
            'datetime' => '"'.date('Y-m-d\TH:i:s').'"',
            default => $this->generateStringExample($fieldLower),
        };
    }

    /**
     * Generate integer example based on field name
     */
    private function generateIntegerExample(string $fieldLower): string
    {
        if (str_contains($fieldLower, 'id')) {
            return '1';
        }
        if (str_contains($fieldLower, 'total') || str_contains($fieldLower, 'count')) {
            return (string) $this->faker->numberBetween(0, 100);
        }

        return (string) $this->faker->numberBetween(1, 999);
    }

    /**
     * Generate string example based on field name
     */
    private function generateStringExample(string $fieldLower): string
    {
        if (str_contains($fieldLower, 'name') || str_contains($fieldLower, 'nome')) {
            return '"'.$this->faker->words(2, true).'"';
        }
        if (str_contains($fieldLower, 'email')) {
            return '"'.$this->faker->email().'"';
        }
        if (str_contains($fieldLower, 'phone') || str_contains($fieldLower, 'telefono')) {
            return '"'.$this->faker->phoneNumber().'"';
        }
        if (str_contains($fieldLower, 'description') || str_contains($fieldLower, 'descrizione')) {
            return '"'.$this->faker->sentence(5).'"';
        }

        return '"'.$this->faker->word().'"';
    }

    /**
     * Generate response for single API Resource
     */
    private function generateApiResourceResponse(int $statusCode, array $fields): array
    {
        $lines = [];

        $lines[] = " * @response {$statusCode} {";
        $lines[] = ' *   "data": {';

        if (! empty($fields)) {
            $fieldKeys = array_keys($fields);
            $lastKey = end($fieldKeys);

            foreach ($fields as $fieldName => $fieldValue) {
                $comma = ($fieldName !== $lastKey) ? ',' : '';
                $formattedValue = $this->formatApiResourceFieldValue($fieldName, $fieldValue);
                $lines[] = " *     \"{$fieldName}\": {$formattedValue}{$comma}";
            }
        } else {
            $lines[] = ' *     "id": 1,';
            $lines[] = ' *     "name": "Example"';
        }

        $lines[] = ' *   }';
        $lines[] = ' * }';
        $lines[] = ' *';

        return $lines;
    }

    /**
     * Generate response for API Resource collection
     */
    private function generateApiResourceCollectionResponse(int $statusCode, array $fields): array
    {
        $lines = [];

        $lines[] = " * @response {$statusCode} {";
        $lines[] = ' *   "data": [';
        $lines[] = ' *     {';

        if (! empty($fields)) {
            $fieldKeys = array_keys($fields);
            $lastKey = end($fieldKeys);

            foreach ($fields as $fieldName => $fieldValue) {
                $comma = ($fieldName !== $lastKey) ? ',' : '';
                $formattedValue = $this->formatApiResourceFieldValue($fieldName, $fieldValue);
                $lines[] = " *       \"{$fieldName}\": {$formattedValue}{$comma}";
            }
        } else {
            $lines[] = ' *       "id": 1,';
            $lines[] = ' *       "name": "Example"';
        }

        $lines[] = ' *     }';
        $lines[] = ' *   ]';
        $lines[] = ' * }';
        $lines[] = ' *';

        return $lines;
    }

    /**
     * Generate response for paginated API Resource collection
     */
    private function generateApiResourcePaginatedResponse(int $statusCode, array $fields): array
    {
        $lines = [];

        $lines[] = " * @response {$statusCode} {";
        $lines[] = ' *   "data": [';
        $lines[] = ' *     {';

        if (! empty($fields)) {
            $fieldKeys = array_keys($fields);
            $lastKey = end($fieldKeys);

            foreach ($fields as $fieldName => $fieldValue) {
                $comma = ($fieldName !== $lastKey) ? ',' : '';
                $formattedValue = $this->formatApiResourceFieldValue($fieldName, $fieldValue);
                $lines[] = " *       \"{$fieldName}\": {$formattedValue}{$comma}";
            }
        } else {
            $lines[] = ' *       "id": 1,';
            $lines[] = ' *       "name": "Example"';
        }

        $lines[] = ' *     }';
        $lines[] = ' *   ],';
        $lines[] = ' *   "links": {';
        $lines[] = ' *     "first": "http://example.com/api/resource?page=1",';
        $lines[] = ' *     "last": "http://example.com/api/resource?page='.$this->faker->numberBetween(5, 20).'",';
        $lines[] = ' *     "prev": null,';
        $lines[] = ' *     "next": "http://example.com/api/resource?page=2"';
        $lines[] = ' *   },';
        $lines[] = ' *   "meta": {';
        $lines[] = ' *     "current_page": 1,';
        $lines[] = ' *     "from": 1,';
        $lines[] = ' *     "last_page": '.$this->faker->numberBetween(5, 20).',';
        $lines[] = ' *     "per_page": 15,';
        $lines[] = ' *     "to": 15,';
        $lines[] = ' *     "total": '.$this->faker->numberBetween(50, 200);
        $lines[] = ' *   }';
        $lines[] = ' * }';
        $lines[] = ' *';

        return $lines;
    }

    /**
     * Format API Resource field value for documentation
     */
    private function formatApiResourceFieldValue(string $fieldName, mixed $fieldValue): string
    {
        // Handle special resource field types
        if (is_array($fieldValue)) {
            // whenLoaded relation
            if (isset($fieldValue['_whenLoaded'])) {
                return '{...}';  // Conditional relation
            }
            // Nested resource
            if (isset($fieldValue['_resource'])) {
                return '{...}';  // Nested object
            }
            // Resource collection
            if (isset($fieldValue['_resource_collection'])) {
                return '[...]';  // Array of objects
            }
            // Conditional field
            if (isset($fieldValue['_conditional'])) {
                return 'null';
            }
        }

        // Use the value directly if it's a string/int/bool
        if (is_scalar($fieldValue)) {
            return $this->formatResponseValue($fieldValue);
        }

        // Infer from field name
        return $this->formatResponseValue($this->inferFieldTypeFromName($fieldName));
    }

    /**
     * Generate response for single Eloquent model from Model::findOrFail() / Model::find() / ->load()
     */
    private function generateSingleModelResponse(int $statusCode, array $modelFields, array $eagerRelations = []): array
    {
        $lines = [];

        $lines[] = " * @response {$statusCode} {";

        if (! empty($modelFields)) {
            // Check if we have relations loaded (either from metadata or passed eager relations)
            $hasRelations = ! empty($this->metadata['model_relations']) || ! empty($eagerRelations);

            $fieldKeys = array_keys($modelFields);
            $lastKey = end($fieldKeys);

            foreach ($modelFields as $field => $value) {
                // Add comma if not last field, or if we have relations to add after
                $comma = ($field !== $lastKey || $hasRelations) ? ',' : '';
                $formattedValue = $this->formatResponseValue($value);
                $lines[] = " *  \"{$field}\": {$formattedValue}{$comma}";
            }

            // Add loaded relations to the response
            // Prefer passed eagerRelations (from ->load() parsing) over metadata
            if (! empty($eagerRelations)) {
                $this->addEagerRelationsToResponse($lines, $eagerRelations, ' ');
            } elseif (! empty($this->metadata['model_relations'])) {
                $this->addRelationsToResponse($lines, ' ');
            }
        } else {
            $lines[] = ' *   "id": 1,';
            $lines[] = ' *   "name": "Example"';
        }

        $lines[] = ' * }';
        $lines[] = ' *';

        return $lines;
    }

    /**
     * Generate field lines for model response documentation
     */
    private function generateModelFieldLines(array $fields, string $indent, bool $hasMoreContent = false): array
    {
        $lines = [];
        $fieldKeys = array_keys($fields);
        $lastKey = end($fieldKeys);

        foreach ($fields as $field => $value) {
            // Add comma if not last field OR if there's more content coming (relations)
            $isLastField = ($field === $lastKey);
            $comma = (! $isLastField || $hasMoreContent) ? ',' : '';
            $formattedValue = $this->formatResponseValue($value);
            $lines[] = " *{$indent}\"{$field}\": {$formattedValue}{$comma}";
        }

        return $lines;
    }

    /**
     * Generate response for paginated results from Model::paginate() / simplePaginate() / cursorPaginate()
     */
    private function generatePaginatedResponse(int $statusCode, array $modelFields): array
    {
        $lines = [];

        $lines[] = " * @response {$statusCode} {";
        $lines[] = ' *   "data": [';
        $lines[] = ' *     {';

        if (! empty($modelFields)) {
            $fieldLines = $this->generateModelFieldLines($modelFields, '      ');
            $lines = array_merge($lines, $fieldLines);
        } else {
            $lines[] = ' *       "id": 1,';
            $lines[] = ' *       "name": "Example"';
        }

        $lines[] = ' *     }';
        $lines[] = ' *   ],';
        $lines[] = ' *   "links": {';
        $lines[] = ' *     "first": "http://example.com/api/resource?page=1",';
        $lines[] = ' *     "last": "http://example.com/api/resource?page='.$this->faker->numberBetween(5, 20).'",';
        $lines[] = ' *     "prev": null,';
        $lines[] = ' *     "next": "http://example.com/api/resource?page=2"';
        $lines[] = ' *   },';
        $lines[] = ' *   "meta": {';
        $lines[] = ' *     "current_page": 1,';
        $lines[] = ' *     "from": 1,';
        $lines[] = ' *     "last_page": '.$this->faker->numberBetween(5, 20).',';
        $lines[] = ' *     "per_page": 15,';
        $lines[] = ' *     "to": 15,';
        $lines[] = ' *     "total": '.$this->faker->numberBetween(50, 200);
        $lines[] = ' *   }';
        $lines[] = ' * }';
        $lines[] = ' *';

        return $lines;
    }

    /**
     * Generate response for simplePaginate results (no total count, more efficient)
     */
    private function generateSimplePaginatedResponse(int $statusCode, array $modelFields): array
    {
        $lines = [];

        $lines[] = " * @response {$statusCode} {";
        $lines[] = ' *   "data": [';
        $lines[] = ' *     {';

        if (! empty($modelFields)) {
            $fieldLines = $this->generateModelFieldLines($modelFields, '      ');
            $lines = array_merge($lines, $fieldLines);
        } else {
            $lines[] = ' *       "id": 1,';
            $lines[] = ' *       "name": "Example"';
        }

        $lines[] = ' *     }';
        $lines[] = ' *   ],';
        $lines[] = ' *   "links": {';
        $lines[] = ' *     "first": "http://example.com/api/resource?page=1",';
        $lines[] = ' *     "last": null,';
        $lines[] = ' *     "prev": null,';
        $lines[] = ' *     "next": "http://example.com/api/resource?page=2"';
        $lines[] = ' *   },';
        $lines[] = ' *   "meta": {';
        $lines[] = ' *     "current_page": 1,';
        $lines[] = ' *     "from": 1,';
        $lines[] = ' *     "path": "http://example.com/api/resource",';
        $lines[] = ' *     "per_page": 15,';
        $lines[] = ' *     "to": 15';
        $lines[] = ' *   }';
        $lines[] = ' * }';
        $lines[] = ' *';

        return $lines;
    }

    /**
     * Generate response for cursorPaginate results (cursor-based pagination)
     */
    private function generateCursorPaginatedResponse(int $statusCode, array $modelFields): array
    {
        $lines = [];
        $cursor = base64_encode(json_encode(['id' => $this->faker->numberBetween(10, 100)]));

        $lines[] = " * @response {$statusCode} {";
        $lines[] = ' *   "data": [';
        $lines[] = ' *     {';

        if (! empty($modelFields)) {
            $fieldLines = $this->generateModelFieldLines($modelFields, '      ');
            $lines = array_merge($lines, $fieldLines);
        } else {
            $lines[] = ' *       "id": 1,';
            $lines[] = ' *       "name": "Example"';
        }

        $lines[] = ' *     }';
        $lines[] = ' *   ],';
        $lines[] = ' *   "path": "http://example.com/api/resource",';
        $lines[] = ' *   "per_page": 15,';
        $lines[] = ' *   "next_cursor": "'.$cursor.'",';
        $lines[] = ' *   "next_page_url": "http://example.com/api/resource?cursor='.$cursor.'",';
        $lines[] = ' *   "prev_cursor": null,';
        $lines[] = ' *   "prev_page_url": null';
        $lines[] = ' * }';
        $lines[] = ' *';

        return $lines;
    }

    /**
     * Generate response for pluck results (array of single column values)
     */
    private function generatePluckResponse(int $statusCode, string $columnName): array
    {
        $lines = [];

        // Determine example values based on column name
        $exampleValues = $this->generatePluckExampleValues($columnName);

        $lines[] = " * @response {$statusCode} [";
        $lines[] = ' *   '.$exampleValues;
        $lines[] = ' * ]';
        $lines[] = ' *';

        return $lines;
    }

    /**
     * Generate example values for pluck response based on column name
     */
    private function generatePluckExampleValues(string $columnName): string
    {
        $columnLower = strtolower($columnName);

        // ID fields
        if (preg_match('/^id$|_id$/', $columnLower)) {
            return '1, 2, 3, 4, 5';
        }

        // Name fields
        if (preg_match('/name|nome|title|titolo/', $columnLower)) {
            return '"'.$this->faker->word().'", "'.$this->faker->word().'", "'.$this->faker->word().'"';
        }

        // Email fields
        if (preg_match('/email|pec/', $columnLower)) {
            return '"'.$this->faker->email().'", "'.$this->faker->email().'"';
        }

        // Date fields
        if (preg_match('/date|data|_at$/', $columnLower)) {
            return '"'.date('Y-m-d').'", "'.date('Y-m-d', strtotime('-1 day')).'"';
        }

        // Status fields
        if (preg_match('/status|stato/', $columnLower)) {
            return '"active", "pending", "inactive"';
        }

        // Default: string values
        return '"'.$this->faker->word().'", "'.$this->faker->word().'", "'.$this->faker->word().'"';
    }

    /**
     * Generate response for value results (single column value)
     */
    private function generateValueResponse(int $statusCode, string $columnName): array
    {
        $lines = [];

        // Determine example value based on column name
        $exampleValue = $this->generateValueExample($columnName);

        $lines[] = " * @response {$statusCode} {$exampleValue}";
        $lines[] = ' *';

        return $lines;
    }

    /**
     * Generate example value for value() response based on column name
     */
    private function generateValueExample(string $columnName): string
    {
        $columnLower = strtolower($columnName);

        // ID fields
        if (preg_match('/^id$|_id$/', $columnLower)) {
            return (string) $this->faker->numberBetween(1, 100);
        }

        // Name fields
        if (preg_match('/name|nome|title|titolo/', $columnLower)) {
            return '"'.$this->faker->word().'"';
        }

        // Email fields
        if (preg_match('/email|pec/', $columnLower)) {
            return '"'.$this->faker->email().'"';
        }

        // Date fields
        if (preg_match('/date|data|_at$/', $columnLower)) {
            return '"'.date('Y-m-d').'"';
        }

        // Numeric fields
        if (preg_match('/count|total|amount|price|num/', $columnLower)) {
            return (string) $this->faker->numberBetween(1, 1000);
        }

        // Boolean fields
        if (preg_match('/^is_|^has_|^can_|active|enabled/', $columnLower)) {
            return 'true';
        }

        // Default: string value
        return '"'.$this->faker->word().'"';
    }

    /**
     * Generate response for scalar results (count, exists, etc.)
     */
    private function generateScalarResponse(int $statusCode, string $scalarType): array
    {
        $lines = [];

        if ($scalarType === 'boolean') {
            $lines[] = " * @response {$statusCode} true";
        } else {
            $lines[] = " * @response {$statusCode} ".$this->faker->numberBetween(1, 100);
        }
        $lines[] = ' *';

        return $lines;
    }

    /**
     * Generate response for binary file download (response()->download())
     */
    private function generateBinaryDownloadResponse(int $statusCode): array
    {
        $lines = [];

        $lines[] = " * @response {$statusCode} scenario=\"File download\" Binary file content (application/octet-stream)";
        $lines[] = ' *';

        return $lines;
    }

    /**
     * Generate response for relation results
     */
    private function generateRelationResponse(int $statusCode, string $relationName): array
    {
        $lines = [];

        $lines[] = " * @response {$statusCode} [";
        $lines[] = ' *   {';
        $lines[] = ' *     "id": 1,';
        $lines[] = ' *     "name": "'.$this->faker->word().'",';
        $lines[] = ' *     "created_at": "'.date('Y-m-d H:i:s').'",';
        $lines[] = ' *     "updated_at": "'.date('Y-m-d H:i:s').'"';
        $lines[] = ' *   }';
        $lines[] = ' * ]';
        $lines[] = ' *';

        return $lines;
    }

    /**
     * Generate response for transformed collections (groupBy->map)
     */
    private function generateTransformedResponse(int $statusCode, array $sqlFields, array $transformation): array
    {
        $lines = [];
        $groupByField = $transformation['groupBy'];
        $structure = $transformation['structure'] ?? [];

        $lines[] = " * @response {$statusCode} [";
        $lines[] = ' *   {';

        // Generate fields based on the transformation structure
        $structureKeys = array_keys($structure);
        $lastStructureKey = end($structureKeys);

        foreach ($structure as $key => $value) {
            $isLast = ($key === $lastStructureKey);

            if (is_array($value) && ! empty($value)) {
                // Nested array (e.g., indicatori)
                $exampleValue = $this->generateFakerExampleForSqlField($key, 'string');
                $lines[] = " *     \"{$key}\": [";
                $lines[] = ' *       {';

                // Generate nested fields based on SQL fields that match
                $nestedKeys = array_keys($value);
                $lastNestedKey = end($nestedKeys);

                foreach ($value as $nestedField => $sourceField) {
                    $nestedIsLast = ($nestedField === $lastNestedKey);
                    // Use the source field to determine type from SQL fields
                    $type = $sqlFields[$sourceField] ?? 'string';
                    $example = $this->generateFakerExampleForSqlField($nestedField, $type);
                    $formattedValue = $this->formatJsonValueForType($example, $type);
                    $comma = $nestedIsLast ? '' : ',';
                    $lines[] = " *         \"{$nestedField}\": {$formattedValue}{$comma}";
                }

                $lines[] = ' *       }';
                $comma = $isLast ? '' : ',';
                $lines[] = " *     ]{$comma}";
            } else {
                // Simple field - use the groupBy field value or generate example
                $comma = $isLast ? '' : ',';
                if ($key === $groupByField || strpos(strtolower($key), strtolower($groupByField)) !== false) {
                    // This is the groupBy field, generate appropriate example
                    $example = $this->generateFakerExampleForSqlField($key, 'string');
                    $formattedValue = $this->formatJsonValueForType($example, 'string');
                    $lines[] = " *     \"{$key}\": {$formattedValue}{$comma}";
                } else {
                    $type = $sqlFields[$key] ?? 'string';
                    $example = $this->generateFakerExampleForSqlField($key, $type);
                    $formattedValue = $this->formatJsonValueForType($example, $type);
                    $lines[] = " *     \"{$key}\": {$formattedValue}{$comma}";
                }
            }
        }

        $lines[] = ' *   }';
        $lines[] = ' * ]';
        $lines[] = ' *';

        return $lines;
    }

    /**
     * Generate field lines for response documentation
     */
    private function generateFieldLines(array $fields, string $indent, string $timestamp): array
    {
        $lines = [];
        $fieldKeys = array_keys($fields);
        $lastKey = end($fieldKeys);

        foreach ($fields as $field => $type) {
            $example = $this->generateFakerExampleForSqlField($field, $type);
            $value = $this->formatJsonValueForType($example, $type);
            $comma = ($field !== $lastKey) ? ',' : '';
            $lines[] = " *{$indent}\"{$field}\": {$value}{$comma}";
        }

        return $lines;
    }

    /**
     * Generate faker example for SQL field based on common naming conventions (EN/IT)
     */
    private function generateFakerExampleForSqlField(string $field, string $type): mixed
    {
        $fieldLower = strtolower($field);

        // ID fields
        if ($fieldLower === 'id' || preg_match('/^id_|_id$/', $fieldLower)) {
            return $this->faker->numberBetween(1, 1000);
        }

        // Name fields (EN: name, surname / IT: nome, cognome)
        if (preg_match('/last_?name|surname|family_?name|cognome/', $fieldLower)) {
            return $this->faker->lastName();
        }

        if (preg_match('/first_?name|given_?name|^name$|^nome$/', $fieldLower)) {
            return $this->faker->firstName();
        }

        if (preg_match('/full_?name|display_?name|nome_?completo|nominativo/', $fieldLower)) {
            return $this->faker->name();
        }

        // Fiscal code / Tax ID (IT: codice fiscale, CF)
        if (preg_match('/cf|codice_?fiscale|fiscal_?code|tax_?id|cfpaziente|cfutente/', $fieldLower)) {
            return strtoupper($this->faker->bothify('??????##?##?###?'));
        }

        // VAT number (IT: partita IVA)
        if (preg_match('/vat|partita_?iva|p_?iva|piva/', $fieldLower)) {
            return $this->faker->numerify('IT###########');
        }

        // Contact fields (EN + IT)
        if (preg_match('/email|e_mail|posta/', $fieldLower)) {
            return $this->faker->email();
        }

        if (preg_match('/phone|mobile|cell|tel$|telephone|telefono|cellulare|fisso/', $fieldLower)) {
            return $this->faker->phoneNumber();
        }

        // Address fields (EN: address, street / IT: indirizzo, via)
        if (preg_match('/address|street|indirizzo|via|residenza|domicilio/', $fieldLower)) {
            return $this->faker->streetAddress();
        }

        if (preg_match('/city|town|citta|comune|localita/', $fieldLower)) {
            return $this->faker->city();
        }

        if (preg_match('/province|provincia|prov/', $fieldLower)) {
            return $this->faker->stateAbbr();
        }

        if (preg_match('/region|regione/', $fieldLower)) {
            return $this->faker->state();
        }

        if (preg_match('/country|nazione|stato|paese/', $fieldLower)) {
            return $this->faker->country();
        }

        if (preg_match('/zip|postal|postcode|cap/', $fieldLower)) {
            return $this->faker->postcode();
        }

        // Date/time fields (EN + IT: data, ora, giorno)
        if (preg_match('/_at$|_date$|^date_|_time$|timestamp|datetime|^data_|_data$|^data$|^ora_|_ora$|nascita|scadenza|rilevazione|registrazione/', $fieldLower)) {
            return $this->faker->date('Y-m-d');
        }

        // Gender/sex fields (EN + IT: sesso, genere)
        if (preg_match('/gender|sex|sesso|genere/', $fieldLower)) {
            return $this->faker->randomElement(['M', 'F']);
        }

        // Age field (EN + IT: età)
        if (preg_match('/^age$|_age$|^eta$|_eta$/', $fieldLower)) {
            return $this->faker->numberBetween(18, 80);
        }

        // Fields starting with % (percentage values, should be 0-1 range)
        if (str_starts_with($field, '%') || str_starts_with($field, '% ')) {
            return round($this->faker->randomFloat(2, 0, 1), 2);
        }

        // Percentage/ratio fields (EN + IT: percentuale, rapporto)
        if (preg_match('/percent|ratio|rate|percentuale|rapporto/', $fieldLower)) {
            return round($this->faker->randomFloat(2, 0, 100), 2);
        }

        // Count/quantity fields (EN + IT: conteggio, quantità, numeratore, denominatore)
        if (preg_match('/count|total|qty|quantity|number|num_|_num$|conteggio|quantita|totale|numeratore|denominatore/', $fieldLower)) {
            return $this->faker->numberBetween(0, 500);
        }

        // Price/amount fields (EN + IT: prezzo, importo, costo)
        if (preg_match('/price|amount|cost|fee|prezzo|importo|costo|tariffa|compenso/', $fieldLower)) {
            return round($this->faker->randomFloat(2, 10, 1000), 2);
        }

        // Value fields (IT: valore)
        if (preg_match('/value|valore|minimo|massimo|minima|massima/', $fieldLower)) {
            return round($this->faker->randomFloat(2, 0, 100), 2);
        }

        // Score fields (EN + IT: punteggio, score)
        if (preg_match('/score|punteggio|rischio/', $fieldLower)) {
            return round($this->faker->randomFloat(2, 0, 100), 2);
        }

        // URL fields (EN + IT: sito, collegamento)
        if (preg_match('/url|link|href|website|sito|collegamento/', $fieldLower)) {
            return $this->faker->url();
        }

        // Description/text fields (EN + IT: descrizione, contenuto, testo, messaggio, nota)
        if (preg_match('/description|desc|content|body|text|message|comment|note|descrizione|contenuto|testo|messaggio|commento|nota|osservazione|annotazione/', $fieldLower)) {
            return $this->faker->sentence();
        }

        // Title fields (EN + IT: titolo, oggetto, intestazione)
        if (preg_match('/title|subject|heading|titolo|oggetto|intestazione/', $fieldLower)) {
            return $this->faker->sentence(3);
        }

        // Code/reference fields (EN + IT: codice, riferimento)
        if (preg_match('/code|ref|reference|sku|serial|codice|riferimento|matricola/', $fieldLower)) {
            return strtoupper($this->faker->bothify('???-####'));
        }

        // Status fields (EN + IT: stato)
        if (preg_match('/status|state|stato/', $fieldLower)) {
            return $this->faker->randomElement(['active', 'pending', 'completed', 'cancelled']);
        }

        // Type fields (EN + IT: tipo, tipologia, categoria)
        if (preg_match('/^type$|_type$|^tipo$|_tipo$|tipologia|categoria/', $fieldLower)) {
            return $this->faker->word();
        }

        // Medical/health specific (IT: patologia, esame, diagnosi, terapia)
        if (preg_match('/patologia|malattia|diagnosi|disease|diagnosis/', $fieldLower)) {
            return $this->faker->randomElement(['Ipertensione', 'Diabete', 'Nessuna']);
        }

        if (preg_match('/esame|test|analisi|exam/', $fieldLower)) {
            return $this->faker->randomElement(['Positivo', 'Negativo', 'In attesa']);
        }

        if (preg_match('/terapia|therapy|trattamento|treatment/', $fieldLower)) {
            return $this->faker->sentence(2);
        }

        // Habit/behavior fields (IT: abitudine)
        if (preg_match('/abitudine|habit|fumo|smoking|alcol|alcohol/', $fieldLower)) {
            return $this->faker->randomElement(['Si', 'No', 'Ex']);
        }

        // Handle by inferred type
        switch ($type) {
            case 'integer':
                return $this->faker->numberBetween(1, 100);
            case 'number':
                return round($this->faker->randomFloat(2, 0, 1000), 2);
            case 'boolean':
                return $this->faker->boolean();
            case 'datetime':
                return $this->faker->dateTimeThisYear()->format('Y-m-d H:i:s');
            case 'email':
                return $this->faker->email();
            case 'phone':
                return $this->faker->phoneNumber();
            case 'url':
                return $this->faker->url();
            default:
                return $this->faker->word();
        }
    }

    /**
     * Format JSON value based on type
     */
    private function formatJsonValueForType(mixed $value, string $type): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if ($type === 'integer' || $type === 'number') {
            return is_numeric($value) ? (string) $value : '0';
        }

        if ($value === null) {
            return 'null';
        }

        return '"'.addslashes((string) $value).'"';
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
                $isPaginated = $this->usesPagination();

                if ($isPaginated) {
                    // Paginated response with data/meta structure
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
                } else {
                    // Simple array response without pagination wrapper
                    $lines[] = " * @response {$successStatus} [{";
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
                    $lines[] = " *   \"created_at\": \"{$timestamp}\"";
                    $lines[] = ' * }]';
                }
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
                $relationNames = array_keys($this->metadata['model_relations'] ?? []);
                $lines[] = " * @response {$successStatus} {";
                $lines[] = ' *   "id": '.$this->faker->numberBetween(1, 100).',';
                foreach ($fieldsToUse as $field => $type) {
                    if ($field === 'id' || $field === 'created_at' || $field === 'updated_at') {
                        continue;
                    }
                    // Skip fields that are relations (will be added by addRelationsToResponse)
                    if (in_array($field, $relationNames)) {
                        continue;
                    }
                    $example = $this->generateFakerExample($field, $type, '');
                    $value = $this->formatJsonValue($example, $type);
                    $lines[] = " *   \"{$field}\": {$value},";
                }
                $this->addRelationsToResponse($lines, '  ');
                $lines[] = " *   \"created_at\": \"{$timestamp}\"";
                $lines[] = ' * }';
                $lines[] = ' *';
                break;

            case 'update':
            case 'edit':
                $relationNames = array_keys($this->metadata['model_relations'] ?? []);
                $lines[] = " * @response {$successStatus} {";
                $lines[] = ' *   "id": '.$this->faker->numberBetween(1, 100).',';
                foreach ($fieldsToUse as $field => $type) {
                    if ($field === 'id' || $field === 'created_at' || $field === 'updated_at') {
                        continue;
                    }
                    // Skip fields that are relations (will be added by addRelationsToResponse)
                    if (in_array($field, $relationNames)) {
                        continue;
                    }
                    $example = $this->generateFakerExample($field, $type, '');
                    $value = $this->formatJsonValue($example, $type);
                    $lines[] = " *   \"{$field}\": {$value},";
                }
                $this->addRelationsToResponse($lines, '  ');
                $lines[] = " *   \"updated_at\": \"{$timestamp}\"";
                $lines[] = ' * }';
                $lines[] = ' *';
                break;

            case 'destroy':
            case 'delete':
                // First, try to detect actual response from code
                // Pattern: return response()->json("string", statusCode) - returns plain string
                if (preg_match('/return\s+response\(\)\s*->\s*json\s*\(\s*["\']([^"\']+)["\']\s*,\s*(\d{3})\s*\)\s*;/s', $this->methodContent, $stringMatch)) {
                    $message = $stringMatch[1];
                    $actualStatus = (int) $stringMatch[2];
                    $lines[] = " * @response {$actualStatus} \"{$message}\"";
                    $lines[] = ' *';
                }
                // Pattern: return response()->json(null, 204)
                elseif (preg_match('/return\s+response\(\)\s*->\s*json\s*\(\s*null\s*,\s*204\s*\)\s*;/s', $this->methodContent)) {
                    $lines[] = ' * @response 204 scenario="Resource deleted successfully"';
                    $lines[] = ' *';
                }
                // 204 No Content should not have a body per HTTP specification
                elseif ($successStatus === 204) {
                    $lines[] = ' * @response 204 scenario="Resource deleted successfully"';
                    $lines[] = ' *';
                } else {
                    $lines[] = " * @response {$successStatus} {";
                    $lines[] = " *   \"message\": \"{$resourceName} deleted successfully\"";
                    $lines[] = ' * }';
                    $lines[] = ' *';
                }
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

        $relationNames = array_keys($this->metadata['model_relations']);
        $lastRelation = end($relationNames);

        foreach ($this->metadata['model_relations'] as $relationName => $relationType) {
            // Determine if it's a to-many or to-one relation
            $isMany = in_array($relationType, ['hasMany', 'belongsToMany', 'morphMany', 'morphToMany', 'morphedByMany', 'loaded']);
            $isLast = ($relationName === $lastRelation);
            $comma = $isLast ? '' : ',';

            // Try to get the related model's fields
            $relatedFields = $this->getRelatedModelFields($relationName);

            if ($isMany) {
                // Array of objects - format on multiple lines
                $lines[] = " *{$indent}\"{$relationName}\": [";
                $lines[] = " *{$indent}  {";
                $this->addFormattedFieldLines($lines, $relatedFields, $indent.'    ');
                $lines[] = " *{$indent}  },";
                $lines[] = " *{$indent}  {";
                // Generate new fake values for second item
                $relatedFields2 = $this->getRelatedModelFields($relationName);
                $this->addFormattedFieldLines($lines, $relatedFields2, $indent.'    ');
                $lines[] = " *{$indent}  }";
                $lines[] = " *{$indent}]{$comma}";
            } else {
                // Single object - format on multiple lines
                $lines[] = " *{$indent}\"{$relationName}\": {";
                $this->addFormattedFieldLines($lines, $relatedFields, $indent.'  ');
                $lines[] = " *{$indent}}{$comma}";
            }
        }
    }

    /**
     * Add formatted field lines for a model/relation
     */
    private function addFormattedFieldLines(array &$lines, array $fields, string $indent, bool $hasMoreContent = false): void
    {
        $fieldNames = array_keys($fields);
        $lastField = end($fieldNames);

        foreach ($fields as $key => $value) {
            $isLastField = ($key === $lastField);
            // Add comma if not last field OR if there's more content coming (nested relations)
            $comma = (! $isLastField || $hasMoreContent) ? ',' : '';

            if (is_string($value)) {
                $lines[] = " *{$indent}\"{$key}\": \"{$value}\"{$comma}";
            } elseif (is_bool($value)) {
                $lines[] = " *{$indent}\"{$key}\": ".($value ? 'true' : 'false')."{$comma}";
            } elseif (is_null($value)) {
                $lines[] = " *{$indent}\"{$key}\": null{$comma}";
            } else {
                $lines[] = " *{$indent}\"{$key}\": {$value}{$comma}";
            }
        }
    }

    /**
     * Get fields from a related model by looking at the relation definition
     */
    private function getRelatedModelFields(string $relationName): array
    {
        // Try to find the model class from the current model's relation
        if (! empty($this->metadata['model_class'])) {
            $modelClass = $this->metadata['model_class'];
            $namespaces = ['App\\Models\\', 'App\\'];

            foreach ($namespaces as $namespace) {
                $fullClass = $namespace.$modelClass;
                if (class_exists($fullClass)) {
                    try {
                        $reflection = new \ReflectionClass($fullClass);
                        if ($reflection->hasMethod($relationName)) {
                            $method = $reflection->getMethod($relationName);
                            $methodBody = file_get_contents($method->getFileName());
                            $startLine = $method->getStartLine();
                            $endLine = $method->getEndLine();
                            $lines = array_slice(explode("\n", $methodBody), $startLine - 1, $endLine - $startLine + 1);
                            $relationCode = implode("\n", $lines);

                            // Extract related model class from relation definition
                            // Pattern: belongsToMany(User::class, ...) or hasMany(Patient::class)
                            if (preg_match('/(?:belongsToMany|hasMany|hasOne|belongsTo|morphMany|morphOne)\s*\(\s*([A-Z][a-zA-Z0-9_]*)::class/', $relationCode, $match)) {
                                $relatedModel = $match[1];

                                return $this->extractModelFields($relatedModel);
                            }
                        }
                    } catch (\Exception $e) {
                        // Fall through to default
                    }
                }
            }
        }

        // Fallback: try to guess model from relation name (singular form)
        $guessedModel = ucfirst(rtrim($relationName, 's'));
        $fields = $this->extractModelFields($guessedModel);
        if (! empty($fields)) {
            return $fields;
        }

        // Default fallback
        return [
            'id' => $this->faker->numberBetween(1, 100),
            'name' => $this->faker->word(),
        ];
    }

    /**
     * Parse eager load relations from with() call content
     * Handles: with('rel1', 'rel2'), with(['rel1', 'rel2']), with('rel1.nested')
     */
    private function parseEagerLoadRelations(string $withContent, string $modelClass): array
    {
        $relations = [];

        // First, remove constraint closures content to avoid picking up select() column names
        // This removes everything inside function($query) { ... } blocks
        $cleanedContent = preg_replace('/function\s*\([^)]*\)\s*\{[^}]*\}/s', '', $withContent);

        // Extract relation names from the cleaned content
        // Supports: 'relation', 'relation.nested', 'relation:col1,col2', 'relation.nested:col1,col2'
        if (preg_match_all("/['\"]([a-zA-Z_][a-zA-Z0-9_.:,]*)['\"]/", $cleanedContent, $matches)) {
            foreach ($matches[1] as $relationPath) {
                // Remove column selection (e.g., 'user:id,name,surname' -> 'user')
                // Also handle nested relations with column selection (e.g., 'posts.comments:id,body')
                $cleanPath = preg_replace('/:[\w,]+/', '', $relationPath);

                // Handle nested relations like 'questionnaires.answers'
                $parts = explode('.', $cleanPath);
                $this->buildNestedRelationStructure($relations, $parts, $modelClass);
            }
        }

        return $relations;
    }

    /**
     * Build nested relation structure recursively
     */
    private function buildNestedRelationStructure(array &$relations, array $parts, string $currentModelClass): void
    {
        if (empty($parts)) {
            return;
        }

        $relationName = array_shift($parts);

        if (! isset($relations[$relationName])) {
            // Get the related model class and its fields
            $relatedModelClass = $this->getRelatedModelClass($currentModelClass, $relationName);
            $relatedFields = $relatedModelClass ? $this->extractModelFields($relatedModelClass) : [];
            $relationType = $this->getRelationType($currentModelClass, $relationName);

            $relations[$relationName] = [
                'model' => $relatedModelClass,
                'type' => $relationType,
                'fields' => $relatedFields,
                'nested' => [],
            ];
        }

        // Recurse for nested relations
        if (! empty($parts) && $relations[$relationName]['model']) {
            $this->buildNestedRelationStructure(
                $relations[$relationName]['nested'],
                $parts,
                $relations[$relationName]['model']
            );
        }
    }

    /**
     * Get the related model class name from a relation method
     */
    private function getRelatedModelClass(string $modelClass, string $relationName): ?string
    {
        $namespaces = ['App\\Models\\', 'App\\'];

        foreach ($namespaces as $namespace) {
            $fullClass = $namespace.$modelClass;
            if (class_exists($fullClass)) {
                try {
                    $reflection = new \ReflectionClass($fullClass);
                    if ($reflection->hasMethod($relationName)) {
                        $method = $reflection->getMethod($relationName);
                        $methodBody = file_get_contents($method->getFileName());
                        $startLine = $method->getStartLine();
                        $endLine = $method->getEndLine();
                        $lines = array_slice(explode("\n", $methodBody), $startLine - 1, $endLine - $startLine + 1);
                        $relationCode = implode("\n", $lines);

                        if (preg_match('/(?:belongsToMany|hasMany|hasOne|belongsTo|morphMany|morphOne|morphToMany)\s*\(\s*([A-Z][a-zA-Z0-9_]*)::class/', $relationCode, $match)) {
                            return $match[1];
                        }
                    }
                } catch (\Exception $e) {
                    // Fall through
                }
            }
        }

        // Fallback: guess from relation name
        return ucfirst(rtrim($relationName, 's'));
    }

    /**
     * Get the relation type (hasMany, belongsTo, etc.)
     */
    private function getRelationType(string $modelClass, string $relationName): string
    {
        $namespaces = ['App\\Models\\', 'App\\'];

        foreach ($namespaces as $namespace) {
            $fullClass = $namespace.$modelClass;
            if (class_exists($fullClass)) {
                try {
                    $reflection = new \ReflectionClass($fullClass);
                    if ($reflection->hasMethod($relationName)) {
                        $method = $reflection->getMethod($relationName);
                        $methodBody = file_get_contents($method->getFileName());
                        $startLine = $method->getStartLine();
                        $endLine = $method->getEndLine();
                        $lines = array_slice(explode("\n", $methodBody), $startLine - 1, $endLine - $startLine + 1);
                        $relationCode = implode("\n", $lines);

                        if (preg_match('/(belongsToMany|hasMany|hasOne|belongsTo|morphMany|morphOne|morphToMany)\s*\(/', $relationCode, $match)) {
                            return $match[1];
                        }
                    }
                } catch (\Exception $e) {
                    // Fall through
                }
            }
        }

        return 'hasMany'; // Default assumption
    }

    /**
     * Format related model fields as JSON string
     */
    private function formatRelatedModelJson(array $fields): string
    {
        $parts = [];
        foreach ($fields as $key => $value) {
            if (is_string($value)) {
                $parts[] = '"'.$key.'": "'.$value.'"';
            } elseif (is_bool($value)) {
                $parts[] = '"'.$key.'": '.($value ? 'true' : 'false');
            } elseif (is_null($value)) {
                $parts[] = '"'.$key.'": null';
            } else {
                $parts[] = '"'.$key.'": '.$value;
            }
        }

        return '{'.implode(', ', $parts).'}';
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
        // First, try to get the actual HTTP method from Laravel's router
        $routeMethod = $this->findRouteHttpMethod();
        if ($routeMethod !== null) {
            return $routeMethod;
        }

        // Fallback: detect from method name patterns
        if (preg_match('/^(store|create|add|attach|sync|assign|register|submit)/i', $this->methodName)) {
            return 'POST';
        }
        if (preg_match('/^(update|edit|patch|modify|change)/i', $this->methodName)) {
            return 'PUT';
        }
        if (preg_match('/^(destroy|delete|remove|detach|unassign|revoke)/i', $this->methodName)) {
            return 'DELETE';
        }

        return 'GET';
    }

    /**
     * Find the actual HTTP method from Laravel's router for this controller method.
     */
    private function findRouteHttpMethod(): ?string
    {
        try {
            $routes = app('router')->getRoutes();

            foreach ($routes as $route) {
                $action = $route->getAction();

                // Check if the route uses this controller and method
                if (isset($action['controller'])) {
                    $controllerAction = $action['controller'];

                    // Format: App\Http\Controllers\UserController@show
                    if (str_contains($controllerAction, '@')) {
                        [$controllerClass, $methodName] = explode('@', $controllerAction);

                        if ($controllerClass === $this->controllerClass && $methodName === $this->methodName) {
                            $methods = $route->methods();
                            // Return first non-HEAD method
                            foreach ($methods as $method) {
                                if ($method !== 'HEAD') {
                                    return strtoupper($method);
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Silent fail - will use fallback detection
        }

        return null;
    }

    private function detectEndpoint(): string
    {
        // Try to find the actual route from Laravel's router
        $realEndpoint = $this->findRouteForControllerMethod();
        if ($realEndpoint !== null) {
            return $realEndpoint;
        }

        // Fallback to guessing based on controller name (legacy behavior)
        $resource = strtolower($this->getResourceNamePlural());

        if (in_array($this->methodName, ['index', 'store'])) {
            return "/api/{$resource}";
        }
        if (in_array($this->methodName, ['show', 'update', 'destroy'])) {
            return "/api/{$resource}/{id}";
        }

        return "/api/{$resource}/".strtolower($this->methodName);
    }

    /**
     * Find the actual route URI for this controller method from Laravel's router.
     *
     * @return string|null The route URI with parameter placeholders, or null if not found
     */
    private function findRouteForControllerMethod(): ?string
    {
        try {
            $routes = app('router')->getRoutes();

            foreach ($routes as $route) {
                $action = $route->getAction();

                // Check if the route uses this controller and method
                if (isset($action['controller'])) {
                    $controllerAction = $action['controller'];

                    // Format: App\Http\Controllers\Api\PatientNoteController@index
                    $expectedAction = $this->controllerClass.'@'.$this->methodName;

                    if ($controllerAction === $expectedAction) {
                        $uri = $route->uri();

                        // Ensure it starts with /
                        if (! str_starts_with($uri, '/')) {
                            $uri = '/'.$uri;
                        }

                        return $uri;
                    }
                }

                // Also check uses array format (for some route definitions)
                if (isset($action['uses']) && is_string($action['uses'])) {
                    $expectedAction = $this->controllerClass.'@'.$this->methodName;

                    if ($action['uses'] === $expectedAction) {
                        $uri = $route->uri();

                        if (! str_starts_with($uri, '/')) {
                            $uri = '/'.$uri;
                        }

                        return $uri;
                    }
                }
            }
        } catch (\Throwable $e) {
            // If we can't access the router (e.g., running outside Laravel context), fall back to guessing
        }

        return null;
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
        // exists:table,column typically refers to an ID (integer)
        if (preg_match('/exists:\w+,id/', $rules) || preg_match('/exists:\w+$/', $rules)) {
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

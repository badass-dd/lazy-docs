<?php

namespace Badass\ControllerPhpDocGenerator\Commands;

use Badass\ControllerPhpDocGenerator\ControllerDocBlockGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use ReflectionMethod;

class GenerateControllerDocsCommand extends Command
{
    protected $signature = 'generate:controller-docs
                            {controller? : Controller class or path}
                            {--method= : Specific method name to generate}
                            {--all : Generate for all controllers}
                            {--overwrite : Process methods that already have PHPDoc (replaces existing)}
                            {--merge : Merge with existing PHPDoc, preserving user-written content}
                            {--dry-run : Preview changes without writing}
                            {--force : Include simple methods}';

    protected $description = 'Generate intelligent PHPDoc for Laravel API controllers. Use --merge to preserve user-written documentation while adding missing tags.';

    public function handle(): int
    {
        $this->newLine();
        $this->info('🚀 Laravel Controller PHPDoc Generator v1.0');
        $this->line('   95% Laravel patterns coverage | Natural language | Scribe-ready');
        $this->newLine();

        // Determine what to process
        if ($this->option('all')) {
            return $this->processAllControllers();
        }

        $controller = $this->argument('controller');
        if (! $controller) {
            $this->error('❌ Please provide controller name or use --all flag');

            return 1;
        }

        $method = $this->option('method');

        if ($method) {
            return $this->processSingleMethod($controller, $method);
        }

        return $this->processSingleController($controller);
    }

    /**
     * Process all controllers
     */
    private function processAllControllers(): int
    {
        $files = $this->getAllControllers();

        if (empty($files)) {
            $this->error('❌ No controller files found.');

            return 1;
        }

        $this->info('📁 Found '.count($files).' controller(s)');
        $this->newLine();

        $stats = [
            'files_processed' => 0,
            'methods_documented' => 0,
            'complex_methods' => 0,
            'skipped' => 0,
        ];

        foreach ($files as $filePath) {
            $result = $this->processController($filePath);
            $stats['files_processed'] += $result['files'];
            $stats['methods_documented'] += $result['methods'];
            $stats['complex_methods'] += $result['complex'];
            $stats['skipped'] += $result['skipped'];
        }

        $this->newLine();
        $this->info('✅ Complete!');
        $this->line('   Files processed: '.$stats['files_processed']);
        $this->line('   Methods documented: '.$stats['methods_documented']);
        $this->line('   Complex methods: '.$stats['complex_methods']);

        if ($stats['skipped'] > 0) {
            $this->line('   Methods skipped: '.$stats['skipped']);
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->warn('⚠️  [DRY RUN] - No files were actually modified');
        }

        $this->newLine();

        return 0;
    }

    /**
     * Process single controller
     */
    private function processSingleController(string $controller): int
    {
        $filePath = $this->resolveControllerPath($controller);

        if (! $filePath || ! file_exists($filePath)) {
            $this->error("❌ Controller not found: {$controller}");

            return 1;
        }

        $result = $this->processController($filePath);

        if ($result['methods'] === 0) {
            $this->warn('⚠️  No methods were documented');

            return 0;
        }

        $this->info('✅ Complete!');
        $this->line("   Methods documented: {$result['methods']}");

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->warn('⚠️  [DRY RUN] - No files were actually modified');
        }

        return 0;
    }

    /**
     * Process single method
     */
    private function processSingleMethod(string $controller, string $methodName): int
    {
        $filePath = $this->resolveControllerPath($controller);

        if (! $filePath || ! file_exists($filePath)) {
            $this->error("❌ Controller not found: {$controller}");

            return 1;
        }

        $className = $this->getClassNameFromFile($filePath);

        if (! $className) {
            $this->error("❌ Could not determine class: {$filePath}");

            return 1;
        }

        try {
            $reflection = new ReflectionClass($className);
        } catch (\Throwable $e) {
            $this->error("❌ Reflection failed: {$className}");

            return 1;
        }

        if (! $reflection->hasMethod($methodName)) {
            $this->error("❌ Method not found: {$className}::{$methodName}");

            return 1;
        }

        $method = $reflection->getMethod($methodName);

        if (! $method->isPublic()) {
            $this->error("❌ Method is not public: {$methodName}");

            return 1;
        }

        $this->line("📄 <fg=cyan>{$className}</>:<fg=cyan>{$methodName}</>");

        $content = File::get($filePath);

        // Check if method already has PHPDoc and neither --overwrite nor --merge is specified
        if (! $this->shouldGenerateDoc($content, $method) && ! $this->option('overwrite') && ! $this->option('merge')) {
            $this->warn('   ⊘ Method already has PHPDoc. Use --overwrite to replace or --merge to add missing tags.');

            return 0;
        }

        $modified = false;
        $mergeMode = (bool) $this->option('merge');

        try {
            $generator = new ControllerDocBlockGenerator($className, $methodName, $mergeMode);
            $phpDoc = $generator->generate();
            $complexity = $generator->metadata['complexity_score'];

            $this->line("   Complexity: <fg=yellow>{$complexity}/30</>");
            $this->line('   <fg=green>Generated PHPDoc:</>', 'v');
            $this->line('');

            // Display generated PHPDoc
            foreach (explode("\n", $phpDoc) as $line) {
                $this->line("   {$line}");
            }

            $this->newLine();

            if (! $this->option('dry-run')) {
                $content = $this->injectPhpDoc($content, $methodName, $phpDoc);
                File::put($filePath, $content);
                $this->line('   <fg=green>✓ Saved to file</>');
                $modified = true;
            } else {
                $this->line('   <fg=yellow>[DRY RUN] - Not written</>');
            }

        } catch (\Throwable $e) {
            $this->error("❌ Error: {$e->getMessage()}");

            return 1;
        }

        $this->newLine();
        $this->info('✅ Complete!');

        if ($this->option('dry-run')) {
            $this->warn('⚠️  [DRY RUN] - No files were actually modified');
        }

        return 0;
    }

    /**
     * Process controller file
     */
    private function processController(string $filePath): array
    {
        $className = $this->getClassNameFromFile($filePath);

        if (! $className) {
            $this->warn("⚠️  Could not determine class: {$filePath}");

            return ['files' => 0, 'methods' => 0, 'complex' => 0, 'skipped' => 0];
        }

        try {
            $reflection = new ReflectionClass($className);
        } catch (\Throwable $e) {
            $this->warn("⚠️  Reflection failed: {$className}");

            return ['files' => 0, 'methods' => 0, 'complex' => 0, 'skipped' => 0];
        }

        $this->line("📄 <fg=cyan>{$className}</>");

        $content = File::get($filePath);
        $modified = false;
        $methodCount = 0;
        $complexCount = 0;
        $skipped = 0;

        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            if ($this->shouldSkipMethod($method, $className)) {
                continue;
            }

            if (! $this->shouldGenerateDoc($content, $method) && ! $this->option('overwrite') && ! $this->option('merge')) {
                $this->line("   ⊘ <fg=gray>{$method->getName()}</> (already documented)");
                $skipped++;

                continue;
            }

            $mergeMode = (bool) $this->option('merge');
            $generator = new ControllerDocBlockGenerator($className, $method->getName(), $mergeMode);
            $complexity = $generator->metadata['complexity_score'];

            if ($complexity < 5 && ! $this->option('force')) {
                $this->line("   ⏭️  <fg=gray>{$method->getName()}</> (simple, complexity: {$complexity})");
                $skipped++;

                continue;
            }

            $phpDoc = $generator->generate();
            $content = $this->injectPhpDoc($content, $method->getName(), $phpDoc);
            $modified = true;
            $methodCount++;

            $icon = $complexity > 15 ? '🔥' : '⚡';
            $this->line("   {$icon} <fg=green>{$method->getName()}</> (complexity: {$complexity})");

            if ($complexity > 10) {
                $complexCount++;
            }
        }

        if ($modified && ! $this->option('dry-run')) {
            File::put($filePath, $content);
            $this->line('   <fg=green>✓ Saved</>');
        }

        if ($modified && $this->option('dry-run')) {
            $this->line('   <fg=yellow>[DRY RUN]</>');
        }

        return [
            'files' => $modified ? 1 : 0,
            'methods' => $methodCount,
            'complex' => $complexCount,
            'skipped' => $skipped,
        ];
    }

    /**
     * Get all controller files
     */
    private function getAllControllers(): array
    {
        $path = app_path('Http/Controllers');

        if (! is_dir($path)) {
            return [];
        }

        return collect(File::allFiles($path))
            ->filter(fn ($file) => $file->getExtension() === 'php')
            ->filter(fn ($file) => str_contains($file->getFilename(), 'Controller'))
            ->map(fn ($file) => $file->getRealPath())
            ->values()
            ->all();
    }

    /**
     * Resolve controller path from name
     */
    private function resolveControllerPath(string $controller): ?string
    {
        // Try direct path
        if (file_exists($controller)) {
            return $controller;
        }

        // Try as file in app/Http/Controllers
        $filePath = app_path('Http/Controllers/'.str_replace('\\', '/', $controller).'.php');
        if (file_exists($filePath)) {
            return $filePath;
        }

        // Try with Controller suffix
        $filePath = app_path('Http/Controllers/'.str_replace('\\', '/', $controller).'Controller.php');
        if (file_exists($filePath)) {
            return $filePath;
        }

        return null;
    }

    /**
     * Check if should skip method
     */
    private function shouldSkipMethod(ReflectionMethod $method, string $controllerClass): bool
    {
        $name = $method->getName();

        if (str_starts_with($name, '__')) {
            return true;
        }

        if ($method->getDeclaringClass()->getName() !== $controllerClass) {
            return true;
        }

        $excluded = config('phpdoc-generator.exclude_methods', []);

        return in_array($name, $excluded);
    }

    /**
     * Check if should generate doc
     */
    private function shouldGenerateDoc(string $content, ReflectionMethod $method): bool
    {
        // Pattern to check if there's a PHPDoc immediately before the method declaration
        // The PHPDoc must be followed only by whitespace before the method, not by other code/methods
        $pattern = '/\/\*\*(?:(?!\*\/).)*\*\/\s*(?:public|protected|private)(?:\s+(?:static|abstract))?\s+function\s+'.preg_quote($method->getName(), '/').'\s*\(/s';

        return ! preg_match($pattern, $content);
    }

    /**
     * Inject PHPDoc into content
     *
     * @param  string  $content  File content
     * @param  string  $methodName  Method name
     * @param  string  $phpDoc  New PHPDoc to inject
     */
    private function injectPhpDoc(string $content, string $methodName, string $phpDoc): string
    {
        // Pattern to find the method declaration
        $methodPattern = '/(?:public|protected|private)(?:\s+(?:static|abstract))?\s+function\s+'.preg_quote($methodName, '/').'\s*\(/';

        if (! preg_match($methodPattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            return $content;
        }

        $methodOffset = $matches[0][1];

        // Look backwards from the method to find the immediate PHPDoc block (if any)
        // Get the content before the method (max 2000 chars should be enough)
        $searchStart = max(0, $methodOffset - 2000);
        $beforeMethod = substr($content, $searchStart, $methodOffset - $searchStart);

        // Find the last PHPDoc block that immediately precedes the method
        // Use strrpos to find the LAST occurrence of /**
        $lastDocStart = strrpos($beforeMethod, '/**');
        
        if ($lastDocStart !== false) {
            // Find the closing */ after this /**
            $docContent = substr($beforeMethod, $lastDocStart);
            if (preg_match('/^\/\*\*[\s\S]*?\*\//', $docContent, $docMatch)) {
                $existingDocBlock = $docMatch[0];
                $docEndInBeforeMethod = $lastDocStart + strlen($existingDocBlock);
                
                // Check if there's only whitespace between the doc block and the method
                $gapContent = substr($beforeMethod, $docEndInBeforeMethod);
                
                if (preg_match('/^\s*$/', $gapContent)) {
                    // This is the method's PHPDoc - calculate absolute positions
                    $absoluteDocStart = $searchStart + $lastDocStart;

                    // Determine the indentation from the existing doc block
                    // Use [ \t]* instead of \s* to avoid capturing newlines
                    $indentation = '';
                    if (preg_match('/\n([ \t]*)\/\*\*/', substr($content, max(0, $absoluteDocStart - 50), 50 + 3), $indentMatch)) {
                        $indentation = $indentMatch[1];
                    } else {
                        $indentation = '    '; // Default indentation
                    }

                    if ($this->option('merge')) {
                        // Merge mode: preserve user content, add missing generated content
                        $mergedDoc = $this->mergePhpDocs($existingDocBlock, $phpDoc);
                        // Add proper indentation to each line
                        $mergedDoc = $this->indentPhpDoc($mergedDoc, $indentation);

                        // Replace: [before doc] + [merged doc] + [newline + indent] + [from method onwards]
                        $newContent = substr($content, 0, $absoluteDocStart)
                            .$mergedDoc."\n".$indentation
                            .substr($content, $methodOffset);

                        return $newContent;
                    }

                    // Overwrite mode: Replace existing PHPDoc
                    $indentedPhpDoc = $this->indentPhpDoc($phpDoc, $indentation);
                    $newContent = substr($content, 0, $absoluteDocStart)
                        .$indentedPhpDoc."\n".$indentation
                        .substr($content, $methodOffset);

                    return $newContent;
                }
            }
        }

        // No existing PHPDoc immediately before the method, insert new one
        // Determine indentation from the method line
        $indentation = '    '; // Default
        $lineStart = strrpos(substr($content, 0, $methodOffset), "\n");
        if ($lineStart !== false) {
            $methodLine = substr($content, $lineStart + 1, $methodOffset - $lineStart - 1);
            if (preg_match('/^([ \t]*)/', $methodLine, $indentMatch)) {
                $indentation = $indentMatch[1];
            }
        }
        
        $indentedPhpDoc = $this->indentPhpDoc($phpDoc, $indentation);
        return substr($content, 0, $methodOffset).$indentedPhpDoc."\n".$indentation.substr($content, $methodOffset);
    }

    /**
     * Add indentation to each line of a PHPDoc block (except the first line)
     */
    private function indentPhpDoc(string $phpDoc, string $indentation): string
    {
        $lines = explode("\n", $phpDoc);
        $indentedLines = [];
        foreach ($lines as $index => $line) {
            // First line already has indentation from the position in the file
            if ($index === 0) {
                $indentedLines[] = $line;
            } else {
                $indentedLines[] = $indentation.$line;
            }
        }

        return implode("\n", $indentedLines);
    }

    /**
     * Merge existing PHPDoc with generated PHPDoc
     * Preserves user-written content, adds missing tags from generated doc
     */
    private function mergePhpDocs(string $existingDoc, string $generatedDoc): string
    {
        // Parse existing tags
        $existingTags = $this->parsePhpDocTags($existingDoc);
        $generatedTags = $this->parsePhpDocTags($generatedDoc);

        // Start building merged doc
        $lines = ['/**'];

        // Preserve existing title and description
        if (! empty($existingTags['title'])) {
            $lines[] = ' * '.$existingTags['title'];
        } elseif (! empty($generatedTags['title'])) {
            $lines[] = ' * '.$generatedTags['title'];
        }

        $lines[] = ' *';

        if (! empty($existingTags['description'])) {
            foreach (explode("\n", $existingTags['description']) as $line) {
                $lines[] = ' * '.$line;
            }
            $lines[] = ' *';
        } elseif (! empty($generatedTags['description'])) {
            foreach (explode("\n", $generatedTags['description']) as $line) {
                $lines[] = ' * '.$line;
            }
            $lines[] = ' *';
        }

        // Preserve existing @group or use generated
        $group = $existingTags['group'] ?? $generatedTags['group'] ?? null;
        if ($group) {
            $lines[] = ' * @group '.$group;
            $lines[] = ' *';
        }

        // Handle @authenticated
        if (! empty($existingTags['authenticated']) || ! empty($generatedTags['authenticated'])) {
            $lines[] = ' * @authenticated';
            $lines[] = ' *';
        }

        // Handle @api tag (use generated if exists, otherwise existing)
        $api = $existingTags['api'] ?? $generatedTags['api'] ?? null;
        if ($api) {
            $lines[] = ' * @api '.$api;
            $lines[] = ' *';
        }

        // Merge body/query params - prefer existing, add missing from generated
        $params = $this->mergeParams(
            $existingTags['bodyParam'] ?? [],
            $generatedTags['bodyParam'] ?? []
        );
        foreach ($params as $param) {
            $lines[] = ' * @bodyParam '.$param;
        }

        $queryParams = $this->mergeParams(
            $existingTags['queryParam'] ?? [],
            $generatedTags['queryParam'] ?? []
        );
        foreach ($queryParams as $param) {
            $lines[] = ' * @queryParam '.$param;
        }

        if (! empty($params) || ! empty($queryParams)) {
            $lines[] = ' *';
        }

        // Merge responses - prefer existing, add missing status codes from generated
        $responses = $this->mergeResponses(
            $existingTags['response'] ?? [],
            $generatedTags['response'] ?? []
        );
        foreach ($responses as $statusCode => $body) {
            $lines[] = " * @response {$statusCode} {";
            // Add each line of the body with proper formatting
            $bodyLines = explode("\n", trim($body, "{}"));
            foreach ($bodyLines as $bodyLine) {
                $bodyLine = trim($bodyLine);
                if (! empty($bodyLine)) {
                    $lines[] = " *   {$bodyLine}";
                }
            }
            $lines[] = ' * }';
            $lines[] = ' *';
        }

        $lines[] = ' */';

        return implode("\n", $lines);
    }

    /**
     * Parse PHPDoc tags from a doc block
     */
    private function parsePhpDocTags(string $docBlock): array
    {
        $tags = [];

        // Extract title (first non-tag line after /**)
        if (preg_match('/\/\*\*\s*\n\s*\*\s*([^@\n][^\n]*)/s', $docBlock, $match)) {
            $title = trim($match[1]);
            if (! empty($title) && $title !== '*') {
                $tags['title'] = $title;
            }
        }

        // Extract description - everything between title and first @tag
        // Find content between title line and first @tag line
        if (preg_match('/\/\*\*\s*\n\s*\*\s*[^@\n][^\n]*\n([\s\S]*?)(?=\s*\*\s*@|\s*\*\/)/s', $docBlock, $match)) {
            // Clean: remove * prefixes and filter out empty lines at start/end
            $descLines = [];
            foreach (explode("\n", $match[1]) as $line) {
                $cleanLine = preg_replace('/^\s*\*\s?/', '', $line);
                // Stop if we hit a line starting with @ (shouldn't happen with lookahead, but safety)
                if (preg_match('/^\s*@/', $cleanLine)) {
                    break;
                }
                $descLines[] = $cleanLine;
            }
            $description = trim(implode("\n", $descLines));
            if (! empty($description)) {
                $tags['description'] = $description;
            }
        }

        // Extract @group
        if (preg_match('/@group\s+(.+)$/m', $docBlock, $match)) {
            $tags['group'] = trim($match[1]);
        }

        // Extract @authenticated
        if (preg_match('/@authenticated/', $docBlock)) {
            $tags['authenticated'] = true;
        }

        // Extract @api
        if (preg_match('/@api\s+(.+)$/m', $docBlock, $match)) {
            $tags['api'] = trim($match[1]);
        }

        // Extract @bodyParam
        if (preg_match_all('/@bodyParam\s+(.+)$/m', $docBlock, $matches)) {
            $tags['bodyParam'] = $matches[1];
        }

        // Extract @queryParam
        if (preg_match_all('/@queryParam\s+(.+)$/m', $docBlock, $matches)) {
            $tags['queryParam'] = $matches[1];
        }

        // Extract @response with their full content (including multi-line JSON)
        // Pattern matches @response STATUS_CODE followed by { or [ and captures until closing } or ]
        if (preg_match_all('/@response\s+(\d{3})\s*(\{[\s\S]*?\n\s*\*\s*\}|\[[\s\S]*?\n\s*\*\s*\])/m', $docBlock, $matches, PREG_SET_ORDER)) {
            $tags['response'] = [];
            foreach ($matches as $match) {
                $statusCode = $match[1];
                $body = $match[2];
                // Clean the body - remove * prefixes from each line
                $cleanBody = preg_replace('/^\s*\*\s?/m', '', $body);
                $tags['response'][$statusCode] = trim($cleanBody);
            }
        }

        return $tags;
    }

    /**
     * Merge parameter lists - keep existing, add missing from generated
     */
    private function mergeParams(array $existing, array $generated): array
    {
        $existingFields = [];
        foreach ($existing as $param) {
            if (preg_match('/^(\S+)/', $param, $match)) {
                $existingFields[$match[1]] = $param;
            }
        }

        foreach ($generated as $param) {
            if (preg_match('/^(\S+)/', $param, $match)) {
                $field = $match[1];
                if (! isset($existingFields[$field])) {
                    $existingFields[$field] = $param;
                }
            }
        }

        return array_values($existingFields);
    }

    /**
     * Merge response lists - keep existing status codes, add missing from generated
     * Now works with associative arrays keyed by status code
     */
    private function mergeResponses(array $existing, array $generated): array
    {
        $merged = [];

        // Add all existing responses
        foreach ($existing as $statusCode => $body) {
            $merged[$statusCode] = $body;
        }

        // Add generated responses only if status code doesn't exist
        foreach ($generated as $statusCode => $body) {
            if (! isset($merged[$statusCode])) {
                $merged[$statusCode] = $body;
            }
        }

        // Sort by status code
        ksort($merged);

        return $merged;
    }

    /**
     * Get class name from file
     */
    private function getClassNameFromFile(string $filePath): ?string
    {
        $content = File::get($filePath);

        if (preg_match('/namespace\s+([\w\\\\]+);/', $content, $nsMatch) &&
            preg_match('/class\s+(\w+)/', $content, $classMatch)) {
            return $nsMatch[1].'\\'.$classMatch[1];
        }

        return null;
    }
}

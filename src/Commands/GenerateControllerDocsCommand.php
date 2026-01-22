<?php

namespace Badass\ControllerPhpDocGenerator\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Badass\ControllerPhpDocGenerator\ControllerDocBlockGenerator;
use ReflectionClass;
use ReflectionMethod;

class GenerateControllerDocsCommand extends Command
{
    protected $signature = 'generate:controller-docs
                            {controller? : Controller class or path}
                            {--method= : Specific method name to generate}
                            {--all : Generate for all controllers}
                            {--overwrite : Overwrite existing PHPDoc blocks}
                            {--dry-run : Preview changes without writing}
                            {--force : Include simple methods}';

    protected $description = 'Generate intelligent PHPDoc for Laravel API controllers';

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
        if (!$controller) {
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

        $this->info("📁 Found " . count($files) . " controller(s)");
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
        $this->line('   Files processed: ' . $stats['files_processed']);
        $this->line('   Methods documented: ' . $stats['methods_documented']);
        $this->line('   Complex methods: ' . $stats['complex_methods']);

        if ($stats['skipped'] > 0) {
            $this->line('   Methods skipped: ' . $stats['skipped']);
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

        if (!$filePath || !file_exists($filePath)) {
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

        if (!$filePath || !file_exists($filePath)) {
            $this->error("❌ Controller not found: {$controller}");
            return 1;
        }

        $className = $this->getClassNameFromFile($filePath);

        if (!$className) {
            $this->error("❌ Could not determine class: {$filePath}");
            return 1;
        }

        try {
            $reflection = new ReflectionClass($className);
        } catch (\Throwable $e) {
            $this->error("❌ Reflection failed: {$className}");
            return 1;
        }

        if (!$reflection->hasMethod($methodName)) {
            $this->error("❌ Method not found: {$className}::{$methodName}");
            return 1;
        }

        $method = $reflection->getMethod($methodName);

        if (!$method->isPublic()) {
            $this->error("❌ Method is not public: {$methodName}");
            return 1;
        }

        $this->line("📄 <fg=cyan>{$className}</>:<fg=cyan>{$methodName}</>");

        $content = File::get($filePath);
        $modified = false;

        try {
            $generator = new ControllerDocBlockGenerator($className, $methodName);
            $phpDoc = $generator->generate();
            $complexity = $generator->metadata['complexity_score'];

            $this->line("   Complexity: <fg=yellow>{$complexity}/30</>");
            $this->line("   <fg=green>Generated PHPDoc:</>", 'v');
            $this->line('');

            // Display generated PHPDoc
            foreach (explode("\n", $phpDoc) as $line) {
                $this->line("   {$line}");
            }

            $this->newLine();

            if (!$this->option('dry-run')) {
                $content = $this->injectPhpDoc($content, $methodName, $phpDoc);
                File::put($filePath, $content);
                $this->line("   <fg=green>✓ Saved to file</>");
                $modified = true;
            } else {
                $this->line("   <fg=yellow>[DRY RUN] - Not written</>");
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

        if (!$className) {
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

            if (!$this->shouldGenerateDoc($content, $method) && !$this->option('overwrite')) {
                $this->line("   ⊘ <fg=gray>{$method->getName()}</> (already documented)");
                $skipped++;
                continue;
            }

            $generator = new ControllerDocBlockGenerator($className, $method->getName());
            $complexity = $generator->metadata['complexity_score'];

            if ($complexity < 5 && !$this->option('force')) {
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

        if ($modified && !$this->option('dry-run')) {
            File::put($filePath, $content);
            $this->line("   <fg=green>✓ Saved</>");
        }

        if ($modified && $this->option('dry-run')) {
            $this->line("   <fg=yellow>[DRY RUN]</>");
        }

        return [
            'files' => $modified ? 1 : 0,
            'methods' => $methodCount,
            'complex' => $complexCount,
            'skipped' => $skipped
        ];
    }

    /**
     * Get all controller files
     */
    private function getAllControllers(): array
    {
        $path = app_path('Http/Controllers');

        if (!is_dir($path)) {
            return [];
        }

        return collect(File::allFiles($path))
            ->filter(fn($file) => $file->getExtension() === 'php')
            ->filter(fn($file) => str_contains($file->getFilename(), 'Controller'))
            ->map(fn($file) => $file->getRealPath())
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
        $filePath = app_path('Http/Controllers/' . str_replace('\\', '/', $controller) . '.php');
        if (file_exists($filePath)) {
            return $filePath;
        }

        // Try with Controller suffix
        $filePath = app_path('Http/Controllers/' . str_replace('\\', '/', $controller) . 'Controller.php');
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
        $pattern = '/\/\*\*.*?\*\/\s+(?:public|protected|private).*?' . preg_quote($method->getName()) . '\s*\(/s';
        return !preg_match($pattern, $content);
    }

    /**
     * Inject PHPDoc into content
     */
    private function injectPhpDoc(string $content, string $methodName, string $phpDoc): string
    {
        $pattern = '/(?=(?:public|protected|private)(?:\s+(?:static|abstract))?\s+(?:function\s+)?' . preg_quote($methodName) . '\s*\()/';

        if (!preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            return $content;
        }

        $offset = $matches[0][1];
        $beforeMethod = substr($content, max(0, $offset - 500), $offset);

        if (preg_match('/\/\*\*.*?\*\/\s*$/is', $beforeMethod)) {
            $newContent = preg_replace(
                '/\/\*\*.*?(?=(?:public|protected|private)(?:\s+(?:static|abstract))?\s+(?:function\s+)?' . preg_quote($methodName) . '\s*\()/is',
                $phpDoc . "\n    ",
                $content
            );
            return $newContent !== null ? $newContent : $content;
        }

        return substr_replace($content, $phpDoc . "\n    ", $offset, 0);
    }

    /**
     * Get class name from file
     */
    private function getClassNameFromFile(string $filePath): ?string
    {
        $content = File::get($filePath);

        if (preg_match('/namespace\s+([\w\\\\]+);/', $content, $nsMatch) &&
            preg_match('/class\s+(\w+)/', $content, $classMatch)) {
            return $nsMatch[1] . '\\' . $classMatch[1];
        }

        return null;
    }
}

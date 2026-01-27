# Lazy Docs - Laravel PHPDoc Generator

🚀 **Automatically generate intelligent, natural-language PHPDoc for your Laravel API controllers** - perfectly compatible with **Laravel Scribe**

## Features

✨ **95% Laravel Pattern Coverage**
- CRUD operations (index, show, store, update, destroy)
- Transactions with rollback detection
- Queue jobs and async operations
- Caching strategies
- Complex query builders with filters
- Relations and eager loading
- Soft deletes
- Authorization checks
- Rate limiting
- Validation rules parsing
- Exception/error handling
- Search, filter, pagination patterns
- Repository pattern support
- Custom methods with intelligent analysis

🎯 **Natural Language Documentation**
- Reads like a human wrote it
- Fully compatible with **Laravel Scribe**
- Multi-step process descriptions
- Contextual details based on code analysis
- Implementation notes for complex methods

📊 **Smart Detection**
- Reflection-based method analysis
- FormRequest rule extraction
- Exception to HTTP status mapping
- Relation detection
- Complexity scoring

🔧 **Production Ready**
- No external dependencies beyond Laravel
- Works with Laravel 10+ & 11
- Single command or method-specific generation
- Dry-run preview mode
- Overwrite existing PHPDoc option

## Installation

```bash
composer require badass-dd/lazy-docs
```

## Quick Start

### Generate for all controllers
```bash
php artisan generate:controller-docs --all
```

### Generate for specific controller
```bash
php artisan generate:controller-docs OrderController
```

### Generate for specific method only
```bash
php artisan generate:controller-docs OrderController --method=store
```

### Preview first (dry-run)
```bash
php artisan generate:controller-docs --all --dry-run
```

### Replace existing PHPDoc
```bash
php artisan generate:controller-docs --all --overwrite
```

## Command Syntax

### Full Signature
```bash
php artisan generate:controller-docs 
    {controller?}           # Controller name or path (optional)
    {--method=}             # Specific method name (optional)
    {--all}                 # Generate for all controllers
    {--overwrite}           # Replace existing PHPDoc
    {--dry-run}             # Preview without writing
    {--force}               # Include simple methods
```

### Examples

```bash
# Generate for single controller
php artisan generate:controller-docs OrderController

# Generate for single method
php artisan generate:controller-docs OrderController --method=store

# Generate all with preview
php artisan generate:controller-docs --all --dry-run

# Overwrite existing documentation
php artisan generate:controller-docs OrderController --overwrite

# Include simple methods
php artisan generate:controller-docs --all --force
```

## Output Example

### Before
```php
public function store(CreateOrderRequest $request): JsonResponse
{
    return DB::transaction(function () use ($request) {
        $order = Order::create($request->validated());
        OrderCreated::dispatch($order);
        return response()->json($order, 201);
    });
}
```

### After (Auto-Generated)
```php
/**
 * Create a new order.
 * 
 * This operation includes request validation, database transaction with automatic 
 * rollback, and asynchronous background jobs.
 *
 * @bodyParam customer_id integer required Customer ID. Example: 1
 * @bodyParam total_amount number required Order total in USD. Example: 99.99
 * @bodyParam items array required Array of order items. Example: []
 *
 * @response 201 {
 *   "id": 1,
 *   "customer_id": 1,
 *   "total_amount": 99.99,
 *   "status": "pending",
 *   "message": "Order created successfully"
 * }
 *
 * @response 422 {
 *   "message": "Validation failed",
 *   "errors": {
 *     "customer_id": ["The customer_id field is required"]
 *   }
 * }
 *
 * @response 403 {
 *   "message": "This action is unauthorized"
 * }
 *
 * ⚠️ This operation is executed within a database transaction with automatic rollback on error.
 * 🔄 Background jobs are dispatched asynchronously and may not complete immediately.
 */
public function store(CreateOrderRequest $request): JsonResponse
```

## Pattern Recognition

The generator automatically detects and documents:

### CRUD Operations
```php
public function index() { }         // "Retrieve a list of..."
public function show($id) { }       // "Retrieve a specific..."
public function store() { }         // "Create a new..."
public function update() { }        // "Update an existing..."
public function destroy() { }       // "Delete a..."
```

### Transactions
```php
DB::transaction(function () {
    Order::create(...);
    Payment::create(...);
});
// → "executed within a database transaction with automatic rollback"
```

### Queue Jobs
```php
SendEmail::dispatch($user);
NotifyAdmin::dispatch($data);
// → "dispatching asynchronous background jobs"
```

### Caching
```php
Cache::remember('orders', 60, fn() => Order::all());
// → "results cached for 60 minutes"
```

### Complex Queries
```php
Order::with(['customer', 'items'])
    ->when($request->status, fn($q) => $q->where('status', $request->status))
    ->orderBy($request->sort ?? 'created_at')
    ->paginate($request->per_page ?? 15);
// → "with advanced filtering, sorting, and pagination including related resources"
```

### Authorization
```php
$this->authorize('update', $order);
// → "requiring proper authorization" + @response 403
```

### Rate Limiting
```php
$this->throttle('update-order');
// → @response 429 Too Many Requests
```

### Soft Deletes
```php
Order::withTrashed()->find($id);
// → "respecting soft-deleted records"
```

### Form Requests with Validation
```php
class CreateOrderRequest extends FormRequest
{
    public function rules()
    {
        return [
            'customer_id' => 'required|integer|exists:customers,id',
            'total_amount' => 'required|numeric|min:0.01',
            'items' => 'required|array|min:1',
        ];
    }
}
// → Auto-generates @bodyParam with validation rules
```

## Configuration

Publish config:
```bash
php artisan vendor:publish --provider="Badass\ControllerPhpDocGenerator\ControllerPhpDocGeneratorServiceProvider" --tag=phpdoc-generator-config
```

Edit `config/phpdoc-generator.php`:

```php
return [
    'complexity_threshold' => 5,  // Skip simple methods
    
    'exclude_methods' => [
        '__construct',
        'middleware',
        'your_method_name',
    ],
    
    'examples' => [
        'email' => 'user@example.com',
        'id' => '1',
        // Add custom field examples
    ],
    
    'include_implementation_notes' => true,
    'include_authorization_errors' => true,
    'include_rate_limit_info' => true,
];
```

## Integration with Scribe

After generating PHPDoc:

```bash
# Install Scribe
composer require knuckleswtf/scribe

# Generate documentation
php artisan scribe:generate

# View in browser
open docs/api.html
```

The generated PHPDoc is 100% compatible with Scribe's expectations!

## Performance

- **Single controller:** ~50ms
- **10 controllers:** ~500ms
- **50+ controllers:** <5 seconds

Uses pure PHP Reflection - no external API calls or network requests.

## Complexity Scoring

Methods are scored 1-30 based on:
- Lines of code
- Nesting levels
- Database transactions
- Queue jobs
- Authorization checks
- Validation logic
- Exception handling
- Relation operations

Methods scoring below threshold are skipped (use `--force` to include).

## Troubleshooting

### Q: Command not found?
**A:** Reinstall composer files:
```bash
composer dump-autoload
```

### Q: No controllers found?
**A:** Make sure controllers exist in `app/Http/Controllers/` and filename matches class name.

### Q: Method not being documented?
**A:** Check:
1. Method is public
2. Not a magic method (__construct, etc)
3. Not in excluded list (config)
4. Complexity > 5 (use --force to include simple)

### Q: Want specific method only?
**A:** Use --method option:
```bash
php artisan generate:controller-docs OrderController --method=store
```

## File Structure

```
src/
├── ControllerDocBlockGenerator.php       (Core logic - 95% pattern coverage)
├── ControllerPhpDocGeneratorServiceProvider.php
└── Commands/
    └── GenerateControllerDocsCommand.php (Artisan command with controller/method support)

config/
└── phpdoc-generator.php                  (Configuration)
```

## What Gets Generated

For each method, you get:

✅ **Intelligent Description**
- Based on method name and code analysis
- Natural language
- Contextual details

✅ **Parameter Documentation**
- `@bodyParam` for FormRequest inputs
- `@urlParam` for route parameters
- Validation rules as constraints
- Realistic examples

✅ **Response Examples**
- Success response (200, 201, 204)
- Validation errors (422)
- Authorization errors (403)
- Rate limit errors (429)
- Custom exceptions (400, 402, 409, etc)

✅ **Implementation Notes**
- Transaction behavior
- Async operations
- Caching behavior
- Authorization requirements
- Rate limiting info
- Soft delete support

## Requirements

- PHP 8.1+
- Laravel 10+ or 11+

## License

MIT

---

**Never write PHPDoc manually again! 🎉**

For support or issues: [GitHub Issues](https://github.com/badass-dd/lazy-docs)

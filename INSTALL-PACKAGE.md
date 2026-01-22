# Package Installation & Setup Guide

## 📁 Directory Structure

Crea questa struttura per il package:

```
lazy-docs/
├── src/
│   ├── ControllerPhpDocGeneratorServiceProvider.php
│   ├── ControllerDocBlockGenerator.php
│   └── Commands/
│       └── GenerateControllerDocsCommand.php
├── config/
│   └── phpdoc-generator.php
├── composer.json
├── README.md
└── LICENSE
```

---

## 🚀 Installation Steps

### Step 1: Install via Composer

In your Laravel project:

```bash
composer require-dd/lazy-docs
```

The package auto-discovers in Laravel 5.5+.

### Step 2: Verify Installation

Check if command is available:

```bash
php artisan generate:controller-docs --help
```

Output should show:
```
Description:
  Generate intelligent PHPDoc for Laravel API controllers

Usage:
  generate:controller-docs [options] [--] [<controller>]
```

### Step 3: (Optional) Publish Config

Customize settings:

```bash
php artisan vendor:publish --provider="Badass-dd\ControllerPhpDocGenerator\ControllerPhpDocGeneratorServiceProvider" --tag=phpdoc-generator-config
```

This creates `config/phpdoc-generator.php` where you can customize:
- Complexity threshold
- Excluded methods
- Custom examples for fields
- Feature toggles

---

## 📖 Usage

### Generate for All Controllers

```bash
php artisan generate:controller-docs --all
```

**Output:**
```
🚀 Laravel Controller PHPDoc Generator v1.0
   95% Laravel patterns coverage | Natural language | Scribe-ready

📁 Found 12 controller(s)

📄 App\Http\Controllers\OrderController
   🔥 index (complexity: 12)
   ⚡ store (complexity: 18)
   ⚡ update (complexity: 15)
   ⊘ show (already documented)
   ⏭️  destroy (simple, complexity: 3)
   ✓ Saved

📄 App\Http\Controllers\UserController
   ⚡ store (complexity: 8)
   ✓ Saved

✅ Complete!
   Files processed: 2
   Methods documented: 5
   Complex methods: 2
```

### Generate for Specific Controller

```bash
php artisan generate:controller-docs OrderController
```

Or with full path:

```bash
php artisan generate:controller-docs "App\Http\Controllers\Api\Orders\OrderController"
```

### Generate for Specific Method

**⭐ NEW FEATURE: Method-specific generation**

```bash
php artisan generate:controller-docs OrderController --method=store
```

Shows full PHPDoc inline with complexity score, then saves to file:

```
📄 App\Http\Controllers\OrderController:store
   Complexity: 18/30
   Generated PHPDoc:
   
   /**
    * Create a new order.
    * 
    * This operation includes request validation, database transaction...
    * @bodyParam customer_id integer required Customer ID
    * @response 201 {...}
    * ...
    */
   
   ✓ Saved to file

✅ Complete!
```

### Preview First (Dry-Run)

See changes without writing files:

```bash
php artisan generate:controller-docs --all --dry-run
```

Output shows what would be generated but files aren't modified.

### Overwrite Existing PHPDoc

By default, skips methods that already have PHPDoc. To replace:

```bash
php artisan generate:controller-docs --all --overwrite
```

### Include Simple Methods

By default, simple methods (complexity < 5) are skipped. To include:

```bash
php artisan generate:controller-docs --all --force
```

### Combine Options

```bash
# Preview single method before applying
php artisan generate:controller-docs OrderController --method=processPayment --dry-run

# Regenerate all with overwrite
php artisan generate:controller-docs --all --overwrite --dry-run

# Force simple methods with preview
php artisan generate:controller-docs --all --force --dry-run
```

---

## 🎯 Example Workflows

### Workflow 1: Generate New Controller Documentation

```bash
# 1. Generate for specific controller
php artisan generate:controller-docs OrderController

# 2. Check the result
git diff app/Http/Controllers/OrderController.php

# 3. Good? Commit!
git add app/Http/Controllers/OrderController.php
git commit -m "docs: auto-generate PHPDoc for OrderController"
```

### Workflow 2: Generate Single Method for Quick Testing

```bash
# 1. Create method
# public function processPayment(PaymentRequest $request) { ... }

# 2. Generate documentation for this method only
php artisan generate:controller-docs PaymentController --method=processPayment

# 3. Check the generated PHPDoc
# 4. Adjust if needed

# 5. Generate Scribe docs
php artisan scribe:generate

# 6. View in browser
open docs/api.html
```

### Workflow 3: Bulk Generate + Integrate with Scribe

```bash
# 1. Generate PHPDoc for all
php artisan generate:controller-docs --all

# 2. Preview what was generated
git diff --stat

# 3. Commit
git add app/Http/Controllers/
git commit -m "docs: auto-generate PHPDoc for all controllers"

# 4. Install Scribe if not installed
composer require knuckleswtf/scribe

# 5. Generate documentation
php artisan scribe:generate

# 6. View docs
open docs/api.html

# 7. Deploy or share docs
```

### Workflow 4: Update Documentation After Code Change

```bash
# 1. Modify OrderController::store method
# public function store(UpdatedRequest $request) { ... new logic ... }

# 2. Preview new PHPDoc
php artisan generate:controller-docs OrderController --method=store --dry-run

# 3. Apply it
php artisan generate:controller-docs OrderController --method=store

# 4. Update Scribe docs
php artisan scribe:generate
```

---

## ⚙️ Configuration

### Default Config Values

```php
// config/phpdoc-generator.php

return [
    // Minimum complexity score to generate (0-30)
    'complexity_threshold' => 5,

    // Methods to never document
    'exclude_methods' => [
        '__construct',
        '__invoke',
        'middleware',
        'validate',
        'authorize',
    ],

    // Example values for fields
    'examples' => [
        'id' => '1',
        'email' => 'user@example.com',
        'name' => 'John Doe',
        'price' => '99.99',
        // ... more examples
    ],

    // Enable implementation notes
    'include_implementation_notes' => true,
];
```

### Customize Examples

Edit `config/phpdoc-generator.php`:

```php
'examples' => [
    'customer_id' => '12345',
    'order_number' => 'ORD-2024-001',
    'stripe_token' => 'tok_visa',
    'webhook_url' => 'https://example.com/webhook',
    // Add your custom field mappings
],
```

### Exclude Specific Methods

```php
'exclude_methods' => [
    '__construct',
    'middleware',
    'internalHelper',  // Add your methods
    'deprecatedMethod',
],
```

---

## 🧪 Testing

### Test on Single Method First

```bash
php artisan generate:controller-docs YourController --method=yourMethod --dry-run
```

Review the generated PHPDoc, then apply:

```bash
php artisan generate:controller-docs YourController --method=yourMethod
```

### Test Full Controller

```bash
php artisan generate:controller-docs YourController --dry-run
```

Then apply:

```bash
php artisan generate:controller-docs YourController
```

### Test All Controllers

```bash
php artisan generate:controller-docs --all --dry-run
```

Review the diff:

```bash
git diff
```

Apply:

```bash
php artisan generate:controller-docs --all
```

---

## 📊 Understanding Complexity Scores

Methods are scored 1-30:

```
Score    Meaning         Action
────────────────────────────────
< 5      Very simple     Skipped by default (use --force)
5-10     Simple          Documented with basic info
11-15    Moderate        Full documentation with notes
16-20    Complex         Detailed analysis + warnings
> 20     Very complex    Extensive documentation + multiple warnings
```

Examples:

- `index()` with simple query: **3-5** (skipped)
- `store()` with validation: **8-12** (documented)
- `store()` with transaction + queue: **15-20** (fully documented)
- `processPayment()` with transaction + queue + external API: **20+** (extensive)

---

## 🚨 Troubleshooting

### Command Not Found

```bash
composer dump-autoload
php artisan clear:cache
php artisan generate:controller-docs --help
```

### No Controllers Found

Make sure:
1. Controllers exist in `app/Http/Controllers/`
2. Filenames match class names (OrderController.php)
3. Classes extend Controller or have public methods

### Method Not Being Documented

Check:
1. Method is `public` (not private/protected)
2. Not a magic method (`__construct`, `__invoke`, etc)
3. Not in excluded list (`config/phpdoc-generator.php`)
4. Complexity > 5 (use `--force` to include simple)

### Test with Verbose Output

The command shows which methods were skipped and why:

```bash
php artisan generate:controller-docs YourController --force
```

- ✅ Shows generated methods
- ⊘ Shows already documented methods
- ⏭️ Shows skipped methods (why)

### File Write Permissions

Make sure Laravel can write to controller files:

```bash
chmod -R 755 app/Http/Controllers/
```

---

## 📚 Integration with Scribe

Perfect workflow:

```bash
# 1. Generate PHPDoc
php artisan generate:controller-docs --all

# 2. Install Scribe
composer require knuckleswtf/scribe

# 3. Configure Scribe (optional)
php artisan vendor:publish --provider="Knuckles\Scribe\ScribeServiceProvider"

# 4. Generate documentation
php artisan scribe:generate

# 5. View documentation
open docs/api.html
```

The generated PHPDoc is 100% compatible with Scribe!

---

## 🎨 Next Steps

1. ✅ Install package
2. ✅ Run `generate:controller-docs --help`
3. ✅ Try `generate:controller-docs YourController --dry-run`
4. ✅ Review generated PHPDoc
5. ✅ Apply: `generate:controller-docs YourController`
6. ✅ Install Scribe for full API documentation
7. ✅ Generate docs: `php artisan scribe:generate`

---

## 💡 Pro Tips

**Tip 1: Use in CI/CD**

```bash
# In your CI pipeline
php artisan generate:controller-docs --all
git diff --exit-code  # Fail if docs changed but not committed
```

**Tip 2: Automate on File Change**

Watch for changes and auto-generate:

```bash
php artisan make:observer ControllerObserver
// Check if controller changed, regenerate PHPDoc
```

**Tip 3: Team Standards**

Commit `config/phpdoc-generator.php` to ensure team consistency:

```bash
git add config/phpdoc-generator.php
git commit -m "chore: standardize PHPDoc generation config"
```

**Tip 4: Review Before Committing**

Always preview first:

```bash
php artisan generate:controller-docs --all --dry-run
git diff  # Review changes
git add && git commit  # Commit if good
```

---

**You're all set! 🎉 Start generating beautiful documentation!**

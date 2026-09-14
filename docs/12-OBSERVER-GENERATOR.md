# Observer Generator

FLIQ can generate Observer classes for you. An Observer is a file where you put
code that runs automatically when something happens to a Model — like before
saving, after creating, or before deleting.

## Table of Contents

- [What is an Observer?](#what-is-an-observer)
- [Step 1: Generate an Observer](#step-1-generate-an-observer)
- [Step 2: Add Your Logic](#step-2-add-your-logic)
- [Step 3: Register the Observer](#step-3-register-the-observer)
- [Choose Specific Events](#choose-specific-events)
- [Generate All Observers at Once](#generate-all-observers-at-once)
- [Add Events to an Existing Observer](#add-events-to-an-existing-observer)
- [Automatic Namespace Detection](#automatic-namespace-detection)
- [Real-World Examples](#real-world-examples)
- [Using It in PHP Code](#using-it-in-php-code)
- [All Options](#all-options)

---

## What is an Observer?

When you work with Models, you often want to run code at certain moments:

- Before saving a user → auto-generate their slug
- After creating an order → send a confirmation email
- Before deleting a record → check if it's allowed

You *could* put this logic directly in your controller or model, but it gets
messy fast. An Observer keeps it organized in one dedicated class.

### Without an Observer (scattered logic)

```php
/* Logic spread across your codebase */
User::on('creating', function (User $user) {
    $user->slug = strtolower($user->name);
});

User::on('deleting', function (User $user) {
    if ($user->role === 'admin') {
        return false; /* Cancel deletion */
    }
});
```

### With an Observer (organized in one file)

```php
/* All User lifecycle logic in one place */
User::observe(new UserObserver());
```

---

## Step 1: Generate an Observer

Run this command from your project root:

```bash
vendor/bin/fliq make:observer User
```

This creates the file `app/Observers/UserObserver.php` with method stubs for all
lifecycle events.

### Preview First

Not sure what it will look like? Preview without creating a file:

```bash
vendor/bin/fliq make:observer User --preview
```

### What Gets Created

The generated file looks like this:

```php
<?php

namespace App\Observers;

use App\Models\User;

/**
 * UserObserver Class.
 *
 * Observes lifecycle events on the User model.
 * Register with: User::observe(new UserObserver());
 */
class UserObserver
{
    /**
     * Handle the model is being created (before INSERT) event.
     *
     * @param User $user The model instance.
     * @return bool|null Return false to cancel the operation; null to continue.
     */
    public function creating(User $user): ?bool
    {
        //

        return null;
    }

    /**
     * Handle the model was created (after INSERT) event.
     *
     * @param User $user The model instance.
     * @return void
     */
    public function created(User $user): void
    {
        //
    }

    /**
     * Handle the model is being updated (before UPDATE) event.
     *
     * @param User $user The model instance.
     * @return bool|null Return false to cancel the operation; null to continue.
     */
    public function updating(User $user): ?bool
    {
        //

        return null;
    }

    /**
     * Handle the model was updated (after UPDATE) event.
     *
     * @param User $user The model instance.
     * @return void
     */
    public function updated(User $user): void
    {
        //
    }

    /**
     * Handle the model is being saved (before INSERT or UPDATE) event.
     *
     * @param User $user The model instance.
     * @return bool|null Return false to cancel the operation; null to continue.
     */
    public function saving(User $user): ?bool
    {
        //

        return null;
    }

    /**
     * Handle the model was saved (after INSERT or UPDATE) event.
     *
     * @param User $user The model instance.
     * @return void
     */
    public function saved(User $user): void
    {
        //
    }

    /**
     * Handle the model is being deleted (before DELETE) event.
     *
     * @param User $user The model instance.
     * @return bool|null Return false to cancel the operation; null to continue.
     */
    public function deleting(User $user): ?bool
    {
        //

        return null;
    }

    /**
     * Handle the model was deleted (after DELETE) event.
     *
     * @param User $user The model instance.
     * @return void
     */
    public function deleted(User $user): void
    {
        //
    }
}
```

Each method is empty — you fill in the ones you need and delete the ones you
don't.

The four "before" methods return `?bool` rather than `void`, because returning
`false` from one cancels the operation. Leave the `return null;` in place when
you don't want to cancel: a `?bool` method that falls off its end raises a
TypeError, and `null` means "carry on".

---

## Step 2: Add Your Logic

Open the generated file and add your code. For example:

```php
public function creating(User $user): ?bool
{
    /* Auto-generate slug from username */
    $user->slug = strtolower(str_replace(' ', '-', $user->username));

    return null; /* Carry on with the insert */
}

public function deleting(User $user): ?bool
{
    /* Don't allow deleting admin users */
    if ($user->role === 'admin') {
        return false; /* This cancels the delete */
    }

    return null;
}
```

### Which Methods Can Cancel Operations?

| Method     | When It Runs                                      | Can Cancel? |
|------------|---------------------------------------------------|:-----------:|
| `creating` | Before a NEW record is inserted into the database |     Yes     |
| `created`  | After a new record was inserted                   |     No      |
| `updating` | Before an EXISTING record is updated              |     Yes     |
| `updated`  | After a record was updated                        |     No      |
| `saving`   | Before any save (both new and existing records)   |     Yes     |
| `saved`    | After any save                                    |     No      |
| `deleting` | Before a record is deleted                        |     Yes     |
| `deleted`  | After a record was deleted                        |     No      |

To cancel: return `false` from a "before" method. The insert/update/delete will
not happen.

---

## Step 3: Register the Observer

The observer does nothing until you register it. Add this line to your
application startup (e.g., in a bootstrap file, service provider, or
`index.php`):

```php
use App\Models\User;
use App\Observers\UserObserver;

User::observe(new UserObserver());
```

After this line, every time you create, update, or delete a User, the observer
methods will run automatically.

### Where to Put the Registration

Put it wherever your app initializes — before any model operations happen:

```php
/* index.php or bootstrap.php */
require 'vendor/autoload.php';

use Simsoft\DB\Connection;
use App\Models\User;
use App\Models\Order;
use App\Observers\UserObserver;
use App\Observers\OrderObserver;

/* Set up a database */
Connection::configure('config/db.php');

/* Register observers */
User::observe(new UserObserver());
Order::observe(new OrderObserver());

/* Now your app code... */
```

---

## Choose Specific Events

If you only care about certain events, use `--events` to generate a smaller
file:

```bash
vendor/bin/fliq make:observer Order --events=creating,deleting
```

This creates an observer with only two methods instead of eight:

```php
class OrderObserver
{
    public function creating(Order $order): ?bool
    {
        //

        return null;
    }

    public function deleting(Order $order): ?bool
    {
        //

        return null;
    }
}
```

### Available Events

You can pick any combination from this list:

`creating`, `created`, `updating`, `updated`, `saving`, `saved`, `deleting`,
`deleted`

Separate them with commas; surrounding spaces are trimmed:

```bash
vendor/bin/fliq make:observer Payment --events=creating,created,saving,saved
```

---

## Real-World Examples

### Auto-Generate a Slug

```php
class PostObserver
{
    public function creating(Post $post): ?bool
    {
        $post->slug = strtolower(str_replace(' ', '-', $post->title));

        return null;
    }
}
```

### Prevent Deleting Important Records

```php
class UserObserver
{
    public function deleting(User $user): ?bool
    {
        /* Block deletion of admin accounts */
        if ($user->role === 'admin') {
            return false;
        }

        return null;
    }
}
```

### Send Notification After Creating

```php
class OrderObserver
{
    public function created(Order $order): void
    {
        /* Send a confirmation email to a customer */
        EmailService::send($order->email, 'Your order #' . $order->id . ' is confirmed!');
    }
}
```

### Audit Logging

```php
class OrderObserver
{
    public function created(Order $order): void
    {
        AuditLog::record('order.created', $order->id, $order->toArray());
    }

    public function updated(Order $order): void
    {
        AuditLog::record('order.updated', $order->id, $order->getDirtyAttributes());
    }

    public function deleted(Order $order): void
    {
        AuditLog::record('order.deleted', $order->id);
    }
}
```

### Clear Cache After Changes

```php
class ProductObserver
{
    public function saved(Product $product): void
    {
        /* Clear the product cache whenever a product is created or updated */
        cache()->forget("product:{$product->id}");
        cache()->forget('products:all');
    }

    public function deleted(Product $product): void
    {
        cache()->forget("product:{$product->id}");
        cache()->forget('products:all');
    }
}
```

---

## Using It in PHP Code

You can also generate observers programmatically:

### Basic Generation

```php
use Simsoft\DB\Generator\ObserverGenerator;

$path = ObserverGenerator::forModel('User')
    ->generate();

echo "Created: $path\n";
/* Created: app/Observers/UserObserver.php */
```

### Custom Location

```php
ObserverGenerator::forModel('Order')
    ->namespace('App\\Listeners')
    ->modelNamespace('App\\Domain\\Models')
    ->outputDir('app/Listeners')
    ->generate();
```

### Only Specific Events

```php
ObserverGenerator::forModel('Payment')
    ->events(['creating', 'created', 'deleting'])
    ->generate();
```

### Preview Without Writing

```php
$code = ObserverGenerator::forModel('User')->preview();
echo $code;
```

### Force Overwrite

```php
ObserverGenerator::forModel('User')
    ->force()
    ->generate();
```

---

## Generate All Observers at Once

Generate observers for every model file in your models' directory:

```bash
vendor/bin/fliq make:observer --all
```

Output:

```
Created: app/Observers/DepartmentObserver.php
Created: app/Observers/UserObserver.php
Created: app/Observers/PostObserver.php
Created: app/Observers/CommentObserver.php
Created: app/Observers/OrderObserver.php

5 created, 0 skipped (5 models).
```

The command scans your models directory (auto-detected from `composer.json`
PSR-4 mapping) and generates an observer for each model file found.

### Force Regenerate All

```bash
vendor/bin/fliq make:observer --all --force
```

### Custom Directories

```bash
vendor/bin/fliq make:observer --all \
    --model-namespace=App\\Domain\\Models \
    --namespace=App\\Domain\\Observers \
    --output=app/Domain/Observers
```

---

## Add Events to an Existing Observer

Already have an observer but want to add more event methods? Use `--append`
instead of regenerating:

```bash
vendor/bin/fliq make:observer User --append --events=saving,deleting
```

This reads your existing `UserObserver.php`, checks which methods are already
there, and adds only the missing ones.

### Example

Say you originally created an observer with only two events:

```bash
vendor/bin/fliq make:observer Order --events=creating,created
```

Later you need to also handle `deleting`. Instead of rewriting the file (and
losing your existing logic), use `--append`:

```bash
vendor/bin/fliq make:observer Order --append --events=deleting
```

Output:

```
Updated: app/Observers/OrderObserver.php
Added events: deleting
```

Your existing `creating` and `created` methods are untouched — only the
`deleting` stub is added at the end of the class.

### What If All Events Already Exist?

```bash
vendor/bin/fliq make:observer Order --append --events=creating,created
```

Output:

```
No missing events. All requested events already exist in the observer.
```

Nothing is modified.

### Append All Missing Events

If you don't specify `--events`, it checks all eight lifecycle events and adds
any that are missing:

```bash
vendor/bin/fliq make:observer User --append
```

### Programmatic Usage

```php
use Simsoft\DB\Generator\ObserverGenerator;

$result = ObserverGenerator::forModel('User')
    ->events(['creating', 'saving', 'deleting'])
    ->append();

if ($result === false) {
    echo "Observer file doesn't exist yet\n";
} elseif (empty($result['added'])) {
    echo "All events already present\n";
} else {
    echo "Added: " . implode(', ', $result['added']) . "\n";
    echo "Already had: " . implode(', ', $result['existing']) . "\n";
}
```

---

## Automatic Namespace Detection

The CLI auto-detects the model namespace from your `composer.json` PSR-4
mapping. If you have:

```json
{
    "autoload": {
        "psr-4": {
            "App\\": "app/"
        }
    }
}
```

And a directory `app/Models/` exists, the CLI automatically resolves
`--model-namespace=App\Models` without you specifying it.

You can still override it manually if your project uses a different structure:

```bash
vendor/bin/fliq make:observer User --model-namespace=Domain\\Models
```

---

## All Options

| Option                   | Default                   | What It Does                                            |
|--------------------------|---------------------------|---------------------------------------------------------|
| `<ModelName>`            | _(required unless --all)_ | The model class name (e.g., `User`, `Order`)            |
| `--all`                  | off                       | Generate observers for ALL model files in the directory |
| `--append`               | off                       | Add missing event methods to an existing observer file  |
| `--namespace=<ns>`       | `App\Observers`           | PHP namespace for the observer class                    |
| `--model-namespace=<ns>` | auto-detected             | Where the model class lives (for the import)            |
| `--output=<dir>`         | `app/Observers`           | Directory where the file is created                     |
| `--events=<list>`        | all 8 events              | Comma-separated list of events to include               |
| `--force`                | off                       | Overwrite the file if it already exists                 |
| `--preview`              | off                       | Print the code to screen without creating a file        |
| `--verbose`, `-v`        | off                       | Show detailed output                                    |

# Validation

The `validate()` method on Model is called automatically before `save()`. Return
`false` to prevent the save.

## Table of Contents

- [Install](#install)
- [Basic Usage](#basic-usage)
- [Validation Groups (Scenarios)](#validation-groups-scenarios)
- [Validating in the Controller](#validating-in-the-controller)
- [Reusable Validator Class](#reusable-validator-class)
- [Which Pattern Should I Use?](#which-pattern-should-i-use)
- [Available Constraints](#available-constraints)
- [Custom Rules](#custom-rules)
- [Conditional and Optional Rules](#conditional-and-optional-rules)

## Install

```bash
composer require simsoft/validator
```

`simsoft/validator` is a Laravel-inspired wrapper for Symfony Validator.

> 📖 **Full documentation:
** [sim-soft.github.io/validator](https://sim-soft.github.io/validator)

## Basic Usage

Add validation rules inside your model's `validate()` method:

```php
use Simsoft\DB\Model;
use Simsoft\Validator;
use Simsoft\Validator\Rule;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class User extends Model
{
    protected string $table = 'user';
    protected array $fillable = ['name', 'email', 'password'];

    public function validate(): bool
    {
        $validator = Validator::make($this->getAttributes(), [
            'name' => Rule::bail([
                new NotBlank(message: 'Name is required'),
                new Length(min: 2, max: 100),
            ]),
            'email' => Rule::bail([
                new NotBlank(message: 'Email is required'),
                new Email(message: 'Invalid email'),
            ]),
            'password' => [
                new NotBlank(message: 'Password is required'),
                new Length(min: 8, minMessage: 'Minimum {{ limit }} characters'),
            ],
        ]);

        if ($validator->fails()) {
            $this->addValidationErrors($validator->errors());
            return false;
        }

        return true;
    }
}
```

Now validation runs automatically when you call `save()`:

```php
$user = new User([
    'name' => '',
    'email' => 'invalid',
    'password' => '123',
]);

if (!$user->save()) {
    // All validation errors are available
    foreach ($user->getErrors() as $error) {
        echo $error . "\n";
    }
}
```

That's it. If validation fails, `save()` returns `false` and no database query
is executed.

### Understanding the Rules

**`Rule::bail([...])`** — checks constraints in order, stops at the first
failure:

```php
// If email is blank, only "Email is required" is reported.
// The format check is skipped because there's nothing to check.
'email' => Rule::bail([
    new NotBlank(message: 'Email is required'),  // checked first
    new Email(message: 'Invalid email'),          // only checked if not blank
]),
```

**Plain array `[...]`** — checks ALL constraints, reports ALL failures:

```php
// If the password is empty, BOTH errors are reported at once.
'password' => [
    new NotBlank(message: 'Password is required'),
    new Length(min: 8, minMessage: 'Minimum {{ limit }} characters'),
],
```

Use `Rule::bail()` when later checks depend on earlier ones passing. Use a plain
array when you want to show all problems at once.

## Validation Groups (Scenarios)

When the same model needs different rules for different operations (e.g.,
register vs. update profile), use validation groups. Each constraint specifies
which scenario it belongs to:

```php
use Simsoft\DB\Model;
use Simsoft\DB\Traits\Scenario;
use Simsoft\Validator;
use Simsoft\Validator\Rule;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Url;

class User extends Model
{
    use Scenario;

    public const SCENARIO_REGISTER = 'register';
    public const SCENARIO_UPDATE_PROFILE = 'update_profile';

    protected string $table = 'user';
    protected array $fillable = ['name', 'email', 'password', 'avatar'];

    public function validate(): bool
    {
        $validator = Validator::make($this->getAttributes(), [
            'name' => Rule::bail([
                new NotBlank(message: 'Name is required', groups: [self::SCENARIO_REGISTER, self::SCENARIO_UPDATE_PROFILE]),
                new Length(min: 2, groups: [self::SCENARIO_REGISTER, self::SCENARIO_UPDATE_PROFILE]),
            ]),
            'email' => Rule::bail([
                new NotBlank(message: 'Email is required', groups: [self::SCENARIO_REGISTER]),
                new Email(message: 'Invalid email', groups: [self::SCENARIO_REGISTER]),
            ]),
            'password' => [
                new NotBlank(message: 'Password is required', groups: [self::SCENARIO_REGISTER]),
                new Length(min: 8, groups: [self::SCENARIO_REGISTER]),
            ],
            'avatar' => new Url(message: 'Invalid avatar URL', groups: [self::SCENARIO_UPDATE_PROFILE]),
        ]);

        // Only validate constraints matching the current scenario
        if (!$validator->validate($this->getScenario() ?? self::SCENARIO_REGISTER)) {
            $this->addValidationErrors($validator->errors());
            return false;
        }

        return true;
    }
}
```

Usage:

```php
/* Register: validates name + email + password (avatar is skipped) */
$user = (new User($_POST))->withScenario(User::SCENARIO_REGISTER);
$user->save();

/* Profile update: validates name + avatar only (email/password skipped) */
$user = User::findByPk(1)->withScenario(User::SCENARIO_UPDATE_PROFILE);
$user->fill($_POST);
$user->save();
```

The `groups` parameter on each constraint controls which scenario it belongs to.
When you call `$validator->validate('register')`, only constraints with that
group are checked — everything else is skipped.

### Conditional Rules with isNew() / exists()

You can also use `$this->isNew()` and `$this->exists()` to apply rules only on
creation or update — without needing scenarios:

```php
public function validate(): bool
{
    $rules = [
        'name' => Rule::bail([
            new NotBlank(message: 'Name is required'),
            new Length(min: 2, max: 100),
        ]),
        'email' => Rule::bail([
            new NotBlank(message: 'Email is required'),
            new Email(message: 'Invalid email'),
        ]),
    ];

    // Password is only required when creating a new user
    if ($this->isNew()) {
        $rules['password'] = Rule::bail([
            new NotBlank(message: 'Password is required'),
            new Length(min: 8),
        ]);
    }

    $validator = Validator::make($this->getAttributes(), $rules);

    if ($validator->fails()) {
        $this->addValidationErrors($validator->errors());
        return false;
    }

    return true;
}
```

- `$this->isNew()` — the model has never been saved (INSERT)
- `$this->exists()` — the model was loaded from the database (UPDATE)

## Validating in the Controller

Sometimes you want to validate before the data reaches the model — for example,
to return an error response early:

```php
class UserController
{
    public function store(): void
    {
        $validator = Validator::make($_POST, [
            'name' => Rule::bail([
                new NotBlank(message: 'Name is required'),
                new Length(min: 2),
            ]),
            'email' => Rule::bail([
                new NotBlank(message: 'Email is required'),
                new Email(message: 'Invalid email'),
            ]),
        ]);

        if ($validator->fails()) {
            // Show errors, redirect, return JSON, etc.
            foreach ($validator->errors() as $field => $messages) {
                echo "$field: " . implode(', ', $messages) . "\n";
            }
            return;
        }

        // Only clean data reaches the model
        $user = new User();
        $user->fill($validator->validated());
        $user->save(validate: false); // skip model validation — already done
    }
}
```

## Reusable Validator Class

When multiple places need the same validation rules, extract them into a class.
Create a file like `app/Validators/UserRegistrationValidator.php`:

```php
namespace App\Validators;

use Simsoft\Validator;
use Simsoft\Validator\Rule;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class UserRegistrationValidator extends Validator
{
    // Only these attributes are validated — anything else in the input is ignored
    protected array $attributes = ['name', 'email', 'password'];

    protected function rules(): array
    {
        return [
            'name' => Rule::bail([
                new NotBlank(message: 'Name is required'),
                new Length(min: 2, max: 100),
            ]),
            'email' => Rule::bail([
                new NotBlank(message: 'Email is required'),
                new Email(message: 'Invalid email'),
            ]),
            'password' => [
                new NotBlank(message: 'Password is required'),
                new Length(min: 8),
            ],
        ];
    }
}
```

This makes the controller from the previous section much cleaner — compare:

```php
class UserController
{
    public function store(): void
    {
        $validator = UserRegistrationValidator::make($_POST);

        if ($validator->fails()) {
            foreach ($validator->errors() as $field => $messages) {
                echo "$field: " . implode(', ', $messages) . "\n";
            }
            return;
        }

        $user = new User();
        $user->fill($validator->validated());
        $user->save(validate: false);
    }
}
```

No inline rules cluttering the controller. The validation logic lives in one
place and can be reused across multiple controllers or actions.

### Combining with Scenarios

You can also use different validator classes per scenario — each class owns its
own rules, keeping things focused:

```php
/* app/Validators/UserUpdateProfileValidator.php */
namespace App\Validators;

use Simsoft\Validator;
use Simsoft\Validator\Rule;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Url;

class UserUpdateProfileValidator extends Validator
{
    protected array $attributes = ['name', 'avatar'];

    protected function rules(): array
    {
        return [
            'name' => Rule::bail([
                new NotBlank(message: 'Name is required'),
                new Length(min: 2, max: 100),
            ]),
            'avatar' => new Url(message: 'Invalid avatar URL'),
        ];
    }
}
```

Then in the model, use `match` to pick the right validator:

```php
use Simsoft\DB\Model;
use Simsoft\DB\Traits\Scenario;
use App\Validators\UserRegistrationValidator;
use App\Validators\UserUpdateProfileValidator;

class User extends Model
{
    use Scenario;

    public const SCENARIO_REGISTER = 'register';
    public const SCENARIO_UPDATE_PROFILE = 'update_profile';

    protected string $table = 'user';
    protected array $fillable = ['name', 'email', 'password', 'avatar'];

    public function validate(): bool
    {
        $validator = match ($this->getScenario()) {
            self::SCENARIO_UPDATE_PROFILE => UserUpdateProfileValidator::make($this->getAttributes()),
            default => UserRegistrationValidator::make($this->getAttributes()),
        };

        if ($validator->fails()) {
            $this->addValidationErrors($validator->errors());
            return false;
        }

        return true;
    }
}
```

Each scenario gets its own validator class with its own `$attributes` and
`rules()`. The model just dispatches to the right one.

## Which Pattern Should I Use?

Start simple. Move to more complex patterns only when you need them:

| Situation                                               | Pattern                                                          |
|---------------------------------------------------------|------------------------------------------------------------------|
| Just getting started                                    | Put rules in Model `validate()` — [Basic Usage](#basic-usage)    |
| Same model, different rules per operation               | [Validation Groups with Scenarios](#validation-groups-scenarios) |
| Need to return error response before touching the model | [Validate in the Controller](#validating-in-the-controller)      |
| Multiple controllers share the same rules               | [Reusable Validator Class](#reusable-validator-class)            |

**For most projects, Basic Usage is all you need.**

## Available Constraints

The examples above use `NotBlank`, `Email`, and `Length` — but there are many
more. Each constraint is a class you import from
`Symfony\Component\Validator\Constraints`:

```php
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Url;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Constraints\Choice;
// ... and many more
```

Here are the most commonly used ones:

| Constraint | What it checks        | Example                                                  |
|------------|-----------------------|----------------------------------------------------------|
| `NotBlank` | Not null/empty        | `new NotBlank(message: 'Required')`                      |
| `Length`   | String min/max length | `new Length(min: 2, max: 100)`                           |
| `Email`    | Valid email format    | `new Email(message: 'Invalid email')`                    |
| `Url`      | Valid URL             | `new Url(message: 'Invalid URL')`                        |
| `Regex`    | Matches a pattern     | `new Regex(pattern: '/^\d+$/', message: 'Numbers only')` |
| `Range`    | Number within range   | `new Range(min: 1, max: 100)`                            |
| `Choice`   | Value in allowed list | `new Choice(choices: ['active', 'inactive'])`            |
| `Type`     | PHP type check        | `new Type(type: 'integer')`                              |
| `Positive` | Greater than zero     | `new Positive()`                                         |
| `Date`     | Valid date string     | `new Date()`                                             |

Every constraint accepts a `message` parameter to customize the error text, and
a `groups` parameter for [Validation Groups](#validation-groups-scenarios).

For the full list of 70+ constraints (numbers, strings, dates, files,
comparison, etc.), see
the [Symfony Constraints Reference](https://symfony.com/doc/current/validation.html#basic-constraints).

## Custom Rules

When built-in constraints aren't enough, create your own.

### Inline with Rule::make()

The quickest way — a closure that receives the value and a `$fail` callback:

```php
use Simsoft\Validator\Rule;

$validator = Validator::make($_POST, [
    'username' => Rule::make(function (mixed $value, Closure $fail) {
        if (str_contains($value, ' ')) {
            $fail('Username cannot contain spaces');
        }

        if (preg_match('/[^a-z0-9_]/', $value)) {
            $fail('Username can only contain lowercase letters, numbers, and underscores');
        }
    }),
]);
```

If validation passes, do nothing. If it fails, call `$fail('error message')`.

### Reusable Constraint Class

For rules, you use it in multiple places, extend `ValidationRule`:

```php
namespace App\Validators\Rules;

use Closure;
use Simsoft\Validator\Constraints\ValidationRule;

class NoDisposableEmail extends ValidationRule
{
    public string $message = 'Disposable email addresses are not allowed';

    private array $blocklist = ['mailinator.com', 'tempmail.com', 'throwaway.email'];

    public function validate(mixed $value, Closure $fail): void
    {
        if (!is_string($value) || $value === '') {
            return; // let NotBlank handle empty values
        }

        $domain = strtolower(substr(strrchr($value, '@') ?: '', 1));

        if (in_array($domain, $this->blocklist, true)) {
            $fail($this->message);
        }
    }
}
```

Use it like any other constraint:

```php
use App\Validators\Rules\NoDisposableEmail;

$validator = Validator::make($_POST, [
    'email' => Rule::bail([
        new NotBlank(message: 'Email is required'),
        new Email(message: 'Invalid email'),
        new NoDisposableEmail(),
    ]),
]);
```

> 📖 For more patterns (constructor parameters, groups support, etc.),
> see [Custom Rules](https://sim-soft.github.io/validator/#/custom-rules) in the
> full validator docs.

## Conditional and Optional Rules

Sometimes a field should only be validated under certain conditions. The
validator provides helpers for this.

### Rule::requiredIf() — Required only when a condition is true

In a model's `validate()` method, reference the model's own attributes:

```php
public function validate(): bool
{
    $validator = Validator::make($this->getAttributes(), [
        'company' => Rule::requiredIf(
            $this->type === 'business',
            message: 'Company name is required for business accounts'
        ),
    ]);

    if ($validator->fails()) {
        $this->addValidationErrors($validator->errors());
        return false;
    }

    return true;
}
```

The `company` field is only required when `type` is `'business'`. If the
condition is false, the field is skipped entirely.

In a controller, pass the condition from the input data:

```php
$data = $_POST;

$validator = Validator::make($data, [
    'company' => Rule::requiredIf(
        ($data['type'] ?? '') === 'business',
        message: 'Company name is required for business accounts'
    ),
]);
```

### Rule::sometimes() — Only validate when the value is present

```php
use Simsoft\Validator\Rule;

$validator = Validator::make($this->getAttributes(), [
    'website' => Rule::sometimes(function (mixed $value, Closure $fail) {
        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            $fail('Invalid website URL');
        }
    }),
]);
```

If `website` is `null` (not provided), validation is skipped. If it has a value,
the rule runs. Useful for optional fields like profile URLs or social links.

### $validator→sometimes() — Add rules based on other input

Use this when a rule depends on another field's value. The condition closure
receives the full input array:

```php
use Symfony\Component\Validator\Constraints\NotBlank;

$validator = Validator::make($this->getAttributes(), [
    'email' => new NotBlank(message: 'Email is required'),
]);

// 'phone' is only required if 'contact_method' is 'phone'
$validator->sometimes(
    'phone',
    new NotBlank(message: 'Phone is required when contact method is phone'),
    fn(array $input) => ($input['contact_method'] ?? '') === 'phone'
);
```

This works in any context — model `validate()`, controller, or reusable
validator class — because the closure inspects the input data that was passed to
`Validator::make()`.

> 📖 For more advanced patterns,
> see [Conditional Rules](https://sim-soft.github.io/validator/#/conditional-rules)
> in the full validator docs.

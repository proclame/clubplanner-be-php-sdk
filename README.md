# Club Planner PHP SDK

A PHP client for the Club Planner Partner API: members, subscriptions, planner, leads, financial, check-in, coach and the rest. It covers all 199 operations of the API's OpenAPI spec.

Requires PHP 8.2+. Works in any PHP project, with an optional Laravel service provider.

## Installation

```bash
composer require proclame/clubplanner-be-php-sdk
```

## Usage

```php
use Proclame\ClubPlanner\ClubPlanner;
use Proclame\ClubPlanner\ClubPlannerException;

$clubPlanner = new ClubPlanner(
    baseUrl: 'https://your-club.clubplanner.app',
    apiKey: $apiKey,
    timezone: 'Europe/Brussels',
);

// A platform can host several clubs: discover the ids first.
$clubs = $clubPlanner->clubs()->list();

$member = $clubPlanner->members()->get(123);

$sessions = $clubPlanner->calendarItems()->list(calendarId: 4, date: '2026-11-02', days: 7);

$clubPlanner->reservations()->create([
    'memberId' => 123,
    'calendarItemId' => 456,
]);

try {
    $member = $clubPlanner->members()->find(email: 'someone@example.com');
} catch (ClubPlannerException $e) {
    if ($e->isNotFound()) {
        // ...
    }
}
```

Path parameters come first, then the required query parameters, then `$body`, then the optional query parameters. Use named arguments for those. Request bodies have their fields documented as array shapes, so an IDE or PHPStan can complete and check them.

Responses are the API's decoded JSON, as arrays with the API's camelCase keys. Each method's docblock names the schema it responds with; [spec/partner-api.json](spec/partner-api.json) has the fields.

### Resources

| Accessor | Covers |
|---|---|
| `members()` | Records, attachments, statuses, blocking, credit, messaging, password, tests, debt collection |
| `subscriptions()` | Member subscriptions, payments, types, options, groups, contracts |
| `visits()` | Visits and visit counts |
| `retention()` | Retention scores and summary |
| `calendars()`, `calendarItems()`, `reservations()`, `waitingList()` | Planner |
| `leads()` | Leads, their steps, sales calendars |
| `sales()`, `credits()`, `moneyInOut()`, `balances()`, `pos()`, `coupons()`, `actions()`, `vouchers()`, `bills()`, `openItems()`, `invoices()`, `orders()` | Financial |
| `clubs()`, `clientDisplays()`, `payMethods()`, `general()` | General |
| `employees()` | Employees, groups, tasks, schedule, check-ins, authorisation groups |
| `checkIn()` | Check-in and check-out at the door |
| `coach()` | Exercises, workouts, member workouts |
| `chats()`, `io()` | Chatbot conversations, Loxone gates and lights |

`$clubPlanner->ping()` checks connectivity. `$clubPlanner->transport()` gives raw `get`, `post`, `put`, `patch`, `delete` and `file` calls for anything else.

### Paging

`members()`, `leads()`, `actions()`, `vouchers()` and `bills()` have an `iterate()` that walks every page lazily:

```php
foreach ($clubPlanner->members()->iterate(pageSize: 500) as $member) {
    // ...
}
```

Without `pageSize`, `GET /members` silently caps at 1000 rows. Each page is a request and counts against the key's rate limit.

### Filtering members

`GET /members` takes a raw SQL-like filter. `MemberFilter` builds it with the quoting done for you:

```php
$filter = $clubPlanner->memberFilter()
    ->whereEmailLike('gmail')
    ->whereCreatedAfter('2026-01-01');

$clubPlanner->members()->iterate(filter: $filter->toString());
```

### Dates

The API's dates carry no offset: they mean the club's wall clock. Every date parameter and documented date field takes a `DateTimeInterface` or a string, and is converted through the `timezone` you configured. A string without an offset is already read as the club's wall clock.

### Files

Image and PDF endpoints return a `BinaryFile`:

```php
$pdf = $clubPlanner->invoices()->pdf(99);
$pdf->mimeType; // application/pdf
$pdf->saveTo('/tmp/invoice-99.pdf');
```

### Errors

Every failure raises a `ClubPlannerException`. `$e->status` is the HTTP status, or null for a local failure such as an unreachable host. `isUnauthorized()`, `isForbidden()`, `isNotFound()`, `isConflict()` and `isRateLimited()` test the common ones.

The SDK does not retry. A 429 means the key's period has to reset first, and a write is not safe to repeat blindly.

A write can answer with an empty body, so `null` from a write means it succeeded and said nothing.

### Sensitive data

Responses are returned whole by default, which includes a member's IBAN as `bankAccount`. When the data goes somewhere it should not, such as logs or a language model, ask for those fields to be stripped:

```php
use Proclame\ClubPlanner\Redactor;

new ClubPlanner($baseUrl, $apiKey, redact: Redactor::SENSITIVE_FIELDS);
```

Do not read, merge and write a record through a redacting client: the stripped fields would be missing from what you send back.

## Laravel

The service provider is auto-discovered. Set the environment and inject the client:

```dotenv
CLUBPLANNER_BASE_URL=https://your-club.clubplanner.app
CLUBPLANNER_API_KEY=cp_...
CLUBPLANNER_TIMEZONE=Europe/Brussels
```

```php
public function show(ClubPlanner $clubPlanner, int $id)
{
    return $clubPlanner->members()->get($id);
}
```

Publish the config with `php artisan vendor:publish --tag=clubplanner-config`.

## API behaviour to know about

Found by testing against a live platform, not in the API's documentation.

- **`GET /checkin` and `GET /checkin/checkout` are writes.** They record a check-in or check-out despite the verb, so never call them from something that retries or crawls.
- **`leads()->update()` replaces the lead.** Read it first and merge, or the fields left out are lost.
- **`calendarItems()->update()` treats 0 as "leave alone"** for `activityId`, `employeeId` and `roomId`. `capacity` is the exception, where 0 is real.
- **`members()->find()` refuses ambiguity.** A name or mobile matching more than one member answers 409. Only `uid` and `email` are reliably unique.
- **`visits()->list()` filters by whole days,** whatever time you pass.
- **`visits()->countRange()` counts distinct members and its `to` is exclusive.** Add a day to include the last one.
- **`visits()->countCurrent()` needs check-outs.** On a platform that records none, it returns everyone who came in that day.
- **Member id 0 can be a real member.** Do not treat it as empty.
- **A key's permissions are per namespace,** and it may be limited to one club. A 403 means the key lacks access, not that the request was wrong.

## Development

```bash
composer test     # PHPUnit; no test reaches a real platform
composer analyse  # PHPStan
composer format   # Pint
```

`src/Resources` is generated. To follow a change in the API, replace `spec/partner-api.json` with a fresh copy of `https://<platform>/openapi/partner.json` and run `composer generate`. The generator refuses to run until every new operation has a name in `tools/operations.php`. Live-tested API behaviour goes in the `notes` section of that file, so it ends up in the docblocks.

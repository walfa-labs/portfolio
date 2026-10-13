<?php

use App\Mail\ContactMessageReceived;
use App\Models\ContactMessage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/*
|--------------------------------------------------------------------------
| Contact (§3.11)
|--------------------------------------------------------------------------
|
| The public contract is the `contact_messages` row, the `toast` flash key and
| the 303 / 302 / 429 status codes.
|
| Rate limiting: `AppServiceProvider::configureRateLimiters()` registers
| `contact` as `Limit::perHour(3)->by($request->ip())`, and `ThrottleRequests`
| keys a *named* limiter as `md5($limiterName.$limit->key)`. `clearContactRateLimiter()`
| therefore clears exactly that bucket. Every test that makes more than three
| submissions calls it first, so the three permitted submissions are the only
| ones that count and the assertions do not depend on the `array` cache store
| happening to be fresh per test.
|
*/

/**
 * Clear the `contact` bucket for an IP, using the key `ThrottleRequests` derives.
 */
function clearContactRateLimiter(string $ip = '127.0.0.1'): void
{
    RateLimiter::clear(md5('contact'.$ip));
}

/**
 * A submission that satisfies every rule, so a test can break exactly one thing.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function contactFormPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.test',
        'message' => 'A message comfortably longer than ten characters.',
        'request_type' => 'access',
        'website' => '',
        '_ts' => time() - 5,
    ], $overrides);
}

test('a validation failure returns 302 back to the form with an errors bag', function () {
    $response = $this->post('/contact', [], ['Referer' => route('contact.show')]);

    $response->assertStatus(302);
    $response->assertRedirect(route('contact.show'));
    $response->assertSessionHasErrors(['name', 'email', 'message', 'request_type', '_ts']);

    expect(ContactMessage::query()->count())->toBe(0);
});

test('a valid submission returns 303 to the form, stores the row and flashes a toast', function () {
    Mail::fake();

    $response = $this->post('/contact', contactFormPayload([
        'request_type' => 'erasure',
        'message' => 'Please delete everything you hold about me.',
    ]));

    $response->assertStatus(303);
    $response->assertRedirect(route('contact.show'));

    $this->assertDatabaseHas('contact_messages', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.test',
        'message' => 'Please delete everything you hold about me.',
        'request_type' => 'erasure',
        'ip_address' => '127.0.0.1',
    ]);

    expect(ContactMessage::query()->count())->toBe(1);

    $response->assertSessionHas('toast', fn (mixed $toast): bool => is_array($toast)
        && ($toast['type'] ?? null) === 'success'
        && filled($toast['message'] ?? null));

    Mail::assertQueued(ContactMessageReceived::class);
});

test('the fourth submission within the hour is rate limited', function () {
    clearContactRateLimiter();

    foreach (range(1, 3) as $attempt) {
        $this->post('/contact', contactFormPayload())->assertStatus(303);
    }

    $response = $this->post('/contact', contactFormPayload());

    $response->assertStatus(429);

    expect(ContactMessage::query()->count())->toBe(3);
});

test('a filled honeypot fails validation', function () {
    $response = $this->post('/contact', contactFormPayload([
        'website' => 'https://spam.example',
    ]));

    $response->assertStatus(302);
    $response->assertSessionHasErrors('website');

    expect(ContactMessage::query()->count())->toBe(0);
});

test('a form submitted less than two seconds after it was rendered fails validation', function () {
    $response = $this->post('/contact', contactFormPayload(['_ts' => time()]));

    $response->assertStatus(302);
    $response->assertSessionHasErrors('_ts');

    expect(ContactMessage::query()->count())->toBe(0);
});

test('a form that has been open for at least two seconds passes the timestamp rule', function () {
    $response = $this->post('/contact', contactFormPayload(['_ts' => time() - 2]));

    $response->assertStatus(303);

    expect(ContactMessage::query()->count())->toBe(1);
});

test('an unknown request type fails validation', function () {
    $response = $this->post('/contact', contactFormPayload(['request_type' => 'complaint']));

    $response->assertStatus(302);
    $response->assertSessionHasErrors('request_type');

    expect(ContactMessage::query()->count())->toBe(0);
});

test('a message of exactly ten characters is accepted', function () {
    $message = str_repeat('a', 10);

    $response = $this->post('/contact', contactFormPayload(['message' => $message]));

    $response->assertStatus(303);

    expect(ContactMessage::query()->value('message'))->toBe($message);
});

test('a message of nine characters is rejected', function () {
    $response = $this->post('/contact', contactFormPayload(['message' => str_repeat('a', 9)]));

    $response->assertStatus(302);
    $response->assertSessionHasErrors('message');

    expect(ContactMessage::query()->count())->toBe(0);
});

test('a message of exactly five thousand characters is accepted', function () {
    $message = str_repeat('a', 5000);

    $response = $this->post('/contact', contactFormPayload(['message' => $message]));

    $response->assertStatus(303);

    expect(ContactMessage::query()->value('message'))->toBe($message);
});

test('a message of five thousand and one characters is rejected', function () {
    $response = $this->post('/contact', contactFormPayload(['message' => str_repeat('a', 5001)]));

    $response->assertStatus(302);
    $response->assertSessionHasErrors('message');

    expect(ContactMessage::query()->count())->toBe(0);
});

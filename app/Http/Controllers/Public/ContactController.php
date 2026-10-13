<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\ContactMessageRequest;
use App\Mail\ContactMessageReceived;
use App\Models\ContactMessage;
use App\Support\Seo\StructuredData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ContactController extends Controller
{
    /**
     * The description §3.9 fixes for this route.
     */
    private const DESCRIPTION = 'Get in touch with ';

    /**
     * The props the `GET /contact` route renders with.
     *
     * §3.2 declares that route as `Route::inertia('/contact', 'public/Contact')`,
     * which carries no controller of its own, so the page's SEO contract is built
     * here rather than inline in `routes/web.php`. Structured data stays
     * server-side (§3.9): `SeoHead.vue` only renders what it is handed.
     *
     * @return array<string, mixed>
     */
    public static function pageProps(): array
    {
        $description = self::DESCRIPTION.config('app.name').'.';

        return [
            'title' => 'Contact - '.config('app.name'),
            'description' => $description,
            'canonical' => StructuredData::url('contact.show'),
            'jsonLd' => StructuredData::graph([
                ...StructuredData::base(),
                ...StructuredData::page(
                    'ContactPage',
                    StructuredData::url('contact.show'),
                    'Contact',
                    $description,
                ),
                ...StructuredData::breadcrumbs([
                    ['title' => 'Home', 'href' => StructuredData::url('home')],
                    ['title' => 'Contact', 'href' => StructuredData::url('contact.show')],
                ]),
            ]),
            'noindex' => ! config('app.indexable'),
        ];
    }

    /**
     * Store a contact submission and notify the site owner.
     *
     * The row is the source of truth (§3.11): the notification is queued and may
     * be logged rather than delivered, but the submission survives either way.
     * The visitor gets a 303 back to the form, so a refresh never replays the
     * POST, plus the `toast` flash that `initializeFlashToast()` turns into a
     * toast.
     */
    public function store(ContactMessageRequest $request): RedirectResponse
    {
        $message = ContactMessage::create([
            'name' => $request->string('name')->trim()->value(),
            'email' => $request->string('email')->trim()->value(),
            'message' => $request->string('message')->trim()->value(),
            'request_type' => $request->string('request_type')->trim()->value(),
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
            'created_at' => now(),
        ]);

        Mail::to($this->recipient())->queue(new ContactMessageReceived($message));

        return redirect()
            ->route('contact.show', status: 303)
            ->with('toast', [
                'type' => 'success',
                'message' => 'Thanks — your message has arrived. I usually reply within a few days.',
            ]);
    }

    /**
     * Resolve the address the notification is delivered to.
     *
     * `CONTACT_EMAIL` is the configured recipient (§3.11). It ships empty in
     * `.env.example`, so an unset value falls back to the site's own from-address
     * rather than dropping the notification on the floor.
     */
    private function recipient(): string
    {
        $recipient = config('mail.contact_recipient');
        $fallback = config('mail.from.address');

        return is_string($recipient) && trim($recipient) !== ''
            ? $recipient
            : (string) $fallback;
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `/admin/messages` — the contact inbox (§5.8).
 *
 * It is not one of the eight §5.9 resources, so it does not extend
 * {@see AdminController}: there is no create, no update, no publish state, no
 * tag pivot and no reorder, and the §5.9 list contract (`?status=&q=&tag=`) has
 * nothing to filter on. It keeps the two conventions that do apply — the
 * `records` paginator prop and `Inertia::flash('toast', …)` on a write — and
 * nothing else.
 *
 * Authorisation is the `admin` middleware on the whole `/admin` group and
 * nothing further. §5.7 pins exactly nine policies and `ContactMessage` is not
 * one of them; a policy here would hold methods no route reaches, which is the
 * unreachable-and-therefore-untested trap §5.7 rejects `Gate::before` for.
 *
 * Reading a submission is an explicit action (§5.8): opening the inbox must not
 * mark anything, or an admin who merely glanced at the list would destroy the
 * unread signal the `read_at` column exists to carry.
 */
class MessageController extends Controller
{
    /**
     * The page size §5.9 fixes for every admin list.
     *
     * The page number is Laravel's own `?page=N`; §4.7's `page[number]` belongs
     * to the API and this controller never reads it.
     */
    private const PER_PAGE = 20;

    /**
     * `GET /admin/messages` — the inbox, newest first.
     */
    public function index(): Response
    {
        /** @var LengthAwarePaginator<int, ContactMessage> $records */
        $records = ContactMessage::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $records->through(fn (ContactMessage $message): array => $this->recordPayload($message));

        return Inertia::render('admin/messages/Index', [
            'records' => $records,
        ]);
    }

    /**
     * `PATCH /admin/messages/{contact_message}/read` — mark one submission read.
     *
     * The stamp is written once: re-activating the action leaves the original
     * `read_at` alone, so the column keeps answering when the message was first
     * opened. `read_at` is deliberately absent from the model's `#[Fillable]`
     * list (it is never part of a contact-form payload), so it is set as an
     * attribute rather than mass-assigned.
     */
    public function read(ContactMessage $contactMessage): RedirectResponse
    {
        if ($contactMessage->read_at === null) {
            $contactMessage->read_at = CarbonImmutable::now();
            $contactMessage->save();
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Message marked as read.',
        ]);

        return back();
    }

    /**
     * One submission as the flat payload the inbox table reads.
     *
     * The keys are §2.3's `contact_messages` columns, so the page never reaches
     * into a nested shape to render a row.
     *
     * @return array<string, mixed>
     */
    private function recordPayload(ContactMessage $message): array
    {
        return [
            'id' => $message->id,
            'name' => $message->name,
            'email' => $message->email,
            'message' => $message->message,
            'request_type' => $message->request_type,
            'ip_address' => $message->ip_address,
            'user_agent' => $message->user_agent,
            'created_at' => $message->created_at->toIso8601String(),
            'read_at' => $message->read_at?->toIso8601String(),
        ];
    }
}

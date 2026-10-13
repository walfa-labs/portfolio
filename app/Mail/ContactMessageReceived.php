<?php

namespace App\Mail;

use App\Models\ContactMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The notification that a contact submission arrived (§3.11).
 *
 * The `contact_messages` row is the source of truth; this mailable is a
 * convenience copy, so it is queued and carries the submitted body verbatim
 * with a reply-to set to the sender. It renders from inline HTML rather than a
 * Blade view so the notification cannot drift out of sync with the row.
 */
class ContactMessageReceived extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(public ContactMessage $contactMessage) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Contact form: '.($this->contactMessage->request_type ?? 'message'),
            replyTo: [
                new Address($this->contactMessage->email, $this->contactMessage->name),
            ],
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(htmlString: $this->renderHtml());
    }

    /**
     * Build the notification body.
     *
     * Every value is escaped: the row holds untrusted visitor input and the
     * message is the one field rendered as multi-line text.
     */
    private function renderHtml(): string
    {
        $rows = [
            'Name' => $this->contactMessage->name,
            'Email' => $this->contactMessage->email,
            'Request type' => $this->contactMessage->request_type ?? '—',
            'Received' => $this->contactMessage->created_at->toDateTimeString(),
        ];

        $summary = '';

        foreach ($rows as $label => $value) {
            $summary .= '<tr><th align="left" scope="row">'.e($label).'</th><td>'.e((string) $value).'</td></tr>';
        }

        return '<table>'.$summary.'</table>'
            .'<p style="white-space: pre-wrap">'.e($this->contactMessage->message).'</p>';
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}

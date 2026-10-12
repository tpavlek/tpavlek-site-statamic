<?php

namespace App\Newsletter;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The one Kit API call the site makes: save a broadcast as a draft.
 *
 * Kit is the list and the sender; the site only writes the email. Drafting rather than
 * sending is deliberate twice over: Kit's API can only save or schedule a broadcast, not
 * send one immediately, and a draft means every email gets a last look in Kit (subject,
 * recipients, a test send) before it reaches anyone.
 *
 * Uses Kit's v4 API, authenticated with a v4 key (they start `kit_`) in KIT_API_KEY.
 */
class Kit
{
    private const ENDPOINT = 'https://api.kit.com/v4/broadcasts';

    public function __construct(private ?string $key = null)
    {
        $this->key ??= config('services.kit.key');
    }

    /**
     * Sign someone up through the site's own subscribe form.
     *
     * Single opt-in, by choice (2026-10-12): someone who fills in the form is subscribed
     * straight away, with no confirmation email to lose them on the way. CASL is satisfied by
     * the consent line under the form, not by a confirmation step. The form's honeypot and
     * the route's throttle stand in for what double opt-in would otherwise catch.
     *
     * To switch to double opt-in, create the subscriber with `'state' => 'inactive'`: adding
     * an inactive subscriber to a form with double opt-in on is what makes Kit send its
     * confirmation email.
     *
     * The create call is an upsert, and it can't change an existing subscriber's state, so
     * someone already on the list (or who unsubscribed) stays exactly as they were.
     */
    public function subscribe(string $email, int $formId, ?string $referrer = null): void
    {
        if (! $this->key) {
            throw new RuntimeException('KIT_API_KEY is not set, so there is no Kit account to subscribe to.');
        }

        $request = fn () => Http::withHeaders(['X-Kit-Api-Key' => $this->key])->acceptJson()->timeout(15);

        $request()->post('https://api.kit.com/v4/subscribers', [
            'email_address' => $email,
            'state' => 'active',
        ])->throw();

        $request()->post("https://api.kit.com/v4/forms/{$formId}/subscribers", array_filter([
            'email_address' => $email,
            'referrer' => $referrer,
        ]))->throw();
    }

    /**
     * @return array{id: int, status: string}
     */
    public function draftBroadcast(string $subject, string $previewText, string $html, string $description, ?string $thumbnailUrl = null): array
    {
        if (! $this->key) {
            throw new RuntimeException('KIT_API_KEY is not set, so there is no Kit account to draft into.');
        }

        $payload = [
            'subject' => $subject,
            'preview_text' => $previewText,
            'content' => $html,
            // Kit's internal name for the broadcast, shown in its broadcast list.
            'description' => $description,
            // The site is the archive; a second public copy on Kit would compete with it.
            'public' => false,
            // null keeps it a draft. Kit's default email template wraps the content and adds
            // the unsubscribe footer. No subscriber_filter: Kit defaults to everyone, and the
            // recipients can still be narrowed in Kit before sending.
            'send_at' => null,
        ];

        if ($thumbnailUrl) {
            $payload['thumbnail_url'] = $thumbnailUrl;
        }

        $response = Http::withHeaders(['X-Kit-Api-Key' => $this->key])
            ->acceptJson()
            ->timeout(30)
            ->post(self::ENDPOINT, $payload);

        if ($response->failed()) {
            $errors = collect($response->json('errors') ?? [])->implode('; ');

            throw new RuntimeException('Kit refused the draft ('.$response->status().')'.($errors ? ": {$errors}" : '.'));
        }

        return [
            'id' => (int) $response->json('broadcast.id'),
            'status' => (string) $response->json('broadcast.status'),
        ];
    }
}

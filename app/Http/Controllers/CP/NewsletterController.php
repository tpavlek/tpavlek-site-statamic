<?php

namespace App\Http\Controllers\CP;

use App\Http\Controllers\Controller;
use App\Newsletter\PostEmail;
use Illuminate\Http\Response;
use Statamic\Facades\Entry;

/**
 * The newsletter version of a post, as a page, so it can be read before it is sent.
 *
 * A CP route, so only someone logged in can see it. The body is exactly what goes to Kit;
 * the grey surround and the footer note stand in for Kit's own email template, which
 * supplies the real width, unsubscribe link and postal address when the broadcast is sent.
 */
class NewsletterController extends Controller
{
    public function preview(string $entryId): Response
    {
        $entry = Entry::find($entryId);

        abort_unless($entry && PostEmail::supports($entry), 404);

        $email = new PostEmail($entry);

        $subject = e($email->subject());
        $preview = e($email->previewText());

        return response(<<<HTML
            <!doctype html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <meta name="robots" content="noindex">
                <title>Newsletter preview: {$subject}</title>
            </head>
            <body style="margin:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;">
                <!-- Not part of the email: how it shows in an inbox list. The preview text is
                     sent hidden; readers see it beside the subject, never in the body. -->
                <p style="max-width:600px;margin:24px auto 6px;font-size:11px;letter-spacing:1px;text-transform:uppercase;color:#9ca3af;">In the inbox</p>
                <div style="max-width:600px;margin:0 auto 28px;background:#ffffff;border:1px solid #e5e7eb;border-radius:8px;padding:10px 14px;font-size:14px;color:#6b7280;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                    <strong style="color:#111827;">Troy Pavlek</strong> &nbsp; <span style="color:#111827;">{$subject}</span> &ndash; {$preview}
                </div>
                <p style="max-width:600px;margin:0 auto 6px;font-size:11px;letter-spacing:1px;text-transform:uppercase;color:#9ca3af;">The email</p>
                <div style="max-width:600px;margin:0 auto 24px;background:#ffffff;padding:32px 28px;border-radius:8px;">
                    {$email->html()}
                </div>
                <p style="max-width:600px;margin:0 auto 40px;text-align:center;font-size:12px;color:#9ca3af;">
                    Kit adds its unsubscribe link and your mailing address below this when it sends.
                </p>
            </body>
            </html>
            HTML);
    }
}

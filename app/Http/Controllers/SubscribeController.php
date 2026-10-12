<?php

namespace App\Http\Controllers;

use App\Newsletter\Kit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The site's own subscribe form (resources/views/_email-subscribe), posting here instead
 * of to Kit's embed script.
 *
 * Answers JSON for the Alpine component and redirects back with a flash for anyone without
 * JavaScript, so the form works either way. A filled-in honeypot field gets the success
 * response and nothing else: a bot that's told it failed just tries again.
 */
class SubscribeController extends Controller
{
    public function __invoke(Request $request, Kit $kit)
    {
        if (filled($request->input('website'))) {
            return $this->respond($request, true);
        }

        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:254']]);

        try {
            $kit->subscribe(
                strtolower(trim($data['email'])),
                (int) config('services.kit.form_id'),
                $request->input('referrer') ?: $request->headers->get('referer'),
            );
        } catch (Throwable $e) {
            Log::warning('Newsletter signup failed', ['message' => $e->getMessage()]);

            return $this->respond($request, false);
        }

        return $this->respond($request, true);
    }

    private function respond(Request $request, bool $ok)
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => $ok], $ok ? 200 : 502);
        }

        return redirect()->to(url()->previous().'#subscribe')->with('subscribe_status', $ok ? 'done' : 'error');
    }
}

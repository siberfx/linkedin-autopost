<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Http\Controllers;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Siberfx\LinkedInAutopost\Exceptions\NotConfigured;
use Siberfx\LinkedInAutopost\LinkedInManager;
use Throwable;

final class OAuthController extends Controller
{
    private const SESSION_KEY = 'linkedin-autopost.state';

    public function __construct(private readonly LinkedInManager $linkedin) {}

    public function redirect(Request $request): RedirectResponse
    {
        $state = Str::random(40);

        try {
            $url = $this->linkedin->authorizationUrl($state);
        } catch (NotConfigured) {
            return $this->back('not_configured');
        }

        $request->session()->put(self::SESSION_KEY, $state);

        return redirect()->away($url);
    }

    public function callback(Request $request): RedirectResponse
    {
        $expected = $request->session()->pull(self::SESSION_KEY);
        $state = $request->query('state');

        if (! is_string($expected) || ! is_string($state) || ! hash_equals($expected, $state)) {
            return $this->back('invalid_state');
        }

        $error = $request->query('error');

        if (is_string($error) && $error !== '') {
            if (str_starts_with($error, 'user_cancelled')) {
                return $this->back('cancelled');
            }

            $reason = $request->query('error_description');

            return $this->back('error', ['reason' => Str::limit(is_string($reason) && $reason !== '' ? $reason : $error, 300)]);
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            return $this->back('missing_code');
        }

        try {
            $this->linkedin->completeConnection($code);
        } catch (Throwable $e) {
            Log::warning('LinkedIn token exchange failed.', [
                'error' => $e instanceof RequestException ? $e->response->body() : $e->getMessage(),
            ]);

            return $this->back('exchange_failed');
        }

        return $this->back('connected');
    }

    /** @param  array<string, string>  $extra */
    private function back(string $status, array $extra = []): RedirectResponse
    {
        $target = (string) config('linkedin-autopost.routes.after_connect', '/');
        $glue = str_contains($target, '?') ? '&' : '?';

        return redirect()->to($target.$glue.http_build_query(['linkedin' => $status] + $extra));
    }
}

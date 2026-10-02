<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Support;

use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;

final class StatusMessages
{
    public const FLASH_KEY = 'linkedin-autopost.flash';

    /** @return array{type: string, message: string}|null */
    public static function fromCallback(?string $status, ?string $reason = null): ?array
    {
        return match ($status) {
            'connected' => ['type' => 'success', 'message' => 'LinkedIn connected.'],
            'cancelled' => ['type' => 'error', 'message' => 'LinkedIn connection cancelled.'],
            'error' => ['type' => 'error', 'message' => 'LinkedIn refused the connection: '.($reason !== null && $reason !== '' ? $reason : 'unknown error')],
            'exchange_failed' => ['type' => 'error', 'message' => 'LinkedIn refused the token exchange. Check that the redirect URL is registered on the LinkedIn app exactly.'],
            'invalid_state' => ['type' => 'error', 'message' => 'The LinkedIn login expired. Connect again.'],
            'missing_code' => ['type' => 'error', 'message' => 'LinkedIn did not return an authorization code.'],
            'not_configured' => ['type' => 'error', 'message' => 'Set LINKEDIN_CLIENT_ID and LINKEDIN_CLIENT_SECRET first.'],
            default => null,
        };
    }

    /** @return array{type: string, message: string}|null */
    public static function current(Request $request): ?array
    {
        $flash = self::session($request)?->get(self::FLASH_KEY);

        if (is_array($flash) && isset($flash['type'], $flash['message'])) {
            return ['type' => (string) $flash['type'], 'message' => (string) $flash['message']];
        }

        $status = $request->query('linkedin');
        $reason = $request->query('reason');

        return self::fromCallback(is_string($status) ? $status : null, is_string($reason) ? $reason : null);
    }

    private static function session(Request $request): ?Session
    {
        if ($request->hasSession()) {
            return $request->session();
        }

        return app()->bound('session.store') ? app('session.store') : null;
    }
}

<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Siberfx\LinkedInAutopost\LinkedInManager;
use Siberfx\LinkedInAutopost\Support\StatusMessages;

final class ConnectionController extends Controller
{
    public function __construct(private readonly LinkedInManager $linkedin) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->linkedin->connection()->toArray()]);
    }

    public function destroy(Request $request): JsonResponse|RedirectResponse
    {
        $revoked = $this->linkedin->disconnect();
        $message = $revoked
            ? 'LinkedIn disconnected.'
            : 'LinkedIn disconnected here, but LinkedIn did not confirm revoking the token. Remove the app under LinkedIn → Settings → Data privacy → Permitted services if needed.';

        if (! $request->expectsJson()) {
            return back()->with(StatusMessages::FLASH_KEY, ['type' => 'success', 'message' => $message]);
        }

        return response()->json([
            'message' => $message,
            'revoked' => $revoked,
            'data' => $this->linkedin->connection()->toArray(),
        ]);
    }
}

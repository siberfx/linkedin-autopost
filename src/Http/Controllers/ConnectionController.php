<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Siberfx\LinkedInAutopost\LinkedInManager;

final class ConnectionController extends Controller
{
    public function __construct(private readonly LinkedInManager $linkedin) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->linkedin->connection()->toArray()]);
    }

    public function destroy(): JsonResponse
    {
        $revoked = $this->linkedin->disconnect();

        return response()->json([
            'message' => $revoked
                ? 'LinkedIn disconnected.'
                : 'LinkedIn disconnected here, but LinkedIn did not confirm revoking the token. Remove the app under LinkedIn → Settings → Data privacy → Permitted services if needed.',
            'revoked' => $revoked,
            'data' => $this->linkedin->connection()->toArray(),
        ]);
    }
}

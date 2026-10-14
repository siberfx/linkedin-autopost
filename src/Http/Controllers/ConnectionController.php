<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;
use Siberfx\LinkedInAutopost\Exceptions\NotConnected;
use Siberfx\LinkedInAutopost\LinkedInManager;
use Siberfx\LinkedInAutopost\Support\StatusMessages;
use Siberfx\LinkedInAutopost\Support\Ui;

final class ConnectionController extends Controller
{
    public function __construct(private readonly LinkedInManager $linkedin) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->linkedin->connection()->toArray()]);
    }

    /** Where posts can go and which pages are selected. */
    public function pages(): JsonResponse
    {
        $connection = $this->linkedin->connection();
        $data = $connection->toArray();

        return response()->json(['data' => [
            'pages' => $data['pages'],
            'selected_pages' => $connection->selectedPages,
        ]]);
    }

    /** Choose the pages posts go to: {"pages": ["urn:li:person:…", "urn:li:organization:…"]}. */
    public function updatePages(Request $request): JsonResponse|RedirectResponse
    {
        $html = ! $request->expectsJson() && Ui::enabled();
        $pages = $request->input('pages');

        try {
            if (! is_array($pages)) {
                throw new InvalidArgumentException('Choose at least one LinkedIn page.');
            }

            $connection = $this->linkedin->selectPages(array_values($pages));
        } catch (NotConnected|InvalidArgumentException $e) {
            return $html
                ? back()->with(StatusMessages::FLASH_KEY, ['type' => 'error', 'message' => $e->getMessage()])
                : response()->json(['message' => $e->getMessage()], 422);
        }

        $message = 'LinkedIn pages saved.';

        if ($html) {
            return back()->with(StatusMessages::FLASH_KEY, ['type' => 'success', 'message' => $message]);
        }

        return response()->json(['message' => $message, 'data' => $connection->toArray()]);
    }

    public function destroy(Request $request): JsonResponse|RedirectResponse
    {
        $revoked = $this->linkedin->disconnect();
        $message = $revoked
            ? 'LinkedIn disconnected.'
            : 'LinkedIn disconnected here, but LinkedIn did not confirm revoking the token. Remove the app under LinkedIn → Settings → Data privacy → Permitted services if needed.';

        if (! $request->expectsJson() && Ui::enabled()) {
            return back()->with(StatusMessages::FLASH_KEY, ['type' => 'success', 'message' => $message]);
        }

        return response()->json([
            'message' => $message,
            'revoked' => $revoked,
            'data' => $this->linkedin->connection()->toArray(),
        ]);
    }
}

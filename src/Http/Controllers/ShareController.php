<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;
use Siberfx\LinkedInAutopost\Contracts\ShareableOnLinkedIn;
use Siberfx\LinkedInAutopost\Exceptions\LinkedInRequestFailed;
use Siberfx\LinkedInAutopost\Exceptions\NotConnected;
use Siberfx\LinkedInAutopost\Exceptions\NotShareable;
use Siberfx\LinkedInAutopost\LinkedInManager;
use Siberfx\LinkedInAutopost\Models\LinkedInPost;
use Siberfx\LinkedInAutopost\Support\StatusMessages;

final class ShareController extends Controller
{
    /** Alias-only route: a class name from the URL is never used. */
    public function __invoke(Request $request, LinkedInManager $linkedin, string $type, string $id): JsonResponse|RedirectResponse
    {
        return $this->share($request, $linkedin, Relation::getMorphedModel($type), $id);
    }

    /** Signed route used by the share button; the app signed the class name, so it may be used. */
    public function signed(Request $request, LinkedInManager $linkedin): JsonResponse|RedirectResponse
    {
        $type = (string) $request->query('type', '');

        return $this->share($request, $linkedin, Relation::getMorphedModel($type) ?? $type, (string) $request->query('id', ''));
    }

    private function share(Request $request, LinkedInManager $linkedin, ?string $class, string $id): JsonResponse|RedirectResponse
    {
        abort_if($class === null || ! class_exists($class) || ! is_subclass_of($class, Model::class) || ! is_subclass_of($class, ShareableOnLinkedIn::class), 404);

        /** @var (Model&ShareableOnLinkedIn)|null $model */
        $model = $class::query()->find($id);
        abort_if($model === null, 404);

        try {
            $post = $linkedin->share($model, LinkedInPost::TRIGGER_MANUAL);
        } catch (NotConnected|NotShareable|InvalidArgumentException $e) {
            // InvalidArgumentException: the model's toLinkedInPost() built an invalid post.
            return $this->respond($request, 422, $e->getMessage());
        } catch (LinkedInRequestFailed $e) {
            return $this->respond($request, 502, $e->getMessage());
        }

        return $this->respond($request, 201, 'Shared on LinkedIn.', [
            'post_urn' => $post->post_urn,
            'posted_at' => $post->posted_at?->toIso8601String(),
        ]);
    }

    /** @param  array<string, mixed>  $data */
    private function respond(Request $request, int $status, string $message, array $data = []): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message] + ($data === [] ? [] : ['data' => $data]), $status);
        }

        return back()->with(StatusMessages::FLASH_KEY, ['type' => $status < 300 ? 'success' : 'error', 'message' => $message]);
    }
}

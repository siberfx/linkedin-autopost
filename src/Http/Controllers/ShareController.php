<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Siberfx\LinkedInAutopost\Contracts\ShareableOnLinkedIn;
use Siberfx\LinkedInAutopost\Exceptions\LinkedInRequestFailed;
use Siberfx\LinkedInAutopost\Exceptions\NotConnected;
use Siberfx\LinkedInAutopost\Exceptions\NotShareable;
use Siberfx\LinkedInAutopost\LinkedInManager;
use Siberfx\LinkedInAutopost\Models\LinkedInPost;

final class ShareController extends Controller
{
    public function __invoke(LinkedInManager $linkedin, string $type, string $id): JsonResponse
    {
        // Morph-map aliases only: a class name from the URL is never instantiated.
        $class = Relation::getMorphedModel($type);

        abort_if($class === null || ! is_subclass_of($class, Model::class) || ! is_subclass_of($class, ShareableOnLinkedIn::class), 404);

        /** @var (Model&ShareableOnLinkedIn)|null $model */
        $model = $class::query()->find($id);
        abort_if($model === null, 404);

        try {
            $post = $linkedin->share($model, LinkedInPost::TRIGGER_MANUAL);
        } catch (NotConnected|NotShareable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (LinkedInRequestFailed $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json([
            'message' => 'Shared on LinkedIn.',
            'data' => [
                'post_urn' => $post->post_urn,
                'posted_at' => $post->posted_at?->toIso8601String(),
            ],
        ], 201);
    }
}

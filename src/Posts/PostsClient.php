<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Posts;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Siberfx\LinkedInAutopost\Exceptions\LinkedInRequestFailed;
use Throwable;

/** The only class that publishes to LinkedIn: POST /rest/posts, and the preview image through the Images API. */
final class PostsClient
{
    private const URL = 'https://api.linkedin.com/rest/posts';

    private const IMAGES = 'https://api.linkedin.com/rest/images?action=initializeUpload';

    /**
     * @return string|null The post URN; null when LinkedIn accepted the post without returning its id.
     *
     * @throws LinkedInRequestFailed
     */
    public function create(string $accessToken, string $authorUrn, LinkPost $post): ?string
    {
        $article = array_filter([
            'source' => $post->url(),
            'title' => $post->title(),
            'description' => $post->descriptionText(),
        ], fn (string $value) => $value !== '');

        $thumbnail = $this->uploadImage($accessToken, $authorUrn, $post->imagePath());

        if ($thumbnail !== null) {
            $article['thumbnail'] = $thumbnail;
        }

        try {
            $response = $this->rest($accessToken)
                ->post(self::URL, [
                    'author' => $authorUrn,
                    'commentary' => LittleText::escape($post->commentaryText()),
                    'visibility' => (string) config('linkedin-autopost.visibility', 'PUBLIC'),
                    'distribution' => [
                        'feedDistribution' => 'MAIN_FEED',
                        'targetEntities' => [],
                        'thirdPartyDistributionChannels' => [],
                    ],
                    'content' => ['article' => $article],
                    'lifecycleState' => 'PUBLISHED',
                    'isReshareDisabledByAuthor' => false,
                ]);
        } catch (ConnectionException $e) {
            throw new LinkedInRequestFailed('Could not reach LinkedIn: '.$e->getMessage(), 0);
        }

        if ($response->failed()) {
            throw LinkedInRequestFailed::fromResponse($response);
        }

        // A 2xx means the post exists; never fail it for a missing id, or a retry would post twice.
        $urn = $response->header('x-restli-id') ?: $response->json('id');

        return is_string($urn) && $urn !== '' ? $urn : null;
    }

    /**
     * Upload the preview image and return its URN, or null without a readable
     * file. Best effort: a failed upload is logged and the post goes out without
     * the image rather than not at all. The image belongs to the page posting it.
     */
    private function uploadImage(string $accessToken, string $owner, ?string $path): ?string
    {
        if ($path === null || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        try {
            $init = $this->rest($accessToken)
                ->post(self::IMAGES, ['initializeUploadRequest' => ['owner' => $owner]])
                ->throw();

            $uploadUrl = $init->json('value.uploadUrl');
            $image = $init->json('value.image');

            if (! is_string($uploadUrl) || $uploadUrl === '' || ! is_string($image) || $image === '') {
                throw new RuntimeException('LinkedIn returned no upload URL for the image.');
            }

            Http::withToken($accessToken)
                ->timeout(max(60, (int) config('linkedin-autopost.http.timeout', 20)))
                ->withBody((string) file_get_contents($path), mime_content_type($path) ?: 'application/octet-stream')
                ->put($uploadUrl)
                ->throw();

            return $image;
        } catch (Throwable $e) {
            Log::warning('LinkedIn image upload failed; posting without it.', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function rest(string $accessToken): PendingRequest
    {
        return Http::withToken($accessToken)
            ->timeout((int) config('linkedin-autopost.http.timeout', 20))
            ->acceptJson()
            ->withHeaders([
                'X-Restli-Protocol-Version' => '2.0.0',
                'LinkedIn-Version' => (string) config('linkedin-autopost.api_version', '202609'),
            ]);
    }
}

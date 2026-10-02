<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Posts;

use Illuminate\Support\Facades\Http;
use Siberfx\LinkedInAutopost\Exceptions\LinkedInRequestFailed;

/** The only class that publishes to LinkedIn: POST /rest/posts. */
final class PostsClient
{
    private const URL = 'https://api.linkedin.com/rest/posts';

    /** @throws LinkedInRequestFailed */
    public function create(string $accessToken, string $authorUrn, LinkPost $post): string
    {
        $article = array_filter([
            'source' => $post->url(),
            'title' => $post->title(),
            'description' => $post->descriptionText(),
        ], fn (string $value) => $value !== '');

        $response = Http::withToken($accessToken)
            ->timeout((int) config('linkedin-autopost.http.timeout', 20))
            ->acceptJson()
            ->withHeaders([
                'X-Restli-Protocol-Version' => '2.0.0',
                'LinkedIn-Version' => (string) config('linkedin-autopost.api_version', '202609'),
            ])
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

        if ($response->failed()) {
            throw LinkedInRequestFailed::fromResponse($response);
        }

        $urn = $response->header('x-restli-id') ?: $response->json('id');

        if (! is_string($urn) || $urn === '') {
            throw new LinkedInRequestFailed('LinkedIn accepted the post but returned no post id.', $response->status());
        }

        return $urn;
    }
}

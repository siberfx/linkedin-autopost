<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\OAuth;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Siberfx\LinkedInAutopost\Data\StoredConnection;
use Siberfx\LinkedInAutopost\Exceptions\LinkedInRequestFailed;
use Siberfx\LinkedInAutopost\Exceptions\NotConfigured;
use Throwable;

/** Everything that talks to LinkedIn's OAuth and identity endpoints. */
final class OAuthClient
{
    private const AUTHORIZE = 'https://www.linkedin.com/oauth/v2/authorization';

    private const TOKEN = 'https://www.linkedin.com/oauth/v2/accessToken';

    private const INTROSPECT = 'https://www.linkedin.com/oauth/v2/introspectToken';

    private const REVOKE = 'https://www.linkedin.com/oauth/v2/revoke';

    private const USERINFO = 'https://api.linkedin.com/v2/userinfo';

    /** Sent on both the authorization request and the token exchange, so they always match. */
    public function redirectUri(): string
    {
        $configured = config('linkedin-autopost.redirect_uri');

        return is_string($configured) && $configured !== ''
            ? $configured
            : route(config('linkedin-autopost.routes.name', 'linkedin-autopost.').'callback');
    }

    public function authorizationUrl(string $state): string
    {
        [$clientId] = $this->credentials();

        return self::AUTHORIZE.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
            'scope' => implode(' ', (array) config('linkedin-autopost.scopes', [])),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @throws LinkedInRequestFailed */
    public function exchange(string $code): StoredConnection
    {
        [$clientId, $clientSecret] = $this->credentials();

        $token = $this->http()->asForm()->post(self::TOKEN, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]);

        if ($token->failed()) {
            throw LinkedInRequestFailed::fromResponse($token);
        }

        $accessToken = $token->json('access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            throw new LinkedInRequestFailed('LinkedIn returned no access token.', $token->status());
        }

        $profile = $this->http()->withToken($accessToken)->acceptJson()->get(self::USERINFO);

        if ($profile->failed()) {
            throw LinkedInRequestFailed::fromResponse($profile);
        }

        $sub = $profile->json('sub');

        if (! is_string($sub) || $sub === '') {
            throw new LinkedInRequestFailed('LinkedIn returned no member id (sub); is "Sign In with LinkedIn using OpenID Connect" added to the app?');
        }

        $now = CarbonImmutable::now();
        $expiresIn = $token->json('expires_in');

        return new StoredConnection(
            accessToken: $accessToken,
            authorUrn: 'urn:li:person:'.$sub,
            name: self::stringOrNull($profile->json('name')),
            email: self::stringOrNull($profile->json('email')),
            picture: self::stringOrNull($profile->json('picture')),
            scopes: self::scopes($token->json('scope')),
            expiresAt: is_numeric($expiresIn) ? $now->addSeconds((int) $expiresIn) : null,
            connectedAt: $now,
        );
    }

    /** @return array<string, mixed>|null */
    public function introspect(string $token): ?array
    {
        [$clientId, $clientSecret] = $this->credentials();

        return $this->jsonOrNull(fn () => $this->http()->asForm()->post(self::INTROSPECT, [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'token' => $token,
        ]));
    }

    /** @return array<string, mixed>|null */
    public function userinfo(string $token): ?array
    {
        return $this->jsonOrNull(fn () => $this->http()->withToken($token)->acceptJson()->get(self::USERINFO));
    }

    public function revoke(string $token): bool
    {
        [$clientId, $clientSecret] = $this->credentials();

        try {
            return $this->http()->asForm()->post(self::REVOKE, [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'token' => $token,
            ])->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /** @return list<string> */
    public static function scopes(mixed $scope): array
    {
        if (! is_string($scope) || $scope === '') {
            return [];
        }

        return array_values(array_filter(preg_split('/[\s,]+/', $scope) ?: []));
    }

    /** @return array{0: string, 1: string} */
    private function credentials(): array
    {
        $id = config('linkedin-autopost.client_id');
        $secret = config('linkedin-autopost.client_secret');

        if (! is_string($id) || $id === '' || ! is_string($secret) || $secret === '') {
            throw new NotConfigured;
        }

        return [$id, $secret];
    }

    private function http(): PendingRequest
    {
        return Http::timeout((int) config('linkedin-autopost.http.timeout', 20));
    }

    /**
     * @param  callable(): Response  $request
     * @return array<string, mixed>|null
     */
    private function jsonOrNull(callable $request): ?array
    {
        try {
            $response = $request();
        } catch (Throwable) {
            return null;
        }

        $json = $response->successful() ? $response->json() : null;

        return is_array($json) ? $json : null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}

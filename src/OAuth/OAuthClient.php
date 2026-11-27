<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\OAuth;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Siberfx\LinkedInAutopost\Data\Page;
use Siberfx\LinkedInAutopost\Data\StoredConnection;
use Siberfx\LinkedInAutopost\Exceptions\LinkedInRequestFailed;
use Siberfx\LinkedInAutopost\Exceptions\NotConfigured;
use Throwable;

/** Everything that talks to LinkedIn's OAuth, identity and organization endpoints. */
final class OAuthClient
{
    private const ORGANIZATION_ACLS = 'https://api.linkedin.com/rest/organizationAcls';

    private const ORGANIZATIONS = 'https://api.linkedin.com/rest/organizations/';

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
            'scope' => implode(' ', $this->requestedScopes()),
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
        $member = new Page(
            id: 'urn:li:person:'.$sub,
            type: Page::TYPE_MEMBER,
            name: self::stringOrNull($profile->json('name')),
            picture: self::stringOrNull($profile->json('picture')),
        );

        return new StoredConnection(
            accessToken: $accessToken,
            authorUrn: $member->id,
            name: $member->name,
            email: self::stringOrNull($profile->json('email')),
            picture: $member->picture,
            scopes: self::scopes($token->json('scope')),
            expiresAt: is_numeric($expiresIn) ? $now->addSeconds((int) $expiresIn) : null,
            connectedAt: $now,
            pages: [$member, ...$this->organizations($accessToken)],
            // Posts keep going to the member's profile until other pages are chosen.
            selectedPages: [$member->id],
        );
    }

    /**
     * The LinkedIn Pages the member may post as, when organizations are
     * enabled. Never throws: a page lookup that fails must not fail connecting,
     * so it logs and returns what it has.
     *
     * @return list<Page>
     */
    public function organizations(string $token): array
    {
        if (! config('linkedin-autopost.organizations.enabled', false)) {
            return [];
        }

        try {
            $acls = $this->rest($token)->get(self::ORGANIZATION_ACLS, [
                'q' => 'roleAssignee',
                'state' => 'APPROVED',
                'count' => 100,
            ]);
        } catch (Throwable $e) {
            Log::warning('Could not list the LinkedIn Pages of the member.', ['error' => $e->getMessage()]);

            return [];
        }

        if ($acls->failed()) {
            Log::warning('Could not list the LinkedIn Pages of the member.', ['status' => $acls->status(), 'error' => $acls->body()]);

            return [];
        }

        $roles = array_map('strval', (array) config('linkedin-autopost.organizations.roles', ['ADMINISTRATOR', 'CONTENT_ADMINISTRATOR']));
        $only = self::onlyOrganizations();
        $urns = [];

        foreach ((array) $acls->json('elements', []) as $acl) {
            // "organization" since the versioned API; "organizationalTarget" before it.
            $urn = is_array($acl) ? ($acl['organization'] ?? $acl['organizationalTarget'] ?? null) : null;

            if (is_string($urn) && str_starts_with($urn, 'urn:li:organization:') && in_array($acl['role'] ?? null, $roles, true)
                && ($only === [] || in_array($urn, $only, true))) {
                $urns[$urn] = true;
            }
        }

        return array_map(fn (string $urn) => new Page(
            id: $urn,
            type: Page::TYPE_ORGANIZATION,
            name: $this->organizationName($token, $urn),
        ), array_keys($urns));
    }

    /**
     * organizations.only as URNs: ids ("2414183") or URNs, in an array or a
     * comma-separated string. Empty means every Page the member administers.
     *
     * @return list<string>
     */
    public static function onlyOrganizations(): array
    {
        $only = config('linkedin-autopost.organizations.only', []);
        $items = is_string($only) ? explode(',', $only) : (array) $only;
        $urns = [];

        foreach ($items as $item) {
            $item = is_scalar($item) ? trim((string) $item) : '';

            if ($item !== '') {
                $urns[] = str_starts_with($item, 'urn:li:organization:') ? $item : 'urn:li:organization:'.$item;
            }
        }

        return array_values(array_unique($urns));
    }

    private function organizationName(string $token, string $urn): ?string
    {
        $organization = $this->jsonOrNull(fn () => $this->rest($token)->get(self::ORGANIZATIONS.substr($urn, strlen('urn:li:organization:'))));

        return self::stringOrNull($organization['localizedName'] ?? null);
    }

    /** @return list<string> The configured scopes, plus the organization and community scopes when each is enabled. */
    public function requestedScopes(): array
    {
        $scopes = array_map('strval', (array) config('linkedin-autopost.scopes', []));

        foreach (['organizations', 'community'] as $feature) {
            if (config("linkedin-autopost.{$feature}.enabled", false)) {
                $scopes = [...$scopes, ...array_map('strval', (array) config("linkedin-autopost.{$feature}.scopes", []))];
            }
        }

        return array_values(array_unique($scopes));
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

    /** The versioned REST API (/rest/…) needs these headers. */
    private function rest(string $token): PendingRequest
    {
        return $this->http()->withToken($token)->acceptJson()->withHeaders([
            'X-Restli-Protocol-Version' => '2.0.0',
            'LinkedIn-Version' => (string) config('linkedin-autopost.api_version', '202609'),
        ]);
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

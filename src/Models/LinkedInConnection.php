<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $access_token
 * @property string $author_urn
 * @property string|null $name
 * @property string|null $email
 * @property string|null $picture
 * @property list<string>|null $scopes
 * @property array<mixed>|null $pages
 * @property array<mixed>|null $selected_pages
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $connected_at
 */
final class LinkedInConnection extends Model
{
    protected $table = 'linkedin_connections';

    protected $guarded = [];

    protected $hidden = ['access_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'scopes' => 'array',
            'pages' => 'array',
            'selected_pages' => 'array',
            'expires_at' => 'immutable_datetime',
            'connected_at' => 'immutable_datetime',
        ];
    }
}

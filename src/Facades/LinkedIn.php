<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Facades;

use Illuminate\Support\Facades\Facade;
use Siberfx\LinkedInAutopost\LinkedInManager;

/**
 * @method static bool isConnected()
 * @method static \Siberfx\LinkedInAutopost\Data\Connection connection()
 * @method static \Siberfx\LinkedInAutopost\Models\LinkedInPost share(\Illuminate\Database\Eloquent\Model&\Siberfx\LinkedInAutopost\Contracts\ShareableOnLinkedIn $model, string $trigger = 'manual')
 * @method static void queue(\Illuminate\Database\Eloquent\Model&\Siberfx\LinkedInAutopost\Contracts\ShareableOnLinkedIn $model)
 * @method static \Illuminate\Support\Collection<int, \Siberfx\LinkedInAutopost\Models\LinkedInPost> shareToPages(\Illuminate\Database\Eloquent\Model&\Siberfx\LinkedInAutopost\Contracts\ShareableOnLinkedIn $model, list<string>|null $pages = null, string $trigger = 'manual')
 * @method static \Siberfx\LinkedInAutopost\Models\LinkedInPost shareToPage(\Illuminate\Database\Eloquent\Model&\Siberfx\LinkedInAutopost\Contracts\ShareableOnLinkedIn $model, string $page, string $trigger = 'manual', \Siberfx\LinkedInAutopost\Posts\LinkPost|null $post = null)
 * @method static list<\Siberfx\LinkedInAutopost\Data\Page> pages()
 * @method static list<\Siberfx\LinkedInAutopost\Data\Page> selectedPages()
 * @method static \Siberfx\LinkedInAutopost\Data\Connection selectPages(list<string> $pages)
 * @method static bool wasPosted(\Illuminate\Database\Eloquent\Model $model, string|null $page = null)
 * @method static bool disconnect()
 * @method static string authorizationUrl(string $state)
 * @method static \Siberfx\LinkedInAutopost\Data\Connection completeConnection(string $code)
 *
 * @see LinkedInManager
 */
final class LinkedIn extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return LinkedInManager::class;
    }
}

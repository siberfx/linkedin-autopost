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
 * @method static bool wasPosted(\Illuminate\Database\Eloquent\Model $model)
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

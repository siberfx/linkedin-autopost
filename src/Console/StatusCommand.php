<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Console;

use Illuminate\Console\Command;
use Siberfx\LinkedInAutopost\LinkedInManager;

final class StatusCommand extends Command
{
    protected $signature = 'linkedin:status';

    protected $description = 'Show the connected LinkedIn account and its token status';

    public function handle(LinkedInManager $linkedin): int
    {
        $connection = $linkedin->connection();

        if (! $connection->connected) {
            $this->components->warn('Not connected to LinkedIn.');

            return self::SUCCESS;
        }

        $this->table(['Field', 'Value'], [
            ['Member', $connection->name ?? '—'],
            ['Email', $connection->email ?? '—'],
            ['Author', $connection->authorUrn ?? '—'],
            ['Status', $connection->status ?? '—'],
            ['Scopes', implode(', ', $connection->scopes) ?: '—'],
            ['Connected', $connection->connectedAt?->toDateTimeString() ?? '—'],
            ['Expires', $connection->expiresAt?->toDateTimeString() ?? '—'],
            ['Days left', (string) ($connection->daysLeft() ?? '—')],
        ]);

        return self::SUCCESS;
    }
}

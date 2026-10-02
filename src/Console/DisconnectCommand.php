<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Console;

use Illuminate\Console\Command;
use Siberfx\LinkedInAutopost\LinkedInManager;

final class DisconnectCommand extends Command
{
    protected $signature = 'linkedin:disconnect {--force : Do not ask for confirmation}';

    protected $description = 'Revoke and forget the LinkedIn connection';

    public function handle(LinkedInManager $linkedin): int
    {
        if (! $linkedin->isConnected()) {
            $this->components->info('Not connected to LinkedIn.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Disconnect LinkedIn and revoke the token?')) {
            return self::FAILURE;
        }

        $revoked = $linkedin->disconnect();

        $revoked
            ? $this->components->info('Disconnected. LinkedIn revoked the token.')
            : $this->components->warn('Disconnected here, but LinkedIn did not confirm revoking the token.');

        return self::SUCCESS;
    }
}

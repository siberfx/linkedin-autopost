<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Console;

use Illuminate\Console\Command;
use Siberfx\LinkedInAutopost\Events\TokenExpiringSoon;
use Siberfx\LinkedInAutopost\LinkedInManager;

final class CheckTokenCommand extends Command
{
    protected $signature = 'linkedin:check-token {--days= : Warn when fewer days remain (default from config)}';

    protected $description = 'Fire TokenExpiringSoon when the LinkedIn token is about to expire';

    public function handle(LinkedInManager $linkedin): int
    {
        $connection = $linkedin->connection();

        if (! $connection->connected) {
            $this->components->info('Not connected to LinkedIn.');

            return self::SUCCESS;
        }

        $days = (int) ($this->option('days') ?? config('linkedin-autopost.expiry_warning_days', 7));
        $left = $connection->daysLeft();

        if ($left === null && ! in_array($connection->status, ['expired', 'revoked'], true)) {
            $this->components->info('The token expiry is unknown.');

            return self::SUCCESS;
        }

        $left ??= 0;

        if ($left <= $days || in_array($connection->status, ['expired', 'revoked'], true)) {
            event(new TokenExpiringSoon($connection, $left));
            $this->components->warn("The LinkedIn token expires in {$left} days. Connect again to renew it.");

            return self::SUCCESS;
        }

        $this->components->info("The LinkedIn token is valid for {$left} more days.");

        return self::SUCCESS;
    }
}

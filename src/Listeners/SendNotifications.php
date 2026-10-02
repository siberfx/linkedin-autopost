<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Listeners;

use Siberfx\LinkedInAutopost\Events\Shared;
use Siberfx\LinkedInAutopost\Events\ShareFailed;
use Siberfx\LinkedInAutopost\Events\TokenExpiringSoon;
use Siberfx\LinkedInAutopost\Notifications\Message;
use Siberfx\LinkedInAutopost\Notifications\Notifier;
use Throwable;

/** Turns the package events into Telegram/Slack messages when linkedin-autopost.notifications says so. */
final class SendNotifications
{
    public function __construct(private readonly Notifier $notifier) {}

    public function handle(Shared|ShareFailed|TokenExpiringSoon $event): void
    {
        $key = match (true) {
            $event instanceof Shared => 'shared',
            $event instanceof ShareFailed => 'failed',
            $event instanceof TokenExpiringSoon => 'token_expiring',
        };

        if (! $this->notifier->wants($key)) {
            return;
        }

        try {
            $message = match (true) {
                $event instanceof Shared => Message::shared($event),
                $event instanceof ShareFailed => Message::failed($event),
                $event instanceof TokenExpiringSoon => Message::tokenExpiring($event),
            };
        } catch (Throwable $e) {
            report($e);

            return;
        }

        $this->notifier->notify($message);
    }
}

<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Notifications;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Siberfx\LinkedInAutopost\Contracts\NotificationChannel;
use Siberfx\LinkedInAutopost\Exceptions\NotificationFailed;
use Siberfx\LinkedInAutopost\Jobs\SendNotification;
use Throwable;

/** Builds the channels in linkedin-autopost.notifications.channels and sends to them. */
final class Notifier
{
    public function __construct(
        private readonly Container $container,
        private readonly Dispatcher $bus,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('linkedin-autopost.notifications.enabled', false);
    }

    /** Whether notifications are on and this event (shared, failed, token_expiring) is wanted. */
    public function wants(string $event): bool
    {
        return $this->enabled() && (bool) config("linkedin-autopost.notifications.events.{$event}", true);
    }

    /** @return list<string> Channels switched on in the config, configured or not. */
    public function enabledChannels(): array
    {
        $names = [];

        foreach ($this->channelsConfig() as $name => $config) {
            if ((bool) ($config['enabled'] ?? false)) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }

    /** @throws InvalidArgumentException When the channel is not in the config or its class is not a NotificationChannel. */
    public function channel(string $name): NotificationChannel
    {
        $config = $this->channelsConfig()[$name] ?? throw new InvalidArgumentException("No LinkedIn notification channel [{$name}] is configured.");
        $class = $config['class'] ?? null;

        if (! is_string($class) || ! is_subclass_of($class, NotificationChannel::class)) {
            throw new InvalidArgumentException("The [{$name}] notification channel needs a class implementing ".NotificationChannel::class.'.');
        }

        /** @var NotificationChannel */
        return $this->container->make($class, ['config' => $config]);
    }

    /**
     * Queues the message for every enabled, configured channel, one job each so
     * they retry independently. Never throws: a share must not fail over a notification.
     */
    public function notify(Message $message): void
    {
        foreach ($this->enabledChannels() as $name) {
            try {
                if (! $this->channel($name)->configured()) {
                    continue;
                }

                $this->bus->dispatch((new SendNotification($name, $message))
                    ->onConnection(config('linkedin-autopost.notifications.queue_connection'))
                    ->onQueue(config('linkedin-autopost.notifications.queue')));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * Sends right away, for linkedin:test-notification and the queued job.
     *
     * @throws NotificationFailed|InvalidArgumentException
     */
    public function sendNow(string $name, Message $message): void
    {
        $this->channel($name)->send($message);
    }

    /** @return array<array-key, array<string, mixed>> */
    private function channelsConfig(): array
    {
        $channels = config('linkedin-autopost.notifications.channels', []);

        return is_array($channels) ? array_filter($channels, 'is_array') : [];
    }
}

<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Observers;

use Illuminate\Database\Eloquent\Model;
use Siberfx\LinkedInAutopost\Contracts\ShareableOnLinkedIn;
use Siberfx\LinkedInAutopost\LinkedInManager;

final class ShareableObserver
{
    public function saved(Model $model): void
    {
        if (! $model instanceof ShareableOnLinkedIn || ! config('linkedin-autopost.autopost.enabled')) {
            return;
        }

        if (app()->runningInConsole() && ! config('linkedin-autopost.autopost.in_console')) {
            return;
        }

        if (! $model->isLiveForLinkedIn() || ! $this->justBecameLive($model)) {
            return;
        }

        $linkedin = app(LinkedInManager::class);

        if (! $linkedin->isConnected() || $linkedin->wasPosted($model)) {
            return;
        }

        $linkedin->queue($model);
    }

    /**
     * Inside "saved" the original attributes still hold the values from
     * before this save. Evaluate the model's own rule on them, so any live
     * rule (enum, boolean, dates) works without naming columns.
     */
    private function justBecameLive(Model&ShareableOnLinkedIn $model): bool
    {
        $before = $model->getRawOriginal();

        if ($before === []) {
            return true; // a fresh insert
        }

        $previous = (clone $model)->setRawAttributes($before);

        return ! $previous->isLiveForLinkedIn();
    }
}

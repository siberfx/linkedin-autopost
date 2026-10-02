<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
use Siberfx\LinkedInAutopost\Contracts\ShareableOnLinkedIn;
use Siberfx\LinkedInAutopost\LinkedInManager;
use Siberfx\LinkedInAutopost\Models\LinkedInPost;

final class ShareButtonPresenter
{
    private ?CarbonImmutable $lastPostedAt = null;

    private bool $resolved = false;

    public function __construct(
        private readonly Model&ShareableOnLinkedIn $model,
        private readonly LinkedInManager $linkedin,
        private readonly ?string $label = null,
    ) {}

    public function lastPostedAt(): ?CarbonImmutable
    {
        if (! $this->resolved) {
            $this->lastPostedAt = LinkedInPost::query()->for($this->model)->posted()->latest('posted_at')->first()?->posted_at;
            $this->resolved = true;
        }

        return $this->lastPostedAt;
    }

    public function disabledReason(): ?string
    {
        if (! $this->linkedin->isConnected()) {
            return 'Connect LinkedIn first.';
        }

        return $this->model->isLiveForLinkedIn() ? null : 'Only live items can be shared.';
    }

    public function text(): string
    {
        return $this->label ?? ($this->lastPostedAt() !== null ? 'Share again' : 'Share on LinkedIn');
    }

    public function title(): string
    {
        $posted = $this->lastPostedAt();

        return $this->disabledReason()
            ?? ($posted !== null ? 'Shared on LinkedIn on '.$posted->format('j M Y').'. Share again.' : 'Share on LinkedIn');
    }

    public function confirmText(): string
    {
        $posted = $this->lastPostedAt();

        return $posted !== null
            ? 'This was already posted on '.$posted->format('j M Y').'. Post it to LinkedIn again?'
            : 'Post this to LinkedIn now?';
    }

    public function signedAction(): string
    {
        return URL::signedRoute(config('linkedin-autopost.routes.name', 'linkedin-autopost.').'share.signed', [
            'type' => $this->model->getMorphClass(),
            'id' => $this->model->getKey(),
        ]);
    }
}

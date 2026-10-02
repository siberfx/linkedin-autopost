<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Siberfx\LinkedInAutopost\Contracts\ShareableOnLinkedIn;
use Siberfx\LinkedInAutopost\Exceptions\LinkedInRequestFailed;
use Siberfx\LinkedInAutopost\Exceptions\NotConnected;
use Siberfx\LinkedInAutopost\Exceptions\NotShareable;
use Siberfx\LinkedInAutopost\LinkedInManager;
use Siberfx\LinkedInAutopost\Models\LinkedInPost;
use Siberfx\LinkedInAutopost\Support\ShareButtonPresenter;
use Siberfx\LinkedInAutopost\Support\Theme;

final class ShareButton extends Component
{
    #[Locked]
    public string $shareableType = '';

    #[Locked]
    public string $shareableId = '';

    public ?string $label = null;

    public string $size = 'md';

    public bool $confirm = true;

    /** @var array{type: string, message: string}|null */
    public ?array $flash = null;

    public function mount(Model $model, ?string $label = null, string $size = 'md', bool $confirm = true): void
    {
        if (! $model instanceof ShareableOnLinkedIn) {
            throw new InvalidArgumentException($model::class.' must implement '.ShareableOnLinkedIn::class.'.');
        }

        $this->shareableType = $model->getMorphClass();
        $this->shareableId = (string) $model->getKey();
        $this->label = $label;
        $this->size = $size === 'sm' ? 'sm' : 'md';
        $this->confirm = $confirm;
    }

    public function share(LinkedInManager $linkedin): void
    {
        try {
            $post = $linkedin->share($this->model(), LinkedInPost::TRIGGER_MANUAL);
        } catch (NotConnected|NotShareable|LinkedInRequestFailed $e) {
            $this->flash = ['type' => 'error', 'message' => $e->getMessage()];

            return;
        }

        $this->flash = ['type' => 'success', 'message' => 'Shared on LinkedIn.'];
        $this->dispatch('linkedin-autopost-shared', urn: $post->post_urn);
    }

    public function render(LinkedInManager $linkedin): View
    {
        return view(Theme::view('share-button'), [
            'presenter' => new ShareButtonPresenter($this->model(), $linkedin, $this->label),
            'size' => $this->size,
            'confirm' => $this->confirm,
            'livewire' => true,
            'flash' => $this->flash,
        ]);
    }

    private function model(): Model&ShareableOnLinkedIn
    {
        $class = Relation::getMorphedModel($this->shareableType) ?? $this->shareableType;

        abort_if(! class_exists($class) || ! is_subclass_of($class, Model::class) || ! is_subclass_of($class, ShareableOnLinkedIn::class), 404);

        /** @var Model&ShareableOnLinkedIn */
        return $class::query()->findOrFail($this->shareableId);
    }
}

<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use Siberfx\LinkedInAutopost\Support\StatusMessages;
use Siberfx\LinkedInAutopost\Support\Theme;

final class Flash extends Component
{
    public function render(): View
    {
        return view(Theme::view('flash'), ['flash' => StatusMessages::current(request())]);
    }
}

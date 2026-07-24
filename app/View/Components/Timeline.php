<?php

declare(strict_types=1);

namespace App\View\Components;

use App\Models\SystemEvent;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\View\Component;

class Timeline extends Component
{
    /**
     * Create a new component instance.
     *
     * @param  'admin'|'customer'  $audience
     */
    public function __construct(
        public Model $entity,
        public int $limit = 50,
        public bool $showHeading = true,
        public string $audience = 'admin',
    ) {}

    /**
     * Events for this entity. Single indexed query (entity_type, entity_id, created_at), max 50, no N+1.
     *
     * @return Collection<int, SystemEvent>
     */
    public function events(): Collection
    {
        return SystemEvent::query()
            ->where('entity_type', $this->entity->getMorphClass())
            ->where('entity_id', $this->entity->getKey())
            ->orderByDesc('created_at')
            ->limit($this->limit)
            ->get();
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        return view('components.timeline', [
            'events' => $this->events(),
            'isCustomerAudience' => $this->audience === 'customer',
        ]);
    }
}

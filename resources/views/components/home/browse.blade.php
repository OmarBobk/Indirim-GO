@props([
    /** @var list<array{id: int, name: string, slug: string, image: string}> $categories */
    'categories' => [],
])

{{-- Browse Zone: category entry into the shelf. --}}
<div
    id="customer-home-browse"
    class="scroll-mt-24"
    data-section="customer-home-browse"
    data-test="customer-home-browse"
    data-zone="browse"
>
    <x-home.category-chips :categories="$categories" />
</div>

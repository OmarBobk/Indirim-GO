@props([
    /** @var list<array{id: int, name: string, image: string, products_count: int, times_ordered: int}> $frequentlyOrdered */
    'frequentlyOrdered' => [],
])

{{-- Personal Zone: frequently ordered. --}}
<div
    data-section="customer-home-personal"
    data-test="customer-home-personal"
    data-zone="personal"
>
    <x-home.frequently-ordered :items="$frequentlyOrdered" />
</div>

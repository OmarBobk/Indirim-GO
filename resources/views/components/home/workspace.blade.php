@props([
    /**
     * @var array{
     *     command: array{},
     *     personal: array{items: list<array{id: int, name: string, image: string, products_count: int, times_ordered: int}>},
     *     browse: array{categories: list<array{id: int, name: string, slug: string, image: string}>},
     *     catalog: array{packages: list<array{id: int, name: string, image: string, products_count: int}>},
     *     merch: array{visible: false}
     * } $view
     */
    'view',
])

{{--
    Authenticated home composition.
    Zone order is fixed; leaf components are passive and receive presenter DTOs.
--}}
<div
    class="storefront-page storefront-page--browse mx-auto flex w-full flex-col gap-5 sm:gap-6"
    data-section="customer-home"
    data-test="customer-home-workspace"
    data-storefront-page="browse"
>
    <div
        class="flex flex-col gap-1.5 sm:gap-2.5"
        data-section="customer-home-shopping-lead"
        data-test="customer-home-shopping-lead"
    >
        <x-home.command />

        <x-home.operational-placeholder />

        <x-home.personal :frequently-ordered="$view['personal']['items']" />
    </div>

    <div
        class="flex flex-col gap-1 sm:gap-1.5"
        data-section="customer-home-discover"
        data-test="customer-home-discover"
    >
        <x-home.browse :categories="$view['browse']['categories']" />

        <x-home.catalog :packages="$view['catalog']['packages']" />
    </div>

    <x-home.merch-placeholder />
</div>

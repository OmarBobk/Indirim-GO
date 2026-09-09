<?php

declare(strict_types=1);

namespace App\Actions\MobileOrders;

use App\Actions\MobileCatalog\ListMobilePackages;
use App\Models\Order;
use App\Models\User;
use App\Support\Api\V1\MobileOrderListItemFactory;
use App\Support\CustomerOrderFulfillmentClassifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;

final class ListMobileOrders
{
    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 50;

    public const MIN_QUERY_LENGTH = 2;

    public const MAX_QUERY_LENGTH = 100;

    public function __construct(
        private readonly CustomerOrderFulfillmentClassifier $classifier,
        private readonly MobileOrderListItemFactory $listItemFactory,
    ) {}

    /**
     * @return array{
     *     data: list<array<string, mixed>>,
     *     meta: array{pagination: array{page: int, per_page: int, total: int, last_page: int}}
     * }
     */
    public function handle(
        User $user,
        int $page,
        int $perPage,
        ?string $searchQuery = null,
        ?string $customerState = null,
    ): array {
        $query = Order::query()
            ->where('user_id', $user->id)
            ->select([
                'orders.id',
                'orders.user_id',
                'orders.order_number',
                'orders.currency',
                'orders.total',
                'orders.status',
                'orders.paid_at',
                'orders.created_at',
            ])
            ->with([
                'items' => fn (HasMany $items): HasMany => $items
                    ->select(['order_items.id', 'order_items.order_id', 'order_items.name'])
                    ->orderBy('order_items.id'),
                'items.fulfillments' => fn (HasMany $fulfillments): HasMany => $fulfillments
                    ->select([
                        'fulfillments.id',
                        'fulfillments.order_id',
                        'fulfillments.order_item_id',
                        'fulfillments.status',
                    ]),
            ])
            ->orderByDesc('orders.created_at')
            ->orderByDesc('orders.id');

        if ($searchQuery !== null && $searchQuery !== '') {
            $this->constrainSearch($query, $searchQuery);
        }

        $this->classifier->selectClassification($query);

        if ($customerState !== null) {
            $this->classifier->applyFilter($query, $customerState);
        }

        $paginator = $query->paginate(perPage: $perPage, page: $page);

        $data = collect($paginator->items())
            ->map(fn (Order $order): array => $this->listItemFactory->fromOrder($order, $user))
            ->values()
            ->all();

        return [
            'data' => $data,
            'meta' => [
                'pagination' => [
                    'page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => max(1, $paginator->lastPage()),
                ],
            ],
        ];
    }

    /**
     * Literal substring match on order number or snapshot item title.
     *
     * EXISTS (not a join / orWhereHas without exists) so two matching line
     * items cannot duplicate the parent order. Live package names are never
     * searched. ESCAPE '!' matches ListMobilePackages so SQLite tests and
     * MySQL production treat %, _, and \ as ordinary characters.
     */
    private function constrainSearch(Builder $query, string $searchQuery): void
    {
        $pattern = '%'.ListMobilePackages::escapeLikeLiterals($searchQuery).'%';

        $query->where(function (Builder $builder) use ($pattern): void {
            $builder
                ->whereRaw("orders.order_number LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereExists(function (QueryBuilder $exists) use ($pattern): void {
                    $exists->selectRaw('1')
                        ->from('order_items')
                        ->whereColumn('order_items.order_id', 'orders.id')
                        ->whereRaw("order_items.name LIKE ? ESCAPE '!'", [$pattern]);
                });
        });
    }
}

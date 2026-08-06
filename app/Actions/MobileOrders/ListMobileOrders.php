<?php

declare(strict_types=1);

namespace App\Actions\MobileOrders;

use App\Models\Order;
use App\Models\User;
use App\Support\Api\V1\MobileOrderListItemFactory;
use App\Support\CustomerOrderFulfillmentClassifier;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ListMobileOrders
{
    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 50;

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
    public function handle(User $user, int $page, int $perPage): array
    {
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

        $this->classifier->selectClassification($query);

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
}

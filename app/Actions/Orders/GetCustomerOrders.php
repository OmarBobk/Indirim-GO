<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Models\Order;
use App\Support\CustomerOrderFulfillmentClassifier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class GetCustomerOrders
{
    public function __construct(
        private readonly CustomerOrderFulfillmentClassifier $classifier,
    ) {}

    public function handle(int $userId, string $filter, int $perPage, string $search = ''): LengthAwarePaginator
    {
        $filter = $this->classifier->normalizeFilter($filter);
        $search = trim($search);

        $query = Order::query()
            ->where('user_id', $userId)
            ->select([
                'orders.id',
                'orders.user_id',
                'orders.order_number',
                'orders.currency',
                'orders.total',
                'orders.status',
                'orders.created_at',
            ])
            ->with([
                'items:id,order_id,package_id,name,unit_price,quantity,amount_mode,requested_amount,amount_unit_label,line_total,requirements_payload',
                'items.fulfillments' => fn (HasMany $query): HasMany => $query->select([
                    'fulfillments.id',
                    'fulfillments.order_item_id',
                    'fulfillments.status',
                    'fulfillments.meta->refund->status as refund_status',
                ]),
                'items.package:id,name,image',
                'items.package.requirements' => fn (HasMany $query): HasMany => $query
                    ->select([
                        'package_requirements.id',
                        'package_requirements.package_id',
                        'package_requirements.key',
                        'package_requirements.label',
                    ])
                    ->where('key', 'id'),
            ]);

        if ($search !== '') {
            $like = '%'.$search.'%';

            $query->where(function (Builder $builder) use ($like): void {
                $builder
                    ->where('orders.order_number', 'like', $like)
                    ->orWhereHas('items', function (Builder $items) use ($like): void {
                        $items->where('order_items.name', 'like', $like);
                    });
            });
        }

        $this->classifier->selectClassification($query);
        $this->classifier->applyFilter($query, $filter);

        if ($filter === CustomerOrderFulfillmentClassifier::ALL) {
            $this->classifier->prioritizeNeedsAttention($query);
        }

        return $query->latest('created_at')->paginate($perPage);
    }
}

export type SalesOrderSummary = {
    id: number;
    number: string;
    customerName: string;
    status: string;
    statusLabel: string;
    currency: string;
    totalAmount: string;
    createdAt: string | null;
};

export type SalesOrderItem = {
    id: number;
    inventoryItemId: number | null;
    name: string;
    sku: string;
    quantity: number;
    fulfilledQuantity: number;
    cancelledQuantity: number;
    reservedQuantity: number;
    returnedQuantity: number;
    unitPrice: string;
    lineTotal: string;
};

export type SalesReservation = {
    id: number;
    salesOrderItemId: number;
    itemName: string;
    warehouseName: string;
    locationName: string;
    quantity: number;
    remainingQuantity: number;
};

export type SalesFulfillment = {
    id: number;
    salesOrderItemId: number;
    itemName: string;
    quantity: number;
    returnedQuantity: number;
    returnableQuantity: number;
    warehouseName: string;
    locationName: string;
};

export type SalesReturn = {
    id: number;
    itemName: string;
    quantity: number;
    condition: 'good' | 'damaged' | 'unsellable';
    accepted: boolean;
    reason: string;
    refundableAmount: string;
    remainingRefundableAmount: string;
};

export type SalesRefund = {
    id: number;
    salesReturnId: number;
    amount: string;
    status: 'pending' | 'refunded' | 'cancelled';
    reference: string | null;
};

export type SalesOrder = Omit<SalesOrderSummary, 'createdAt'> & {
    customerEmail: string | null;
    customerPhone: string | null;
    customerAddress: string | null;
    cancellationReason: string | null;
    items: SalesOrderItem[];
    reservations: SalesReservation[];
    fulfillments: SalesFulfillment[];
    returns: SalesReturn[];
    refunds: SalesRefund[];
    events: {
        id: number;
        note: string | null;
        userName: string;
        createdAt: string | null;
    }[];
};

export type SalesWarehouse = {
    id: number;
    name: string;
    locations: { id: number; name: string }[];
};

export function salesMoney(amount: string, currency: string): string {
    try {
        return new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency,
            currencyDisplay: 'code',
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(Number(amount));
    } catch {
        return `${currency} ${amount}`;
    }
}

export function salesDate(value: string | null): string {
    if (!value) return '—';
    const date = new Date(value);
    return Number.isNaN(date.getTime())
        ? value
        : new Intl.DateTimeFormat(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(date);
}

export function unreservedQuantity(item: SalesOrderItem): number {
    return Math.max(
        0,
        item.quantity -
            item.fulfilledQuantity -
            item.cancelledQuantity -
            item.reservedQuantity,
    );
}

export const salesSelectClass =
    'h-10 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50';

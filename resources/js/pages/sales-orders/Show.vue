<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import SalesOrderField from '@/components/SalesOrderField.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { cancel, fulfill, index, reserve, show } from '@/routes/sales-orders';
import {
    store as storeRefund,
    update as updateRefund,
} from '@/routes/sales-orders/refunds';
import { store as storeReturn } from '@/routes/sales-orders/returns';
import {
    salesDate,
    salesMoney,
    salesSelectClass,
    unreservedQuantity,
    type SalesOrder,
    type SalesWarehouse,
} from '@/types/sales-orders';

const props = defineProps<{
    order: SalesOrder;
    canReserve: boolean;
    canFulfill: boolean;
    canCancel: boolean;
    canReturn: boolean;
    canRefund: boolean;
    warehouses: SalesWarehouse[];
}>();

defineOptions({
    layout: (layoutProps: {
        currentTeam?: { slug: string } | null;
        order: SalesOrder;
    }) => ({
        breadcrumbs: layoutProps.currentTeam
            ? [
                  {
                      title: 'Sales orders',
                      href: index.url({
                          current_team: layoutProps.currentTeam.slug,
                      }),
                  },
                  {
                      title: layoutProps.order.number,
                      href: show.url({
                          current_team: layoutProps.currentTeam.slug,
                          salesOrder: layoutProps.order.id,
                      }),
                  },
              ]
            : [],
    }),
});

type Operation =
    | 'reserve'
    | 'fulfill'
    | 'return'
    | 'refund'
    | 'refundUpdate'
    | 'cancel';
const page = usePage();
const teamSlug = computed(
    () => (page.props.currentTeam as { slug: string } | null)?.slug ?? '',
);
const routeParams = computed(() => ({
    current_team: teamSlug.value,
    salesOrder: props.order.id,
}));
const operation = ref<Operation>('reserve');
const operationOpen = ref(false);
const selectedId = ref(0);
const successMessage = ref('');
const startNewWarning = ref(false);

function newOperationData() {
    return {
        idempotency_key: '',
        sales_order_item_id: 0,
        reservation_id: 0,
        fulfillment_id: 0,
        sales_return_id: 0,
        warehouse_id: '',
        warehouse_location_id: '',
        quantity: 1,
        condition: 'good' as 'good' | 'damaged' | 'unsellable',
        accepted: true,
        reason: '',
        amount: '',
        status: 'pending' as 'pending' | 'refunded' | 'cancelled',
        reference: '',
    };
}

type OperationData = ReturnType<typeof newOperationData>;
type RetryableOperation = 'fulfill' | 'return' | 'refund';
type OperationAttempt = {
    action: RetryableOperation;
    targetId: number;
    data: OperationData;
    state: 'uncertain' | 'validation';
    errors: Record<string, string>;
};

const form = useForm(newOperationData());
const attempts = ref<Record<string, OperationAttempt>>({});
const attemptsReady = ref(false);
const corruptAttemptStorage = ref(false);
const attemptStorageError = ref('');
const attemptStorageKey = computed(() => {
    const userId = (page.props.auth as { user?: { id: number } } | undefined)
        ?.user?.id;
    return userId && teamSlug.value
        ? `erp:sales-attempts:v1:user:${userId}:team:${encodeURIComponent(teamSlug.value)}:order:${props.order.id}`
        : null;
});

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function isStoredAttempt(
    key: string,
    value: unknown,
): value is OperationAttempt {
    if (
        !isRecord(value) ||
        !['fulfill', 'return', 'refund'].includes(String(value.action)) ||
        !Number.isSafeInteger(value.targetId) ||
        Number(value.targetId) < 1 ||
        key !== `${value.action}:${value.targetId}` ||
        !['uncertain', 'validation'].includes(String(value.state)) ||
        !isRecord(value.data) ||
        !isRecord(value.errors) ||
        !Object.values(value.errors).every((error) => typeof error === 'string')
    )
        return false;

    const data = value.data;
    const originalData = newOperationData();
    const validFields =
        Object.keys(data).length === Object.keys(originalData).length &&
        Object.entries(originalData).every(
            ([field, initial]) =>
                typeof data[field] === typeof initial &&
                (typeof initial !== 'number' || Number.isFinite(data[field])),
        );
    const targetField = {
        fulfill: 'reservation_id',
        return: 'fulfillment_id',
        refund: 'sales_return_id',
    }[value.action as RetryableOperation];
    return (
        validFields &&
        data[targetField] === value.targetId &&
        /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(
            String(data.idempotency_key),
        ) &&
        ['good', 'damaged', 'unsellable'].includes(String(data.condition)) &&
        ['pending', 'refunded', 'cancelled'].includes(String(data.status))
    );
}

function loadAttempts(storageKey: string | null): void {
    attemptsReady.value = false;
    corruptAttemptStorage.value = false;
    attemptStorageError.value = '';
    attempts.value = {};
    if (typeof window === 'undefined' || !storageKey) {
        attemptStorageError.value =
            'Retry protection requires a signed-in user and browser session storage. No fulfillment, return, or refund will be sent without it.';
        return;
    }
    let stored: string | null;
    try {
        stored = window.sessionStorage.getItem(storageKey);
    } catch {
        attemptStorageError.value =
            'Saved retry protection is unavailable. Enable session storage and retry loading before submitting an operation.';
        return;
    }
    try {
        if (stored !== null) {
            const saved: unknown = JSON.parse(stored);
            if (
                !isRecord(saved) ||
                saved.version !== 1 ||
                !isRecord(saved.attempts) ||
                !Object.entries(saved.attempts).every(([key, attempt]) =>
                    isStoredAttempt(key, attempt),
                )
            ) {
                throw new Error('Invalid sales attempt storage');
            }
            attempts.value = saved.attempts as Record<string, OperationAttempt>;
        }
        attemptsReady.value = true;
    } catch {
        corruptAttemptStorage.value = true;
        attemptStorageError.value =
            'Saved retry data is unreadable. An earlier operation may already be recorded. Check order history before explicitly discarding this saved data.';
    }
}

function saveAttempts(next: Record<string, OperationAttempt>): boolean {
    if (
        typeof window === 'undefined' ||
        !attemptStorageKey.value ||
        !attemptsReady.value
    )
        return false;
    try {
        if (Object.keys(next).length) {
            window.sessionStorage.setItem(
                attemptStorageKey.value,
                JSON.stringify({ version: 1, attempts: next }),
            );
        } else {
            window.sessionStorage.removeItem(attemptStorageKey.value);
        }
        attempts.value = next;
        attemptStorageError.value = '';
        return true;
    } catch {
        attemptStorageError.value =
            'Retry protection could not be saved. No new request will be sent until it can be saved. Keep this page open and enable session storage before trying again.';
        return false;
    }
}

function discardCorruptAttempts(): void {
    if (
        typeof window === 'undefined' ||
        !attemptStorageKey.value ||
        form.processing
    )
        return;
    try {
        window.sessionStorage.removeItem(attemptStorageKey.value);
        loadAttempts(attemptStorageKey.value);
    } catch {
        attemptStorageError.value =
            'Saved retry data could not be cleared. Enable session storage and try again.';
    }
}

onMounted(() => {
    watch(
        attemptStorageKey,
        (storageKey) => {
            operationOpen.value = false;
            form.defaults(newOperationData()).resetAndClearErrors();
            loadAttempts(storageKey);
        },
        { immediate: true, flush: 'sync' },
    );
});
const attemptKey = computed(() => `${operation.value}:${selectedId.value}`);
const currentAttempt = computed(() => attempts.value[attemptKey.value]);
const isUncertain = computed(() => currentAttempt.value?.state === 'uncertain');
const unresolvedAttempts = computed(() =>
    Object.entries(attempts.value).filter(
        ([, attempt]) => attempt.state === 'uncertain',
    ),
);
const errors = computed(() => form.errors as Record<string, string>);
const selectedItem = computed(() =>
    props.order.items.find((item) => item.id === selectedId.value),
);
const selectedReservation = computed(() =>
    props.order.reservations.find((entry) => entry.id === selectedId.value),
);
const selectedFulfillment = computed(() =>
    props.order.fulfillments.find((entry) => entry.id === selectedId.value),
);
const selectedReturn = computed(() =>
    props.order.returns.find((entry) => entry.id === selectedId.value),
);
const selectedRefund = computed(() =>
    props.order.refunds.find((entry) => entry.id === selectedId.value),
);
const selectedWarehouse = computed(() =>
    props.warehouses.find(
        (warehouse) => String(warehouse.id) === form.warehouse_id,
    ),
);
const restocks = computed(
    () =>
        operation.value === 'return' &&
        form.accepted &&
        form.condition === 'good',
);
const needsLocation = computed(
    () => operation.value === 'reserve' || restocks.value,
);
const maxQuantity = computed(() => {
    if (operation.value === 'reserve')
        return selectedItem.value ? unreservedQuantity(selectedItem.value) : 0;
    if (operation.value === 'fulfill')
        return selectedReservation.value?.remainingQuantity ?? 0;
    if (operation.value === 'return')
        return selectedFulfillment.value?.returnableQuantity ?? 0;
    return 0;
});
const operationTitles: Record<Operation, string> = {
    reserve: 'Reserve stock',
    fulfill: 'Fulfill reservation',
    return: 'Receive customer return',
    refund: 'Record refund',
    refundUpdate: 'Update refund record',
    cancel: 'Cancel unfulfilled remainder',
};
const title = computed(() => operationTitles[operation.value]);
const description = computed(() => {
    switch (operation.value) {
        case 'reserve':
            return `${selectedItem.value?.name ?? 'Item'}: ${maxQuantity.value} units available to reserve on this order. Choose a bin; repeat to reserve from several bins.`;
        case 'fulfill':
            return `${selectedReservation.value?.itemName ?? 'Item'} · Reservation #${selectedId.value}: ${maxQuantity.value} reserved units remaining. This records stock leaving the selected bin.`;
        case 'return':
            return `${selectedFulfillment.value?.itemName ?? 'Item'} · Fulfillment #${selectedId.value}: ${maxQuantity.value} delivered units remain returnable. Every received return, including rejected returns, counts against this quantity.`;
        case 'refund':
            return `${selectedReturn.value?.itemName ?? 'Item'} · Return #${selectedId.value}. Remaining refundable: ${salesMoney(selectedReturn.value?.remainingRefundableAmount ?? '0.00', props.order.currency)}. Tracking only; no payment is sent.`;
        case 'refundUpdate':
            return `Refund #${selectedId.value} · ${salesMoney(selectedRefund.value?.amount ?? '0.00', props.order.currency)}. Mark the pending record refunded or cancel it. Refunded records are terminal. No payment is sent.`;
        case 'cancel':
            return 'Cancel all unfulfilled quantities and release their reservations. Fulfilled quantities stay on the order and remain returnable. This cannot be undone.';
    }
});
const submitLabel = computed(() => {
    if (form.processing) return 'Recording…';
    if (isUncertain.value) return 'Retry unchanged operation';
    if (operation.value === 'refundUpdate')
        return form.status === 'cancelled'
            ? 'Cancel refund record'
            : 'Mark refund as refunded';
    return title.value;
});
const blocked = computed(
    () =>
        form.processing ||
        !teamSlug.value ||
        (isRetryableOperation(operation.value) && !attemptsReady.value) ||
        (!isUncertain.value &&
            needsLocation.value &&
            (!form.warehouse_id || !form.warehouse_location_id)) ||
        (!isUncertain.value &&
            ['reserve', 'fulfill', 'return'].includes(operation.value) &&
            maxQuantity.value < 1),
);

function openOperation(action: Operation, id = 0): void {
    if (form.processing) return;
    operation.value = action;
    selectedId.value = id;
    startNewWarning.value = false;
    form.defaults(newOperationData()).resetAndClearErrors();
    const previousAttempt = currentAttempt.value;
    if (previousAttempt) {
        Object.assign(form, previousAttempt.data);
        form.setError(previousAttempt.errors);
        operationOpen.value = true;
        return;
    }
    form.idempotency_key = isRetryableOperation(action)
        ? crypto.randomUUID()
        : '';
    form.sales_order_item_id = action === 'reserve' ? id : 0;
    form.reservation_id = action === 'fulfill' ? id : 0;
    form.fulfillment_id = action === 'return' ? id : 0;
    form.sales_return_id = action === 'refund' ? id : 0;
    form.quantity = Math.max(1, maxQuantity.value);
    if (action === 'refund')
        form.amount = selectedReturn.value?.remainingRefundableAmount ?? '';
    if (action === 'refundUpdate') {
        form.status = 'refunded';
        form.reference = selectedRefund.value?.reference ?? '';
    }
    operationOpen.value = true;
}

function isRetryableOperation(action: Operation): action is RetryableOperation {
    return ['fulfill', 'return', 'refund'].includes(action);
}

function attemptTarget(action: RetryableOperation, id: number): string {
    const labels = {
        fulfill: 'Reservation',
        return: 'Fulfillment',
        refund: 'Return',
    };
    return `${labels[action]} #${id}`;
}

function requestNewOperation(): void {
    if (form.processing) return;
    if (isUncertain.value) {
        startNewWarning.value = true;
        return;
    }
    startNewOperation();
}

function startNewOperation(): void {
    if (form.processing) return;
    const next = { ...attempts.value };
    delete next[attemptKey.value];
    if (!saveAttempts(next)) return;
    openOperation(operation.value, selectedId.value);
}

function operationPayload(action: Operation, data: OperationData) {
    switch (action) {
        case 'reserve':
            return {
                sales_order_item_id: data.sales_order_item_id,
                warehouse_id: data.warehouse_id,
                warehouse_location_id: data.warehouse_location_id,
                quantity: data.quantity,
            };
        case 'fulfill':
            return {
                idempotency_key: data.idempotency_key,
                reservation_id: data.reservation_id,
                quantity: data.quantity,
            };
        case 'return':
            return {
                idempotency_key: data.idempotency_key,
                fulfillment_id: data.fulfillment_id,
                quantity: data.quantity,
                condition: data.condition,
                accepted: data.accepted,
                reason: data.reason,
                ...(data.accepted && data.condition === 'good'
                    ? {
                          warehouse_id: data.warehouse_id,
                          warehouse_location_id: data.warehouse_location_id,
                      }
                    : {}),
            };
        case 'refund':
            return {
                idempotency_key: data.idempotency_key,
                sales_return_id: data.sales_return_id,
                amount: String(data.amount),
                status: data.status,
                reference: data.reference || null,
            };
        case 'refundUpdate':
            return { status: data.status, reference: data.reference || null };
        case 'cancel':
            return { reason: data.reason };
    }
}

function submitOperation(): void {
    if (blocked.value) return;
    const action = operation.value;
    const id = selectedId.value;
    const key = attemptKey.value;
    const wasUncertain = isUncertain.value;
    const snapshot = {
        ...(wasUncertain ? currentAttempt.value!.data : form.data()),
    };
    const data = { ...snapshot, amount: String(snapshot.amount) };
    const params = { ...routeParams.value };
    const retryable = isRetryableOperation(action);
    const storageScope = attemptStorageKey.value;
    form.transform(() => operationPayload(action, data));
    const options = {
        preserveScroll: true,
        onBefore: () => {
            if (isRetryableOperation(action)) {
                if (attemptStorageKey.value !== storageScope) return false;
                return saveAttempts({
                    ...attempts.value,
                    [key]: {
                        action,
                        targetId: id,
                        data,
                        state: 'uncertain',
                        errors: {},
                    },
                });
            }
        },
        onSuccess: () => {
            if (attemptStorageKey.value !== storageScope) return;
            if (retryable) {
                const next = { ...attempts.value };
                delete next[key];
                saveAttempts(next);
                attempts.value = next;
            }
            operationOpen.value = false;
            successMessage.value = `${operationTitles[action]} recorded successfully.`;
        },
        onError: (validationErrors: Record<string, string>) => {
            if (
                isRetryableOperation(action) &&
                attemptStorageKey.value === storageScope
            ) {
                const next: Record<string, OperationAttempt> = {
                    ...attempts.value,
                    [key]: {
                        action,
                        targetId: id,
                        data,
                        state: wasUncertain ? 'uncertain' : 'validation',
                        errors: { ...validationErrors },
                    },
                };
                saveAttempts(next);
                attempts.value = next;
            }
        },
        onNetworkError: () => (retryable ? false : undefined),
        onHttpException: () => (retryable ? false : undefined),
    };
    switch (action) {
        case 'reserve':
            form.post(reserve.url(params), options);
            break;
        case 'fulfill':
            form.post(fulfill.url(params), options);
            break;
        case 'return':
            form.post(storeReturn.url(params), options);
            break;
        case 'refund':
            form.post(storeRefund.url(params), options);
            break;
        case 'refundUpdate':
            form.patch(
                updateRefund.url({ ...params, salesRefund: id }),
                options,
            );
            break;
        case 'cancel':
            form.post(cancel.url(params), options);
            break;
    }
}

function returnName(id: number): string {
    return (
        props.order.returns.find((entry) => entry.id === id)?.itemName ?? 'Item'
    );
}
</script>

<template>
    <main class="flex min-h-full flex-1 flex-col gap-6 p-4 sm:p-6">
        <Head :title="order.number" />
        <header
            class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end"
        >
            <div class="min-w-0">
                <Link
                    :href="index({ current_team: teamSlug })"
                    class="text-sm text-muted-foreground underline underline-offset-4"
                    >Back to sales orders</Link
                >
                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <h1 class="text-2xl font-semibold tracking-tight">
                        {{ order.number }}
                    </h1>
                    <Badge
                        :variant="
                            order.status === 'cancelled'
                                ? 'destructive'
                                : 'secondary'
                        "
                        >{{ order.statusLabel }}</Badge
                    >
                </div>
                <p class="mt-1 text-sm text-muted-foreground">
                    Order total ·
                    <span class="font-medium text-foreground tabular-nums">{{
                        salesMoney(order.totalAmount, order.currency)
                    }}</span>
                    · No taxes or discounts
                </p>
            </div>
            <Button
                v-if="canCancel"
                variant="outline"
                :disabled="form.processing"
                @click="openOperation('cancel')"
                >Cancel unfulfilled remainder</Button
            >
        </header>

        <p v-if="successMessage" role="status" class="text-sm">
            {{ successMessage }}
        </p>
        <Alert
            v-if="attemptStorageError && !operationOpen"
            variant="destructive"
        >
            <AlertTitle>Retry protection needs attention</AlertTitle>
            <AlertDescription>
                <p>{{ attemptStorageError }}</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <Button
                        v-if="corruptAttemptStorage"
                        type="button"
                        variant="destructive"
                        :disabled="form.processing"
                        @click="discardCorruptAttempts"
                        >I checked history — discard unreadable retry
                        data</Button
                    >
                    <Button
                        v-else-if="!attemptsReady"
                        type="button"
                        variant="outline"
                        :disabled="form.processing"
                        @click="loadAttempts(attemptStorageKey)"
                        >Retry loading saved attempts</Button
                    >
                </div>
            </AlertDescription>
        </Alert>
        <Alert v-if="unresolvedAttempts.length && !form.processing">
            <AlertTitle>Operations awaiting confirmation</AlertTitle>
            <AlertDescription>
                <p>
                    A response was not confirmed. These operations may already
                    be recorded. Review or retry the original attempt before
                    recording another one.
                </p>
                <ul class="mt-3 flex flex-col gap-2">
                    <li
                        v-for="[key, attempt] in unresolvedAttempts"
                        :key="key"
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <span
                            >{{ operationTitles[attempt.action] }} ·
                            {{
                                attemptTarget(attempt.action, attempt.targetId)
                            }}</span
                        >
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            :aria-label="`Review unresolved ${attempt.action} for ${attemptTarget(attempt.action, attempt.targetId)}`"
                            @click="
                                openOperation(attempt.action, attempt.targetId)
                            "
                            >Review attempt</Button
                        >
                    </li>
                </ul>
            </AlertDescription>
        </Alert>
        <section
            aria-labelledby="customer-title"
            class="rounded-lg border bg-card p-4 sm:p-5"
        >
            <h2 id="customer-title" class="font-semibold">Customer snapshot</h2>
            <dl class="mt-3 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div class="min-w-0">
                    <dt class="text-muted-foreground">Name</dt>
                    <dd class="mt-1 break-words">{{ order.customerName }}</dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-muted-foreground">Email</dt>
                    <dd class="mt-1 break-words">
                        {{ order.customerEmail || 'Not provided' }}
                    </dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-muted-foreground">Phone</dt>
                    <dd class="mt-1 break-words">
                        {{ order.customerPhone || 'Not provided' }}
                    </dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-muted-foreground">Address</dt>
                    <dd class="mt-1 break-words whitespace-pre-line">
                        {{ order.customerAddress || 'Not provided' }}
                    </dd>
                </div>
            </dl>
            <p v-if="order.cancellationReason" class="mt-4 text-sm">
                <strong>Cancellation reason:</strong>
                {{ order.cancellationReason }}
            </p>
        </section>

        <section
            aria-labelledby="items-title"
            class="flex min-w-0 flex-col gap-3"
        >
            <h2 id="items-title" class="text-lg font-semibold">Order items</h2>
            <div class="overflow-x-auto rounded-md border">
                <table
                    class="w-full min-w-[820px] text-left text-sm tabular-nums"
                >
                    <caption class="sr-only">
                        Order quantities, outstanding reservations, fulfilled
                        units, received returns, and prices in
                        {{
                            order.currency
                        }}
                    </caption>
                    <thead class="bg-muted/50 text-xs text-muted-foreground">
                        <tr>
                            <th scope="col" class="px-3 py-3 font-medium">
                                Item
                            </th>
                            <th scope="col" class="px-3 py-3 font-medium">
                                Ordered
                            </th>
                            <th scope="col" class="px-3 py-3 font-medium">
                                Reserved
                            </th>
                            <th scope="col" class="px-3 py-3 font-medium">
                                Fulfilled
                            </th>
                            <th scope="col" class="px-3 py-3 font-medium">
                                Cancelled
                            </th>
                            <th scope="col" class="px-3 py-3 font-medium">
                                Returned
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-3 text-right font-medium"
                            >
                                Unit price
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-3 text-right font-medium"
                            >
                                Line total
                            </th>
                            <th scope="col" class="px-3 py-3">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="item in order.items" :key="item.id">
                            <th scope="row" class="px-3 py-3 font-medium">
                                {{ item.name
                                }}<span
                                    class="mt-1 block text-xs font-normal text-muted-foreground"
                                    >{{ item.sku }}</span
                                >
                            </th>
                            <td class="px-3 py-3">{{ item.quantity }}</td>
                            <td class="px-3 py-3">
                                {{ item.reservedQuantity }}
                            </td>
                            <td class="px-3 py-3">
                                {{ item.fulfilledQuantity }}
                            </td>
                            <td class="px-3 py-3">
                                {{ item.cancelledQuantity }}
                            </td>
                            <td class="px-3 py-3">
                                {{ item.returnedQuantity }}
                            </td>
                            <td class="px-3 py-3 text-right whitespace-nowrap">
                                {{ salesMoney(item.unitPrice, order.currency) }}
                            </td>
                            <td class="px-3 py-3 text-right whitespace-nowrap">
                                {{ salesMoney(item.lineTotal, order.currency) }}
                            </td>
                            <td class="px-3 py-3 text-right">
                                <Button
                                    v-if="
                                        canReserve &&
                                        unreservedQuantity(item) > 0
                                    "
                                    variant="outline"
                                    size="sm"
                                    :disabled="form.processing"
                                    :aria-label="`Reserve stock for ${item.name}`"
                                    @click="openOperation('reserve', item.id)"
                                    >Reserve stock</Button
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="text-xs text-muted-foreground">
                Reserved means stock still awaiting fulfillment. Returned
                includes accepted and rejected customer returns.
            </p>
        </section>

        <div class="grid min-w-0 gap-6 lg:grid-cols-2">
            <section
                aria-labelledby="reservations-title"
                class="flex min-w-0 flex-col gap-3"
            >
                <h2 id="reservations-title" class="text-lg font-semibold">
                    Stock reservations
                </h2>
                <p
                    v-if="!order.reservations.length"
                    class="text-sm text-muted-foreground"
                >
                    No stock reserved yet. An admin can reserve available stock
                    from one or more bins.
                </p>
                <article
                    v-for="entry in order.reservations"
                    :key="entry.id"
                    class="flex flex-col gap-3 rounded-lg border bg-card p-4"
                >
                    <h3 class="font-medium">
                        {{ entry.itemName }} · Reservation #{{ entry.id }}
                    </h3>
                    <p class="text-sm text-muted-foreground">
                        {{ entry.warehouseName }} / {{ entry.locationName }}
                    </p>
                    <p class="text-sm tabular-nums">
                        {{ entry.remainingQuantity }} remaining of
                        {{ entry.quantity }} reserved
                    </p>
                    <Button
                        v-if="canFulfill && entry.remainingQuantity > 0"
                        variant="outline"
                        class="self-start"
                        :disabled="form.processing"
                        :aria-label="`Fulfill reservation #${entry.id}`"
                        @click="openOperation('fulfill', entry.id)"
                        >Fulfill reservation</Button
                    >
                </article>
            </section>
            <section
                aria-labelledby="fulfillments-title"
                class="flex min-w-0 flex-col gap-3"
            >
                <h2 id="fulfillments-title" class="text-lg font-semibold">
                    Fulfillments
                </h2>
                <p
                    v-if="!order.fulfillments.length"
                    class="text-sm text-muted-foreground"
                >
                    No quantities fulfilled yet. Reserve stock before recording
                    fulfillment.
                </p>
                <article
                    v-for="entry in order.fulfillments"
                    :key="entry.id"
                    class="flex flex-col gap-3 rounded-lg border bg-card p-4"
                >
                    <h3 class="font-medium">
                        {{ entry.itemName }} · Fulfillment #{{ entry.id }}
                    </h3>
                    <p class="text-sm text-muted-foreground">
                        {{ entry.warehouseName }} / {{ entry.locationName }}
                    </p>
                    <p class="text-sm tabular-nums">
                        {{ entry.quantity }} fulfilled ·
                        {{ entry.returnedQuantity }} returned ·
                        {{ entry.returnableQuantity }} returnable
                    </p>
                    <Button
                        v-if="canReturn && entry.returnableQuantity > 0"
                        variant="outline"
                        class="self-start"
                        :disabled="form.processing"
                        :aria-label="`Receive return for fulfillment #${entry.id}`"
                        @click="openOperation('return', entry.id)"
                        >Receive return</Button
                    >
                </article>
            </section>
        </div>

        <section aria-labelledby="returns-title" class="flex flex-col gap-3">
            <h2 id="returns-title" class="text-lg font-semibold">
                Customer returns
            </h2>
            <p class="text-sm text-muted-foreground">
                Only accepted returns in good condition restock inventory.
                Accepted damaged or unsellable goods can be refunded without
                restocking.
            </p>
            <p
                v-if="!order.returns.length"
                class="text-sm text-muted-foreground"
            >
                No customer returns received.
            </p>
            <article
                v-for="entry in order.returns"
                :key="entry.id"
                class="flex flex-col justify-between gap-4 rounded-lg border bg-card p-4 sm:flex-row sm:items-start"
            >
                <div class="flex min-w-0 flex-col gap-2">
                    <h3 class="font-medium">
                        {{ entry.itemName }} · Return #{{ entry.id }}
                    </h3>
                    <div class="flex flex-wrap items-center gap-2">
                        <Badge
                            :variant="entry.accepted ? 'secondary' : 'outline'"
                            >{{
                                entry.accepted ? 'Accepted' : 'Rejected'
                            }}</Badge
                        ><span class="text-sm"
                            >{{ entry.quantity }} units ·
                            {{ entry.condition }}</span
                        >
                    </div>
                    <p class="text-sm break-words">
                        Reason: {{ entry.reason }}
                    </p>
                    <p class="text-sm text-muted-foreground tabular-nums">
                        Refundable
                        {{ salesMoney(entry.refundableAmount, order.currency) }}
                        · Remaining
                        {{
                            salesMoney(
                                entry.remainingRefundableAmount,
                                order.currency,
                            )
                        }}
                    </p>
                </div>
                <Button
                    v-if="
                        canRefund &&
                        entry.accepted &&
                        Number(entry.remainingRefundableAmount) > 0
                    "
                    variant="outline"
                    :disabled="form.processing"
                    :aria-label="`Record refund for return #${entry.id}`"
                    @click="openOperation('refund', entry.id)"
                    >Record refund</Button
                >
            </article>
        </section>

        <section aria-labelledby="refunds-title" class="flex flex-col gap-3">
            <div>
                <h2 id="refunds-title" class="text-lg font-semibold">
                    Refund tracking
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Records only. No payment is sent by this system. Pending
                    refunds may be marked refunded or cancelled; refunded
                    records cannot be changed.
                </p>
            </div>
            <p
                v-if="!order.refunds.length"
                class="text-sm text-muted-foreground"
            >
                No refund records.
            </p>
            <article
                v-for="entry in order.refunds"
                :key="entry.id"
                class="flex flex-col justify-between gap-4 rounded-lg border bg-card p-4 sm:flex-row sm:items-start"
            >
                <div class="flex min-w-0 flex-col gap-2">
                    <h3 class="font-medium">
                        Refund #{{ entry.id }} ·
                        {{ salesMoney(entry.amount, order.currency) }}
                    </h3>
                    <div class="flex flex-wrap items-center gap-2">
                        <Badge
                            :variant="
                                entry.status === 'cancelled'
                                    ? 'outline'
                                    : 'secondary'
                            "
                            >{{ entry.status }}</Badge
                        ><span class="text-sm"
                            >{{ returnName(entry.salesReturnId) }} · Return #{{
                                entry.salesReturnId
                            }}</span
                        >
                    </div>
                    <p class="text-sm break-words text-muted-foreground">
                        Reference: {{ entry.reference || 'Not provided' }}
                    </p>
                </div>
                <Button
                    v-if="canRefund && entry.status === 'pending'"
                    variant="outline"
                    :disabled="form.processing"
                    :aria-label="`Update refund #${entry.id}`"
                    @click="openOperation('refundUpdate', entry.id)"
                    >Update refund record</Button
                >
            </article>
        </section>

        <section
            aria-labelledby="history-title"
            class="flex flex-col gap-3 border-t pt-5"
        >
            <h2 id="history-title" class="text-lg font-semibold">
                Order history
            </h2>
            <p
                v-if="!order.events.length"
                class="text-sm text-muted-foreground"
            >
                No order events yet.
            </p>
            <ol class="flex flex-col gap-4">
                <li
                    v-for="event in order.events"
                    :key="event.id"
                    class="text-sm"
                >
                    <p class="break-words">
                        {{ event.note || 'Order updated' }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ event.userName }} ·
                        <time
                            v-if="event.createdAt"
                            :datetime="event.createdAt"
                            >{{ salesDate(event.createdAt) }}</time
                        ><span v-else>Date not recorded</span>
                    </p>
                </li>
            </ol>
        </section>

        <Dialog
            :open="operationOpen"
            @update:open="
                (open) => {
                    if (!form.processing) operationOpen = open;
                }
            "
        >
            <DialogContent
                class="max-h-[90vh] overflow-y-auto sm:max-w-lg"
                @interact-outside="
                    (event) => {
                        if (form.processing) event.preventDefault();
                    }
                "
                @escape-key-down="
                    (event) => {
                        if (form.processing) event.preventDefault();
                    }
                "
            >
                <form
                    class="flex flex-col gap-5"
                    :aria-busy="form.processing"
                    @submit.prevent="submitOperation"
                >
                    <DialogHeader class="text-left"
                        ><DialogTitle>{{ title }}</DialogTitle
                        ><DialogDescription>{{
                            description
                        }}</DialogDescription></DialogHeader
                    >
                    <Alert v-if="attemptStorageError" variant="destructive">
                        <AlertTitle
                            >Retry protection needs attention</AlertTitle
                        >
                        <AlertDescription
                            >{{ attemptStorageError }} Close this dialog to
                            review saved retry protection.</AlertDescription
                        >
                    </Alert>
                    <Alert v-if="isUncertain && !form.processing">
                        <AlertTitle>Result not confirmed</AlertTitle>
                        <AlertDescription>
                            This operation may already be recorded. Its original
                            values are locked. Retry unchanged with the same
                            request key to confirm it safely. Closing this
                            dialog keeps the attempt for later review.
                        </AlertDescription>
                    </Alert>
                    <Alert
                        v-else-if="
                            currentAttempt?.state === 'validation' &&
                            !form.processing
                        "
                    >
                        <AlertTitle>Correct the validation errors</AlertTitle>
                        <AlertDescription
                            >Edit the fields below and submit again. This
                            attempt keeps its original request
                            key.</AlertDescription
                        >
                    </Alert>
                    <Alert v-if="startNewWarning" variant="destructive">
                        <AlertTitle
                            >Starting separately can record a
                            duplicate</AlertTitle
                        >
                        <AlertDescription>
                            <p>
                                Check order history first. Starting a separate
                                operation abandons the original retry key and
                                uses a new one. If the earlier operation
                                completed, the new submission can record an
                                additional fulfillment, return, or refund.
                            </p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    :disabled="form.processing"
                                    @click="startNewWarning = false"
                                    >Keep original attempt</Button
                                >
                                <Button
                                    type="button"
                                    variant="destructive"
                                    :disabled="form.processing"
                                    @click="startNewOperation"
                                    >I checked history — start
                                    separately</Button
                                >
                            </div>
                        </AlertDescription>
                    </Alert>
                    <fieldset
                        :disabled="form.processing || isUncertain"
                        class="flex min-w-0 flex-col gap-4"
                    >
                        <legend class="sr-only">{{ title }} details</legend>
                        <SalesOrderField
                            v-if="
                                ['reserve', 'fulfill', 'return'].includes(
                                    operation,
                                )
                            "
                            id="so-operation-quantity"
                            v-slot="field"
                            label="Quantity"
                            :error="errors.quantity"
                        >
                            <Input
                                id="so-operation-quantity"
                                v-model.number="form.quantity"
                                type="number"
                                min="1"
                                step="1"
                                :max="maxQuantity"
                                required
                                :aria-invalid="field.invalid"
                                :aria-describedby="field.describedby"
                            />
                        </SalesOrderField>
                        <template v-if="operation === 'return'">
                            <SalesOrderField
                                id="so-return-condition"
                                v-slot="field"
                                label="Return condition"
                                :error="errors.condition"
                            >
                                <select
                                    id="so-return-condition"
                                    v-model="form.condition"
                                    :class="salesSelectClass"
                                    required
                                    :aria-invalid="field.invalid"
                                    :aria-describedby="field.describedby"
                                >
                                    <option value="good">Good</option>
                                    <option value="damaged">Damaged</option>
                                    <option value="unsellable">
                                        Unsellable
                                    </option>
                                </select>
                            </SalesOrderField>
                            <SalesOrderField
                                id="so-return-decision"
                                v-slot="field"
                                label="Return decision"
                                :error="errors.accepted"
                                hint="This decision is recorded immediately. Rejected returns also reduce the remaining returnable quantity."
                            >
                                <select
                                    id="so-return-decision"
                                    v-model="form.accepted"
                                    :class="salesSelectClass"
                                    :aria-invalid="field.invalid"
                                    :aria-describedby="field.describedby"
                                >
                                    <option :value="true">Accepted</option>
                                    <option :value="false">Rejected</option>
                                </select>
                            </SalesOrderField>
                            <p
                                class="text-sm text-muted-foreground"
                                role="status"
                            >
                                {{
                                    restocks
                                        ? 'Accepted good stock will be restocked in the bin below.'
                                        : form.accepted
                                          ? 'This return can be refunded, but will not restock inventory.'
                                          : 'This return will not restock inventory and cannot be refunded.'
                                }}
                            </p>
                        </template>
                        <template v-if="needsLocation">
                            <SalesOrderField
                                id="so-operation-warehouse"
                                v-slot="field"
                                :label="
                                    restocks ? 'Restock warehouse' : 'Warehouse'
                                "
                                :error="errors.warehouse_id"
                            >
                                <select
                                    id="so-operation-warehouse"
                                    v-model="form.warehouse_id"
                                    required
                                    :class="salesSelectClass"
                                    :aria-invalid="field.invalid"
                                    :aria-describedby="field.describedby"
                                    @change="form.warehouse_location_id = ''"
                                >
                                    <option value="" disabled>
                                        Select warehouse
                                    </option>
                                    <option
                                        v-for="warehouse in warehouses"
                                        :key="warehouse.id"
                                        :value="String(warehouse.id)"
                                    >
                                        {{ warehouse.name }}
                                    </option>
                                </select>
                            </SalesOrderField>
                            <SalesOrderField
                                id="so-operation-bin"
                                v-slot="field"
                                :label="restocks ? 'Restock bin' : 'Bin'"
                                :error="errors.warehouse_location_id"
                            >
                                <select
                                    id="so-operation-bin"
                                    v-model="form.warehouse_location_id"
                                    required
                                    :disabled="
                                        !selectedWarehouse?.locations.length
                                    "
                                    :class="salesSelectClass"
                                    :aria-invalid="field.invalid"
                                    :aria-describedby="field.describedby"
                                >
                                    <option value="" disabled>
                                        Select bin
                                    </option>
                                    <option
                                        v-for="location in selectedWarehouse?.locations ??
                                        []"
                                        :key="location.id"
                                        :value="String(location.id)"
                                    >
                                        {{ location.name }}
                                    </option>
                                </select>
                            </SalesOrderField>
                            <p
                                v-if="
                                    !warehouses.length ||
                                    (selectedWarehouse &&
                                        !selectedWarehouse.locations.length)
                                "
                                class="text-sm text-muted-foreground"
                            >
                                A warehouse with a bin is required. Ask an admin
                                to configure one before continuing.
                            </p>
                        </template>
                        <SalesOrderField
                            v-if="
                                operation === 'return' || operation === 'cancel'
                            "
                            id="so-operation-reason"
                            v-slot="field"
                            :label="
                                operation === 'return'
                                    ? 'Return reason'
                                    : 'Cancellation reason'
                            "
                            :error="errors.reason"
                        >
                            <Input
                                id="so-operation-reason"
                                v-model="form.reason"
                                required
                                maxlength="2000"
                                :aria-invalid="field.invalid"
                                :aria-describedby="field.describedby"
                            />
                        </SalesOrderField>
                        <template
                            v-if="
                                operation === 'refund' ||
                                operation === 'refundUpdate'
                            "
                        >
                            <SalesOrderField
                                v-if="operation === 'refund'"
                                id="so-refund-amount"
                                v-slot="field"
                                :label="`Refund amount (${order.currency})`"
                                :error="errors.amount"
                            >
                                <Input
                                    id="so-refund-amount"
                                    v-model="form.amount"
                                    type="number"
                                    min="0.01"
                                    :max="
                                        selectedReturn?.remainingRefundableAmount
                                    "
                                    step="0.01"
                                    required
                                    :aria-invalid="field.invalid"
                                    :aria-describedby="field.describedby"
                                />
                            </SalesOrderField>
                            <SalesOrderField
                                id="so-refund-status"
                                v-slot="field"
                                label="Refund status"
                                :error="errors.status"
                            >
                                <select
                                    id="so-refund-status"
                                    v-model="form.status"
                                    :class="salesSelectClass"
                                    required
                                    :aria-invalid="field.invalid"
                                    :aria-describedby="field.describedby"
                                >
                                    <option
                                        v-if="operation === 'refund'"
                                        value="pending"
                                    >
                                        Pending
                                    </option>
                                    <option value="refunded">
                                        Refunded (tracking only)
                                    </option>
                                    <option
                                        v-if="operation === 'refundUpdate'"
                                        value="cancelled"
                                    >
                                        Cancelled
                                    </option>
                                </select>
                            </SalesOrderField>
                            <SalesOrderField
                                id="so-refund-reference"
                                v-slot="field"
                                label="Refund reference (optional)"
                                :error="errors.reference"
                            >
                                <Input
                                    id="so-refund-reference"
                                    v-model="form.reference"
                                    maxlength="255"
                                    :aria-invalid="field.invalid"
                                    :aria-describedby="field.describedby"
                                />
                            </SalesOrderField>
                        </template>
                    </fieldset>
                    <ul
                        v-if="form.hasErrors"
                        role="alert"
                        aria-label="Operation validation errors"
                        class="flex flex-col gap-1 text-sm text-destructive"
                    >
                        <li v-for="(error, key) in errors" :key="key">
                            {{ error }}
                        </li>
                    </ul>
                    <Button
                        v-if="currentAttempt && !startNewWarning"
                        type="button"
                        variant="outline"
                        class="self-start"
                        :disabled="form.processing"
                        @click="requestNewOperation"
                        >Start a new operation</Button
                    >
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="form.processing"
                            @click="operationOpen = false"
                            >Close</Button
                        >
                        <Button
                            type="submit"
                            :variant="
                                operation === 'cancel' ||
                                (operation === 'refundUpdate' &&
                                    form.status === 'cancelled')
                                    ? 'destructive'
                                    : 'default'
                            "
                            :disabled="blocked"
                            >{{ submitLabel }}</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </main>
</template>

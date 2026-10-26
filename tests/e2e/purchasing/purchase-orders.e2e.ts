import { describe, test } from '@e2e-dev/web';
import { credentials, expect } from 'e2e';

test.setup(
    'sign in as the purchasing tester',
    { sessions: ['purchasing-user'] },
    async ({ app, screen, browser, session }) => {
        const purchasingUser = credentials.user('e2e');

        await app.open('/login');
        await screen.getByLabel('Email address').fill(purchasingUser.username);
        await screen.getByLabel('Password').fill(purchasingUser.password);
        await screen.getByRole('button', 'Log in').tap();
        await expect(browser).toHaveURL(/\/e2e-test-user\/dashboard$/);
        await session.save('purchasing-user');
    },
);

describe('purchase order workflow', () => {
    test(
        'creates, submits, approves, and receives a purchase order',
        { session: 'purchasing-user' },
        async ({ app, screen, browser }) => {
            const suffix = Date.now();
            const supplierName = `E2E Supplier ${suffix}`;
            const sku = `E2E-PO-${suffix}`;
            const itemName = `E2E Purchase Item ${suffix}`;
            const reference = `QUOTE-${suffix}`;

            await app.open('/e2e-test-user/suppliers');
            await screen.getByRole('button', 'Add supplier').last().tap();
            await screen.getByLabel('Supplier name').fill(supplierName);
            await screen.getByRole('button', 'Save supplier').tap();
            await expect(
                screen.getByRole('heading', supplierName),
            ).toBeVisible();

            await app.open('/e2e-test-user/inventories');
            await screen.getByRole('button', 'Add item').tap();
            await screen.getByLabel('Product name').fill(itemName);
            await screen.getByLabel('SKU').fill(sku);
            await screen.getByLabel('Category').fill('Testing');
            await screen.getByLabel('Opening quantity').fill('0');
            await screen.getByLabel('Reorder point').fill('0');
            await screen.getByLabel('Unit cost').fill('1.25');
            await screen.getByRole('button', 'Add item').last().tap();
            await screen.getByLabel('Search inventory').fill(sku);
            await expect(screen.getByText(itemName)).toBeVisible();

            await app.open('/e2e-test-user/purchase-orders');
            await screen.getByRole('button', 'New purchase order').last().tap();
            await expect(
                screen.getByRole('dialog', 'New purchase order'),
            ).toBeVisible();
            await expect(screen.getByLabel('Inventory item')).toHaveValue('');
            await expect(
                screen.getByRole('button', 'Create draft'),
            ).toBeDisabled();
            await screen.getByRole('button', 'Add item').tap();
            await expect(
                screen.getByLabel('Inventory item').nth(1),
            ).toHaveValue('');
            await expect(screen.getByLabel('Unit cost').nth(1)).toHaveValue(
                '0',
            );
            await screen.getByRole('button', 'Remove item 2').tap();
            await screen
                .getByLabel('Supplier')
                .selectOption({ label: supplierName });
            await screen.getByLabel('Expected date').fill('2027-01-15');
            await screen.getByLabel('Reference').fill(reference);
            await screen.getByLabel('Inventory item').selectOption({
                label: `${itemName} · ${sku.toUpperCase()}`,
            });
            await screen.getByLabel('Quantity').fill('8');
            await screen.getByLabel('Unit cost').fill('1.25');
            await screen.getByRole('button', 'Create draft').tap();

            const orderCard = screen
                .getByRole('article')
                .filter({ hasText: reference });
            const orderNumber = (
                await orderCard.getByRole('heading').textContent()
            )?.trim();
            if (!orderNumber) {
                throw new Error('The new purchase order has no order number.');
            }
            const stableOrderCard = screen
                .getByRole('article')
                .filter({ hasText: orderNumber });
            await expect(orderCard).toContainText('Draft');
            await expect(orderCard).toContainText(reference);
            await expect(orderCard).toContainText('$10.00');

            const cancellationReference = `CANCEL-${suffix}`;
            await screen.getByRole('button', 'New purchase order').last().tap();
            const cancellationDraftDialog = screen.getByRole(
                'dialog',
                'New purchase order',
            );
            await expect(cancellationDraftDialog).toBeVisible();
            await screen
                .getByLabel('Supplier')
                .selectOption({ label: supplierName });
            await screen.getByLabel('Expected date').fill('2027-01-15');
            await screen.getByLabel('Reference').fill(cancellationReference);
            await screen.getByLabel('Inventory item').selectOption({
                label: `${itemName} · ${sku.toUpperCase()}`,
            });
            await screen.getByLabel('Quantity').fill('1');
            await screen.getByLabel('Unit cost').fill('1.25');
            await screen.getByRole('button', 'Create draft').tap();

            const cancellationCard = screen
                .getByRole('article')
                .filter({ hasText: cancellationReference });
            await expect(cancellationCard).toContainText('Draft');
            await cancellationCard.getByRole('button', 'Cancel').tap();
            const cancellationDialog = screen.getByRole(
                'dialog',
                'Cancel purchase order',
            );
            await expect(cancellationDialog).toBeVisible();
            await screen
                .getByLabel('Cancellation reason')
                .fill('Duplicate draft');
            await screen.getByRole('button', 'Cancel order').tap();
            await expect(cancellationCard).toContainText('Cancelled');
            await expect(cancellationCard).toContainText(
                'Cancellation reason: Duplicate draft',
            );

            await stableOrderCard.getByRole('button', 'Submit').tap();
            await expect(stableOrderCard).toContainText('Awaiting approval');

            await stableOrderCard.getByRole('button', 'Request revision').tap();
            const revisionDialog = screen.getByRole('dialog');
            await expect(revisionDialog).toBeVisible();
            await screen
                .getByLabel('Revision reason')
                .fill('Please confirm the required quantity.');
            await screen.getByRole('button', 'Send back for revision').tap();
            await expect(stableOrderCard).toContainText('Draft');

            const revisedExpectedDate = '2027-01-22';
            await stableOrderCard.getByRole('button', 'Edit draft').tap();
            const editDialog = screen.getByRole(
                'dialog',
                'Edit purchase order',
            );
            await expect(editDialog).toBeVisible();
            await screen.getByLabel('Expected date').fill(revisedExpectedDate);
            await screen.getByRole('button', 'Save changes').tap();
            await expect(stableOrderCard).toContainText(
                'Expected Jan 22, 2027',
            );

            await stableOrderCard.getByRole('button', 'Submit').tap();
            await expect(stableOrderCard).toContainText('Awaiting approval');

            await stableOrderCard.getByRole('button', 'Approve').tap();
            await expect(stableOrderCard).toContainText('Approved');

            await app.open('/e2e-test-user/suppliers');
            await browser.onDialog('accept');
            await screen
                .getByRole('listitem')
                .filter({ hasText: supplierName })
                .getByRole('button', 'Archive')
                .tap();
            await expect(
                screen.getByText(
                    'This supplier has open purchase orders and cannot be archived.',
                ),
            ).toBeVisible();
            await expect(
                screen.getByRole('heading', supplierName),
            ).toBeVisible();

            await app.open('/e2e-test-user/purchase-orders');

            await stableOrderCard.getByRole('button', 'Receive').tap();
            await expect(
                screen.getByRole('dialog', `Receive ${itemName}`),
            ).toBeVisible();
            await screen.getByLabel('Warehouse').selectOption({ index: 0 });
            await screen.getByLabel('Bin').selectOption({ index: 0 });
            await screen.getByLabel('Quantity received').fill('3');
            let capturedReceiptRequest: {
                url: string;
                headers: Record<string, string>;
                postData: string;
            } | null = null;
            const receiptPattern = '**/e2e-test-user/purchase-orders/*/receive';
            const captureReceiptRequest = async (
                route: Parameters<Parameters<typeof browser.route>[1]>[0],
            ) => {
                capturedReceiptRequest = {
                    url: route.request.url,
                    headers: { ...route.request.headers },
                    postData: route.request.postData ?? '',
                };
                await route.continue();
            };
            await browser.route(receiptPattern, captureReceiptRequest);
            await screen.getByRole('button', 'Record receipt').tap();

            await expect(stableOrderCard).toContainText('Partially received');
            await expect(stableOrderCard).toContainText('3 / 8');
            await browser.unroute(receiptPattern);
            if (!capturedReceiptRequest) {
                throw new Error('The receipt form did not send its request.');
            }
            const receiptReplayResponse = await browser.evaluate(
                async (request) => {
                    const headers: Record<string, string> = {};
                    for (const name of [
                        'accept',
                        'content-type',
                        'x-inertia',
                        'x-inertia-version',
                        'x-requested-with',
                        'x-xsrf-token',
                    ]) {
                        const value = request.headers[name];
                        if (value) headers[name] = value;
                    }
                    const response = await fetch(request.url, {
                        method: 'POST',
                        headers,
                        body: request.postData,
                        credentials: 'same-origin',
                    });
                    return response.status;
                },
                capturedReceiptRequest,
            );
            expect(receiptReplayResponse).toBe(200);
            await app.open('/e2e-test-user/purchase-orders');
            await expect(stableOrderCard).toContainText('3 / 8');

            await stableOrderCard.getByRole('button', 'Receive').tap();
            await expect(
                screen.getByRole('dialog', `Receive ${itemName}`),
            ).toBeVisible();
            await screen.getByLabel('Quantity received').fill('5');
            await screen.getByRole('button', 'Record receipt').tap();

            await expect(stableOrderCard).toContainText('Received');
            await expect(stableOrderCard).toContainText('8 / 8');
            await stableOrderCard.getByText('Order history (8)').tap();
            await expect(stableOrderCard).toContainText(
                `Received 3 × ${itemName}`,
            );
            await expect(stableOrderCard).toContainText(
                `Received 5 × ${itemName}`,
            );

            await stableOrderCard.getByRole('button', 'Return').tap();
            await expect(
                screen.getByRole('dialog', `Return ${itemName}`),
            ).toBeVisible();
            await screen.getByLabel('Quantity returned').fill('2');
            await screen.getByLabel('Return reason').fill('Damaged in transit');
            let capturedReturnRequest: {
                url: string;
                headers: Record<string, string>;
                postData: string;
            } | null = null;
            const returnPattern = '**/e2e-test-user/purchase-orders/*/return';
            const captureReturnRequest = async (
                route: Parameters<Parameters<typeof browser.route>[1]>[0],
            ) => {
                capturedReturnRequest = {
                    url: route.request.url,
                    headers: { ...route.request.headers },
                    postData: route.request.postData ?? '',
                };
                await route.continue();
            };
            await browser.route(returnPattern, captureReturnRequest);
            await screen.getByRole('button', 'Record return').tap();
            await expect(stableOrderCard).toContainText('2 returned');
            await browser.unroute(returnPattern);
            if (!capturedReturnRequest) {
                throw new Error(
                    'The supplier return form did not send its request.',
                );
            }
            const returnReplayResponse = await browser.evaluate(
                async (request) => {
                    const headers: Record<string, string> = {};
                    for (const name of [
                        'accept',
                        'content-type',
                        'x-inertia',
                        'x-inertia-version',
                        'x-requested-with',
                        'x-xsrf-token',
                    ]) {
                        const value = request.headers[name];
                        if (value) headers[name] = value;
                    }
                    const response = await fetch(request.url, {
                        method: 'POST',
                        headers,
                        body: request.postData,
                        credentials: 'same-origin',
                    });
                    return response.status;
                },
                capturedReturnRequest,
            );
            expect(returnReplayResponse).toBe(200);
            await app.open('/e2e-test-user/purchase-orders');
            await expect(stableOrderCard).toContainText('2 returned');
            await stableOrderCard.getByText('Order history (9)').tap();
            await expect(stableOrderCard).toContainText(
                `Returned 2 × ${itemName} (${sku.toUpperCase()}) to supplier: Damaged in transit`,
            );

            await app.open('/e2e-test-user/suppliers');
            await browser.onDialog('accept');
            await screen
                .getByRole('listitem')
                .filter({ hasText: supplierName })
                .getByRole('button', 'Archive')
                .tap();

            await app.open('/e2e-test-user/purchase-orders');
            const historicalOrder = screen
                .getByRole('article')
                .filter({ hasText: orderNumber });
            await expect(historicalOrder).toBeVisible();
            await expect(historicalOrder).toContainText('Received');

            await screen
                .getByLabel('Search purchase orders')
                .fill(supplierName);
            await screen
                .getByLabel('Status')
                .selectOption({ label: 'Received' });
            await screen.getByRole('button', 'Search').tap();
            await expect(historicalOrder).toBeVisible();
            await expect(historicalOrder).toContainText(reference);
        },
    );
});

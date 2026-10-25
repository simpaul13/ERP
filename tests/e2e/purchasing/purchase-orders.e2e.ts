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
                .filter({ hasText: supplierName });
            await expect(orderCard).toContainText('Draft');
            await expect(orderCard).toContainText(reference);
            await expect(orderCard).toContainText('$10.00');

            await orderCard.getByRole('button', 'Submit').tap();
            await expect(orderCard).toContainText('Awaiting approval');

            await orderCard.getByRole('button', 'Approve').tap();
            await expect(orderCard).toContainText('Approved');

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

            await orderCard.getByRole('button', 'Receive').tap();
            await expect(
                screen.getByRole('dialog', `Receive ${itemName}`),
            ).toBeVisible();
            await screen.getByLabel('Warehouse').selectOption({ index: 0 });
            await screen.getByLabel('Bin').selectOption({ index: 0 });
            await screen.getByLabel('Quantity received').fill('3');
            await screen.getByRole('button', 'Record receipt').tap();

            await expect(orderCard).toContainText('Partially received');
            await expect(orderCard).toContainText('3 / 8');

            await orderCard.getByRole('button', 'Receive').tap();
            await expect(
                screen.getByRole('dialog', `Receive ${itemName}`),
            ).toBeVisible();
            await screen.getByLabel('Quantity received').fill('5');
            await screen.getByRole('button', 'Record receipt').tap();

            await expect(orderCard).toContainText('Received');
            await expect(orderCard).toContainText('8 / 8');
            await orderCard.getByText('Order history (5)').tap();
            await expect(orderCard).toContainText(`Received 3 × ${itemName}`);
            await expect(orderCard).toContainText(`Received 5 × ${itemName}`);

            await orderCard.getByRole('button', 'Return').tap();
            await expect(
                screen.getByRole('dialog', `Return ${itemName}`),
            ).toBeVisible();
            await screen.getByLabel('Quantity returned').fill('2');
            await screen.getByLabel('Return reason').fill('Damaged in transit');
            await screen.getByRole('button', 'Record return').tap();
            await expect(orderCard).toContainText('2 returned');
            await expect(orderCard).toContainText(
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
                .filter({ hasText: supplierName });
            await expect(historicalOrder).toBeVisible();
            await expect(historicalOrder).toContainText('Received');
        },
    );
});

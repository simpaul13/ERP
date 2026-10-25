import { describe, test, type Browser } from '@e2e-dev/web';
import { credentials, expect, type Screen } from 'e2e';

test.setup(
    'sign in as the sales tester',
    { sessions: ['sales-user'] },
    async ({ app, screen, browser, session }) => {
        const user = credentials.user('e2e');
        await app.open('/login');
        await screen.getByLabel('Email address').fill(user.username);
        await screen.getByLabel('Password').fill(user.password);
        await screen.getByRole('button', 'Log in').tap();
        await expect(browser).toHaveURL(/\/e2e-test-user\/dashboard$/);

        await app.open('/e2e-test-user/warehouses');
        await expect(
            screen.getByRole('heading', 'Warehouses', { level: 1 }),
        ).toBeVisible();
        const warehouseLink = screen.getByRole('link', 'Main warehouse', {
            exact: true,
        });
        if ((await warehouseLink.count()) === 0) {
            await screen.getByRole('button', 'Add warehouse').first().tap();
            const dialog = screen.getByRole('dialog', 'Add warehouse');
            await dialog.getByLabel('Name').fill('Main warehouse');
            await dialog
                .getByLabel("Use as the team's default warehouse")
                .check();
            await dialog.getByRole('button', 'Add warehouse').tap();
            await expect(dialog).toHaveCount(0);
        }
        await expect(warehouseLink).toBeVisible();
        const warehouseCard = screen
            .getByRole('article')
            .filter({ has: warehouseLink });
        const defaultBin = warehouseCard.getByText(
            /^Default bin\s*·\s*DEFAULT$/,
        );
        if ((await defaultBin.count()) === 0) {
            await warehouseCard.getByRole('button', 'Add bin').tap();
            const dialog = screen.getByRole('dialog', 'Add bin');
            await dialog.getByLabel('Name').fill('Default bin');
            await dialog.getByLabel('Code').fill('DEFAULT');
            await dialog.getByRole('button', 'Add bin').tap();
            await expect(dialog).toHaveCount(0);
        }
        await expect(defaultBin).toBeVisible();
        await app.open('/e2e-test-user/inventories');
        await expect(
            screen.getByRole('heading', 'Inventory', { level: 1 }),
        ).toBeVisible();
        await session.save('sales-user');
    },
);

async function createSalesInventory(
    screen: Screen,
    browser: Browser,
    itemName: string,
    sku: string,
    quantity: number,
): Promise<void> {
    await screen.getByRole('button', 'Add item').tap();
    await screen.getByLabel('Product name').fill(itemName);
    await screen.getByLabel('SKU').fill(sku);
    await screen
        .getByLabel('Warehouse', { exact: true })
        .selectOption({ label: 'Main warehouse' });
    await screen
        .getByLabel('Bin (optional)')
        .selectOption({ label: 'Default bin · DEFAULT' });
    await screen.getByLabel('Opening quantity').fill(String(quantity));
    await screen.getByLabel('Reorder point').fill('0');
    await screen.getByLabel('Unit cost').fill('2.15');
    await screen.getByRole('button', 'Add item').last().tap();
    await expect(screen.getByRole('dialog', 'Add inventory item')).toHaveCount(
        0,
    );
    await searchSalesInventory(screen, browser, sku);
    await expect(screen.getByText(itemName)).toBeVisible();
}

async function searchSalesInventory(
    screen: Screen,
    browser: Browser,
    sku: string,
): Promise<void> {
    await screen.getByLabel('Search inventory').fill(sku);
    await expect(browser).toHaveURL(
        new RegExp(
            `/e2e-test-user/inventories\\?(?:[^#]*&)?search=${encodeURIComponent(sku)}(?:&|$)`,
        ),
    );
    await expect(
        screen.getByRole('row').filter({ hasText: sku }),
    ).toBeVisible();
}

async function createSalesDraft(
    screen: Screen,
    customer: string,
    itemName: string,
    sku: string,
    quantity: number,
): Promise<void> {
    await screen.getByRole('button', 'New sales order').first().tap();
    const dialog = screen.getByRole('dialog', 'New sales order');
    await expect(dialog.getByLabel('Currency')).toHaveValue(
        'PHP — Philippine peso',
    );
    await dialog.getByLabel('Customer name').fill(customer);
    await dialog
        .getByLabel('Customer email (optional)')
        .fill('buyer@example.test');
    await dialog
        .getByLabel('Inventory item 1')
        .selectOption({ label: `${itemName} · ${sku}` });
    await dialog.getByLabel('Quantity 1').fill(String(quantity));
    await dialog.getByLabel('Unit price 1 (PHP)').fill('10.01');
    await dialog.getByRole('button', 'Create draft').tap();
    await expect(dialog).toHaveCount(0);
    await expect(screen.getByRole('region', 'Customer snapshot')).toContainText(
        customer,
    );
    await expect(screen.getByText('Draft', { exact: true })).toBeVisible();
}

async function reserveSalesStock(
    screen: Screen,
    itemName: string,
    quantity: number,
): Promise<void> {
    await screen.getByRole('button', `Reserve stock for ${itemName}`).tap();
    const dialog = screen.getByRole('dialog', 'Reserve stock');
    await dialog
        .getByLabel('Warehouse')
        .selectOption({ label: 'Main warehouse' });
    await dialog.getByLabel('Bin').selectOption({ label: 'Default bin' });
    await dialog.getByLabel('Quantity').fill(String(quantity));
    await dialog.getByRole('button', 'Reserve stock').tap();
    await expect(dialog).toHaveCount(0);
    await expect(
        screen.getByRole('region', 'Stock reservations'),
    ).toContainText(`${quantity} remaining of ${quantity} reserved`);
}

describe('sales order workflow', () => {
    test(
        'creates reserves fulfills returns and tracks a peso refund',
        { session: 'sales-user', timeout: 180_000 },
        async ({ app, screen, browser }) => {
            const suffix = `${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
            const sku = `E2E-SALE-${suffix}`.toUpperCase();
            const itemName = `E2E Sales Item ${suffix}`;
            const customer = `E2E Sales Buyer ${suffix}`;
            const reference = `MANUAL-REFUND-${suffix}`;

            await app.open('/e2e-test-user/inventories');
            await createSalesInventory(screen, browser, itemName, sku, 10);
            await screen.getByRole('link', 'Sales', { exact: true }).tap();
            await expect(
                screen.getByRole('heading', 'Sales orders'),
            ).toBeVisible();
            await createSalesDraft(screen, customer, itemName, sku, 5);
            await expect(browser).toHaveURL(/\/sales-orders\/\d+$/);
            await expect(
                screen.getByText(/Order total · PHP\s*50\.05/),
            ).toBeVisible();
            const orderUrl = await browser.url();

            await reserveSalesStock(screen, itemName, 5);
            await app.open('/e2e-test-user/inventories');
            await searchSalesInventory(screen, browser, sku);
            await expect(
                screen
                    .getByRole('row')
                    .filter({ hasText: sku })
                    .getByRole('cell')
                    .nth(3),
            ).toContainText(/^10\s*\/ min 0$/);
            await app.open(orderUrl);

            await screen
                .getByRole('button', /^Fulfill reservation #\d+$/)
                .tap();
            const fulfillmentDialog = screen.getByRole(
                'dialog',
                'Fulfill reservation',
            );
            await fulfillmentDialog.getByLabel('Quantity').fill('5');
            await fulfillmentDialog
                .getByRole('button', 'Fulfill reservation')
                .tap();
            await expect(fulfillmentDialog).toHaveCount(0);
            await expect(
                screen.getByRole('region', 'Fulfillments'),
            ).toContainText('5 fulfilled · 0 returned · 5 returnable');

            await screen
                .getByRole('button', /^Receive return for fulfillment #\d+$/)
                .tap();
            const returnDialog = screen.getByRole(
                'dialog',
                'Receive customer return',
            );
            await returnDialog.getByLabel('Quantity').fill('1');
            await returnDialog
                .getByLabel('Return condition')
                .selectOption({ label: 'Damaged' });
            await returnDialog
                .getByLabel('Return decision')
                .selectOption({ label: 'Rejected' });
            await returnDialog
                .getByLabel('Return reason')
                .fill('Rejected damaged item');
            await returnDialog
                .getByRole('button', 'Receive customer return')
                .tap();
            await expect(returnDialog).toHaveCount(0);
            const returns = screen.getByRole('region', 'Customer returns');
            await expect(
                returns
                    .getByRole('article')
                    .filter({ hasText: 'Rejected damaged item' }),
            ).toContainText('Rejected');
            await expect(
                screen.getByRole('region', 'Fulfillments'),
            ).toContainText('5 fulfilled · 1 returned · 4 returnable');

            await screen
                .getByRole('button', /^Receive return for fulfillment #\d+$/)
                .tap();
            await expect(
                returnDialog.getByLabel('Return condition'),
            ).toHaveValue('good');
            await expect(
                returnDialog.getByLabel('Return decision'),
            ).toHaveValue('true');
            await expect(returnDialog.getByLabel('Return reason')).toHaveValue(
                '',
            );
            await returnDialog.getByLabel('Quantity').fill('2');
            await returnDialog
                .getByLabel('Restock warehouse')
                .selectOption({ label: 'Main warehouse' });
            await returnDialog
                .getByLabel('Restock bin')
                .selectOption({ label: 'Default bin' });
            await returnDialog
                .getByLabel('Return reason')
                .fill('Unopened customer return');
            await returnDialog
                .getByRole('button', 'Receive customer return')
                .tap();
            await expect(returnDialog).toHaveCount(0);
            await expect(
                screen.getByRole('region', 'Customer returns'),
            ).toContainText('2 units · good');
            await expect(
                screen.getByRole('region', 'Customer returns'),
            ).toContainText(/Refundable PHP\s*20\.02/);

            await screen
                .getByRole('button', /^Receive return for fulfillment #\d+$/)
                .tap();
            await returnDialog.getByLabel('Quantity').fill('1');
            await returnDialog
                .getByLabel('Return condition')
                .selectOption({ label: 'Damaged' });
            await returnDialog
                .getByLabel('Return reason')
                .fill('Damaged accepted by admin');
            await returnDialog
                .getByRole('button', 'Receive customer return')
                .tap();
            await expect(returnDialog).toHaveCount(0);
            const damagedReturn = returns
                .getByRole('article')
                .filter({ hasText: 'Damaged accepted by admin' });
            await expect(damagedReturn).toContainText('Accepted');
            await expect(damagedReturn).toContainText(
                /Refundable PHP\s*10\.01/,
            );

            await returns
                .getByRole('article')
                .filter({ hasText: 'Unopened customer return' })
                .getByRole('button', /^Record refund for return #\d+$/)
                .tap();
            const refundDialog = screen.getByRole('dialog', 'Record refund');
            await refundDialog.getByLabel('Refund amount (PHP)').fill('20.02');
            await refundDialog
                .getByLabel('Refund status')
                .selectOption({ label: 'Pending' });
            await refundDialog
                .getByLabel('Refund reference (optional)')
                .fill(reference);
            await refundDialog.getByRole('button', 'Record refund').tap();
            await expect(refundDialog).toHaveCount(0);
            const refunds = screen.getByRole('region', 'Refund tracking');
            await expect(refunds).toContainText('pending');
            await expect(refunds).toContainText(reference);

            await screen.getByRole('button', /^Update refund #\d+$/).tap();
            const updateDialog = screen.getByRole(
                'dialog',
                'Update refund record',
            );
            await updateDialog
                .getByLabel('Refund status')
                .selectOption({ label: 'Refunded (tracking only)' });
            await updateDialog
                .getByRole('button', 'Mark refund as refunded')
                .tap();
            await expect(updateDialog).toHaveCount(0);
            await expect(refunds.getByRole('article')).toContainText(
                'refunded',
            );
            await expect(
                refunds.getByRole('button', /^Update refund #/),
            ).toHaveCount(0);

            await damagedReturn
                .getByRole('button', /^Record refund for return #\d+$/)
                .tap();
            await expect(refundDialog.getByLabel('Refund status')).toHaveValue(
                'pending',
            );
            await expect(
                refundDialog.getByLabel('Refund reference (optional)'),
            ).toHaveValue('');
            await expect(
                refundDialog.getByLabel('Refund amount (PHP)'),
            ).toHaveValue('10.01');
            await refundDialog.getByRole('button', 'Record refund').tap();
            await expect(refundDialog).toHaveCount(0);
            const pendingRefund = refunds
                .getByRole('article')
                .filter({ hasText: 'pending' });
            await expect(pendingRefund).toContainText(/PHP\s*10\.01/);
            await expect(pendingRefund).toContainText(
                'Reference: Not provided',
            );

            await app.open('/e2e-test-user/inventories');
            await searchSalesInventory(screen, browser, sku);
            await expect(
                screen
                    .getByRole('row')
                    .filter({ hasText: sku })
                    .getByRole('cell')
                    .nth(3),
            ).toContainText(/^7\s*\/ min 0$/);
            await screen
                .getByRole('button', `More actions for ${itemName}`)
                .tap();
            await screen.getByRole('menuitem', 'Delete item').tap();
            const deleteDialog = screen.getByRole(
                'dialog',
                'Delete inventory item',
            );
            await deleteDialog.getByRole('button', 'Delete item').tap();
            await expect(deleteDialog.getByRole('alert')).toContainText(
                'Move all remaining stock out before deleting this item.',
            );
            await deleteDialog.getByRole('button', 'Cancel').tap();
            await expect(
                screen.getByRole('row').filter({ hasText: sku }),
            ).toBeVisible();
        },
    );

    test(
        'cancellation releases reserved stock for another customer order',
        { session: 'sales-user', timeout: 180_000 },
        async ({ app, screen, browser }) => {
            const suffix = `${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
            const sku = `E2E-CANCEL-${suffix}`.toUpperCase();
            const itemName = `E2E Cancellation Item ${suffix}`;
            await app.open('/e2e-test-user/inventories');
            await createSalesInventory(screen, browser, itemName, sku, 5);

            for (const customer of [
                `First buyer ${suffix}`,
                `Second buyer ${suffix}`,
            ]) {
                await app.open('/e2e-test-user/sales-orders');
                await createSalesDraft(screen, customer, itemName, sku, 5);
                await reserveSalesStock(screen, itemName, 5);
                await screen
                    .getByRole('button', 'Cancel unfulfilled remainder')
                    .tap();
                const dialog = screen.getByRole(
                    'dialog',
                    'Cancel unfulfilled remainder',
                );
                await dialog
                    .getByLabel('Cancellation reason')
                    .fill(`Cancelled ${customer}`);
                await dialog
                    .getByRole('button', 'Cancel unfulfilled remainder')
                    .tap();
                await expect(dialog).toHaveCount(0);
                await expect(
                    browser
                        .locator('header')
                        .getByText('Cancelled', { exact: true }),
                ).toBeVisible();
                await expect(
                    screen.getByRole('region', 'Stock reservations'),
                ).toContainText('0 remaining of 5 reserved');
                await expect(
                    screen.getByRole('region', 'Customer snapshot'),
                ).toContainText(`Cancelled ${customer}`);
                await expect(
                    screen.getByRole('button', /^Fulfill reservation #/),
                ).toHaveCount(0);
            }

            await app.open('/e2e-test-user/inventories');
            await searchSalesInventory(screen, browser, sku);
            await expect(
                screen
                    .getByRole('row')
                    .filter({ hasText: sku })
                    .getByRole('cell')
                    .nth(3),
            ).toContainText(/^5\s*\/ min 0$/);
        },
    );
});

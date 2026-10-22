import { beforeEach, describe, test } from '@e2e-dev/web';
import { credentials, expect, type Screen } from 'e2e';

test.setup(
    'sign in as the inventory tester',
    { sessions: ['inventory-user'] },
    async ({ app, screen, browser, session }) => {
        const inventoryUser = credentials.user('e2e');

        await app.open('/login');
        await screen.getByLabel('Email address').fill(inventoryUser.username);
        await screen.getByLabel('Password').fill(inventoryUser.password);
        await screen.getByRole('button', 'Log in').tap();
        await expect(browser).toHaveURL(/\/dashboard$/);
        await session.save('inventory-user');
    },
);

async function createInventoryItem(
    screen: Screen,
    sku: string,
    name: string,
    quantity = 8,
): Promise<void> {
    await screen.getByRole('button', 'Add item').tap();
    await screen.getByLabel('Product name').fill(name);
    await screen.getByLabel('SKU').fill(sku);
    await screen.getByLabel('Category').fill('Testing');
    await screen.getByLabel('Location').fill('QA warehouse');
    await screen.getByLabel('Available units').fill(String(quantity));
    await screen.getByLabel('Reorder point').fill('4');
    await screen.getByLabel('Unit cost').fill('1.25');
    await screen.getByRole('button', 'Add item').last().tap();

    await expect(screen.getByText(name)).toBeVisible();
}

describe('inventory module', () => {
    beforeEach(async ({ app, screen }) => {
        await app.open('/');
        await screen.getByRole('link', 'Dashboard').tap();
        await screen.getByRole('link', 'Inventory').tap();
        await expect(screen.getByRole('heading', 'Inventory')).toBeVisible();
    });

    test(
        'creates an inventory item',
        { session: 'inventory-user' },
        async ({ screen }) => {
            const sku = `E2E-${Date.now()}`;
            const productName = `E2E Product ${sku}`;

            await createInventoryItem(
                screen,
                sku.toLowerCase(),
                productName,
                12,
            );

            await expect(screen.getByText(sku.toUpperCase())).toBeVisible();
        },
    );

    test(
        'updates stock for an inventory item',
        { session: 'inventory-user' },
        async ({ screen }) => {
            const sku = `E2E-UPDATE-${Date.now()}`;
            const itemName = `E2E Stock Adjustment Item ${sku}`;

            await createInventoryItem(screen, sku, itemName);
            const itemRow = screen.getByRole('row').filter({ hasText: sku });

            await screen
                .getByRole('button', `Receive stock for ${itemName}`)
                .tap();
            await screen.getByLabel('Units to receive').fill('5');
            await screen.getByRole('button', 'Confirm adjustment').tap();

            await expect(
                itemRow.getByText('13', { exact: true }),
            ).toBeVisible();
        },
    );

    test(
        'uploads a product image',
        { session: 'inventory-user' },
        async ({ screen }) => {
            const sku = `E2E-IMAGE-${Date.now()}`;
            const itemName = `E2E Image Upload Item ${sku}`;

            await createInventoryItem(screen, sku, itemName);
            await screen
                .getByRole('button', `Manage product images for ${itemName}`)
                .tap();
            await screen
                .getByLabel(/Choose product photos/)
                .setInputFiles('tests/e2e/fixtures/product-image-one.png');

            await expect(
                screen.getByRole('img', 'product-image-one.png'),
            ).toBeVisible();
            await screen.getByRole('button', 'Save images').tap();
            await expect(
                screen.getByRole('img', `${itemName} main product image`),
            ).toBeVisible();
        },
    );

    test(
        'removes a product image',
        { session: 'inventory-user' },
        async ({ screen }) => {
            const sku = `E2E-REMOVE-${Date.now()}`;
            const itemName = `E2E Image Removal Item ${sku}`;

            await createInventoryItem(screen, sku, itemName);
            await screen
                .getByRole('button', `Manage product images for ${itemName}`)
                .tap();
            await screen
                .getByLabel(/Choose product photos/)
                .setInputFiles('tests/e2e/fixtures/product-image-one.png');
            await screen
                .getByRole('button', 'Remove product-image-one.png')
                .tap();
            await expect(
                screen.getByText('No product photos yet'),
            ).toBeVisible();
            await screen.getByRole('button', 'Save images').tap();

            await screen
                .getByRole('button', `Manage product images for ${itemName}`)
                .tap();
            await expect(
                screen.getByText('No product photos yet'),
            ).toBeVisible();
        },
    );

    test(
        'makes a product image the main image',
        { session: 'inventory-user' },
        async ({ screen }) => {
            const sku = `E2E-MAIN-${Date.now()}`;
            const itemName = `E2E Main Image Item ${sku}`;

            await createInventoryItem(screen, sku, itemName);
            await screen
                .getByRole('button', `Manage product images for ${itemName}`)
                .tap();
            await screen
                .getByLabel(/Choose product photos/)
                .setInputFiles([
                    'tests/e2e/fixtures/product-image-one.png',
                    'tests/e2e/fixtures/product-image-two.png',
                ]);
            await screen.getByRole('button', 'Set as main').tap();
            await expect(screen.getByRole('button', 'Main image')).toHaveCount(
                1,
            );
            await expect(
                screen.getByRole('button', 'Main image'),
            ).toHaveAttribute('aria-pressed', 'true');
            await screen.getByRole('button', 'Save images').tap();

            await expect(
                screen.getByRole('img', `${itemName} main product image`),
            ).toBeVisible();
        },
    );

    test(
        'edits an inventory item',
        { session: 'inventory-user' },
        async ({ screen }) => {
            const sku = `E2E-EDIT-${Date.now()}`;
            const itemName = `E2E Edit Item ${sku}`;
            const updatedName = `${itemName} Updated`;

            await createInventoryItem(screen, sku, itemName);
            await screen.getByRole('button', `Edit ${itemName}`).tap();
            await screen.getByLabel('Product name').fill(updatedName);
            await screen.getByLabel('Unit cost').fill('2.50');
            await screen.getByRole('button', 'Save changes').tap();

            const itemRow = screen.getByRole('row').filter({ hasText: sku });

            await expect(itemRow.getByText(updatedName)).toBeVisible();
        },
    );

    test(
        'deletes an inventory item',
        { session: 'inventory-user' },
        async ({ screen }) => {
            const sku = `E2E-DELETE-${Date.now()}`;
            const itemName = `E2E Delete Item ${sku}`;

            await createInventoryItem(screen, sku, itemName);
            await screen.getByRole('button', `Delete ${itemName}`).tap();
            await screen.getByRole('button', 'Delete item').tap();

            await expect(screen.getByText(itemName)).toHaveCount(0);
        },
    );

    test(
        'shows recent inventory activity',
        { session: 'inventory-user' },
        async ({ screen }) => {
            const sku = `E2E-HISTORY-${Date.now()}`;
            const itemName = `E2E History Item ${sku}`;

            await createInventoryItem(screen, sku, itemName);
            await screen
                .getByRole('button', `Receive stock for ${itemName}`)
                .tap();
            await screen.getByLabel('Units to receive').fill('3');
            await screen.getByRole('button', 'Confirm adjustment').tap();
            await screen.getByRole('button', 'History').tap();

            const history = screen.getByRole('dialog');

            await expect(
                history.getByText('Inventory Created').first(),
            ).toBeVisible();
            await expect(
                history.getByText('Inventory Stock Adjusted').first(),
            ).toBeVisible();
            await expect(
                history.getByText(itemName, { exact: false }).first(),
            ).toBeVisible();
        },
    );
});

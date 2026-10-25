import { test } from '@e2e-dev/web';
import { credentials, expect } from 'e2e';

test('links sales while keeping planned modules unlinked in the sidebar', async ({
    app,
    screen,
    browser,
}) => {
    const user = credentials.user('e2e');

    await app.open('/login');
    await screen.getByLabel('Email address').fill(user.username);
    await screen.getByLabel('Password').fill(user.password);
    await screen.getByRole('button', 'Log in').tap();

    await expect(browser).toHaveURL(/\/e2e-test-user\/dashboard$/);

    await expect(screen.getByText('Modules', { exact: true })).toHaveCount(0);

    for (const moduleName of [
        'Purchasing',
        'Sales',
        'Finance',
        'Reports & Operations',
    ]) {
        await expect(
            screen.getByText(moduleName, { exact: true }),
        ).toBeVisible();
    }

    await expect(screen.getByRole('link', 'Inventory')).toBeVisible();
    await expect(screen.getByRole('link', 'Sales')).toHaveAttribute(
        'href',
        '/e2e-test-user/sales-orders',
    );

    for (const plannedModule of [
        'Purchasing',
        'Finance',
        'Reports & Operations',
    ]) {
        await expect(screen.getByRole('link', plannedModule)).toHaveCount(0);
    }
});

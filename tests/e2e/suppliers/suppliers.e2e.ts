import { beforeEach, describe, test } from '@e2e-dev/web';
import type { Browser } from '@e2e-dev/web';
import { credentials, expect } from 'e2e';

const cursorSpotlightScript = String.raw`
    (() => {
        const cursorId = 'e2e-cursor-spotlight';
        const style = document.createElement('style');

        style.textContent = '#e2e-cursor-spotlight{position:fixed;top:0;left:0;z-index:2147483647;width:30px;height:30px;border:2px solid rgb(255 72 32 / 95%);border-radius:9999px;background:rgb(255 72 32 / 18%);box-shadow:0 0 0 4px rgb(255 72 32 / 18%),0 2px 8px rgb(0 0 0 / 30%);pointer-events:none;opacity:0;transform:translate(-50%,-50%);transition:opacity 120ms ease,transform 90ms ease;will-change:left,top,transform}#e2e-cursor-spotlight::after{position:absolute;top:50%;left:50%;width:5px;height:5px;border-radius:9999px;background:#fff;box-shadow:0 0 2px #111;content:"";transform:translate(-50%,-50%)}#e2e-cursor-spotlight.visible{opacity:1}#e2e-cursor-spotlight.pressed{transform:translate(-50%,-50%) scale(.78)}@media(prefers-reduced-motion:reduce){#e2e-cursor-spotlight{transition:none}}';

        const getCursor = () => {
            if (!document.body) {
                return null;
            }

            if (!style.isConnected) {
                document.head?.appendChild(style);
            }

            let cursor = document.getElementById(cursorId);

            if (!cursor) {
                cursor = document.createElement('div');
                cursor.id = cursorId;
                document.body.appendChild(cursor);
            }

            return cursor;
        };

        window.addEventListener('pointermove', (event) => {
            const cursor = getCursor();

            if (!cursor) {
                return;
            }

            cursor.style.left = event.clientX + 'px';
            cursor.style.top = event.clientY + 'px';
            cursor.classList.add('visible');
        }, true);

        window.addEventListener('pointerdown', () => {
            getCursor()?.classList.add('pressed');
        }, true);

        window.addEventListener('pointerup', () => {
            getCursor()?.classList.remove('pressed');
        }, true);

        window.addEventListener('pointerleave', () => {
            getCursor()?.classList.remove('visible', 'pressed');
        }, true);
    })();
`;

async function installCursorSpotlight(browser: Browser): Promise<void> {
    await browser.addInitScript(cursorSpotlightScript);
}

test.setup(
    'sign in as the supplier tester',
    { sessions: ['supplier-user'] },
    async ({ app, screen, browser, session }) => {
        const supplierUser = credentials.user('e2e');

        await installCursorSpotlight(browser);
        await app.open('/login');
        await screen.getByLabel('Email address').fill(supplierUser.username);
        await screen.getByLabel('Password').fill(supplierUser.password);
        await screen.getByRole('button', 'Log in').tap();
        await expect(browser).toHaveURL(/\/e2e-test-user\/dashboard$/);
        await session.save('supplier-user');
    },
);

describe('supplier management', () => {
    beforeEach(async ({ app, browser }) => {
        await installCursorSpotlight(browser);
        await app.open('/e2e-test-user/suppliers');
    });

    test(
        'creates, edits, and archives a supplier',
        { session: 'supplier-user' },
        async ({ screen, browser }) => {
            const suffix = Date.now();
            const supplierName = `E2E Supplier ${suffix}`;
            const updatedSupplierName = `${supplierName} Updated`;
            const contactEmail = `supplier-${suffix}@example.test`;

            const addSupplierButtons = screen.getByRole(
                'button',
                'Add supplier',
            );

            await expect(addSupplierButtons).toHaveCount(2);
            await addSupplierButtons.last().tap();
            await expect(
                browser.locator('#e2e-cursor-spotlight'),
            ).toBeVisible();
            await screen.getByLabel('Supplier name').fill(supplierName);
            await screen.getByLabel('Contact name').fill('E2E Contact');
            await screen.getByLabel('Email').fill(contactEmail);
            await screen.getByLabel('Phone').fill('+1 555 0100');
            await screen.getByLabel('Billing address').fill('100 Test Street');
            await screen.getByRole('button', 'Save supplier').tap();

            await expect(
                screen.getByRole('heading', supplierName),
            ).toBeVisible();
            await expect(screen.getByRole('link', contactEmail)).toBeVisible();

            await screen.getByRole('button', 'Add supplier').tap();
            await expect(
                screen.getByRole('dialog', 'Add supplier'),
            ).toBeVisible();
            await screen.getByRole('button', 'Cancel').tap();
            await expect(
                screen.getByRole('dialog', 'Add supplier'),
            ).toHaveCount(0);

            const supplierRow = screen
                .getByRole('listitem')
                .filter({ hasText: supplierName });

            await supplierRow.getByRole('button', 'Edit').tap();
            await screen.getByLabel('Supplier name').fill(updatedSupplierName);
            await screen.getByRole('button', 'Save supplier').tap();

            await expect(
                screen.getByRole('heading', updatedSupplierName),
            ).toBeVisible();
            await expect(screen.getByRole('link', contactEmail)).toBeVisible();

            await browser.onDialog('accept');
            await screen
                .getByRole('listitem')
                .filter({ hasText: updatedSupplierName })
                .getByRole('button', 'Archive')
                .tap();

            await expect(
                screen.getByRole('heading', updatedSupplierName),
            ).toHaveCount(0);
        },
    );
});

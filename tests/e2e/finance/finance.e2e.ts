import { test } from "@e2e-dev/web";
import { credentials, expect } from "e2e";

test.setup(
    "sign in as the finance tester",
    { sessions: ["finance-user"] },
    async ({ app, screen, browser, session }) => {
        const user = credentials.user("e2e");
        await app.open("/login");
        await screen.getByLabel("Email address").fill(user.username);
        await screen.getByLabel("Password").fill(user.password);
        await screen.getByRole("button", "Log in").tap();
        await expect(browser).toHaveURL(/\/e2e-test-user\/dashboard$/);
        await session.save("finance-user");
    },
);

test(
    "records income and expenses and updates the overview totals",
    { session: "finance-user" },
    async ({ app, screen, browser }) => {
        const suffix = Date.now();
        const incomeDescription = `E2E income ${suffix}`;
        const expenseDescription = `E2E expense ${suffix}`;

        await app.open("/e2e-test-user/finance");
        await expect(
            screen.getByRole("heading", "Finance", { level: 1 }),
        ).toBeVisible();
        await expect(screen.getByRole("link", "Finance").first()).toBeVisible();

        await screen.getByRole("button", "Record transaction").tap();
        await screen.getByLabel("Amount").fill("125.50");
        await screen
            .getByLabel("Date")
            .fill(new Date().toISOString().slice(0, 10));
        await screen
            .getByLabel("Category")
            .last()
            .selectOption({ label: "Sales" });
        await screen
            .getByLabel("Payment method")
            .selectOption({ label: "Bank" });
        await screen.getByLabel("Description").fill(incomeDescription);
        await screen.getByRole("button", "Save").tap();

        await expect(screen.getByText(incomeDescription)).toBeVisible();
        await expect(
            screen.getByRole("article").filter({ hasText: "Income" }),
        ).toContainText("125.50");
        await expect(browser).toHaveURL(/\/e2e-test-user\/finance/);

        await screen.getByRole("button", "Record transaction").tap();
        await screen
            .getByLabel("Type")
            .last()
            .selectOption({ label: "Expense" });
        await screen.getByLabel("Amount").fill("30.25");
        await screen
            .getByLabel("Date")
            .fill(new Date().toISOString().slice(0, 10));
        await screen
            .getByLabel("Category")
            .last()
            .selectOption({ label: "Supplies" });
        await screen
            .getByLabel("Payment method")
            .selectOption({ label: "Cash" });
        await screen.getByLabel("Description").fill(expenseDescription);
        await screen.getByRole("button", "Save").tap();

        await expect(screen.getByText(expenseDescription)).toBeVisible();
        await expect(
            screen.getByRole("article").filter({ hasText: "Expenses" }),
        ).toContainText("30.25");
        await expect(
            screen.getByRole("article").filter({ hasText: "Net" }),
        ).toContainText("95.25");
    },
);

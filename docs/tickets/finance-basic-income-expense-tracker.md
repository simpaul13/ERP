# Ticket: Add a Basic Finance Tracker

**Type:** Feature  
**Priority:** Medium  
**Status:** Proposed

## Summary

Add a small-business Finance module for manually recording and reviewing income and expenses. The first version is a simple cash tracker, not a full accounting system.

## User need

As a business owner or team member, I want to record money received and money spent, so I can see a simple summary of the business's income and expenses over time.

## Scope

### Finance overview

- Show total income, total expenses, and net amount (income minus expenses) for the selected month.
- Show a short list of recent transactions.
- Allow the user to select a month/date range; totals and transaction list reflect that selection.

### Transactions

- Show a searchable, filterable list of transactions with date, description, category, income, expense, and payment method.
- Allow authorized team members to create, edit, and delete a transaction.
- Each transaction has one type: income or expense.
- Each transaction records amount, transaction date, description, category, and payment method.
- Support cash and bank as payment methods in the initial version.
- Validate that the amount is greater than zero and required fields are provided.
- Keep transaction data scoped to the user's current team.

### Categories

- Provide basic categories for income and expenses.
- Allow authorized team members to add, rename, and deactivate categories.
- Prevent a category from being selected for the wrong transaction type.
- Seed practical initial categories, such as Sales and Other Income; Supplies, Rent, Transport, Utilities, and Other Expense.

## Acceptance criteria

1. A team member can open Finance from the application navigation.
2. A team member can record an income transaction and see it in the transaction list and overview totals.
3. A team member can record an expense transaction and see it in the transaction list and overview totals.
4. Editing or deleting a transaction updates the overview totals.
5. Selecting a different month/date range updates the totals and list accordingly.
6. A user cannot view or modify another team's transactions or categories.
7. Invalid transactions (including zero or negative amounts and missing required fields) are rejected with a clear validation message.
8. The transaction list can be filtered by type, category, and date range.

## Out of scope for this ticket

- General ledger, journal entries, trial balance, or formal financial statements.
- Customer invoicing, supplier bills, accounts receivable/payable, or payment processing.
- Automatic income creation from Sales orders or automatic expenses from Purchase orders.
- Tax calculations/filing, payroll, budgets, bank feeds, reconciliation, and multi-currency support.

## Implementation note

For this MVP, transactions are entered manually. Do not treat a sales order, purchase order, goods receipt, or refund record as proof that money was actually received or paid. Any automatic integration with Sales or Purchasing should be handled as a separate follow-up ticket after the business confirms the desired workflow.

## Open business decisions

- Confirm the default currency and whether users need to choose a currency per transaction.
- Confirm who can create, edit, and delete Finance transactions.
- Confirm whether deleting a transaction is acceptable for the initial release or whether it should be voided instead.

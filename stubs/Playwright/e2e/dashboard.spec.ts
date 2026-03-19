/**
 * Example E2E test for the dashboard (auth + verified user).
 *
 * Assumes you are using the official Laravel starter kit (Vue/Inertia).
 *
 * Copyright (c) 2026 Andrei Valcu
 */
import { test, expect } from '@playwright/test'

test('Dashboard shows for an authenticated user', async ({ page }) => {
    await page.goto('/dashboard')

    await expect(page).toHaveURL(/\/dashboard/)
    await expect(
        page.getByRole('link', { name: 'Dashboard', exact: true }).first(),
    ).toBeVisible()
})

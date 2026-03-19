/**
 * Example E2E test for appearance / theme preferences.
 *
 * Assumes you are using the official Laravel starter kit (Vue/Inertia).
 *
 * Copyright (c) 2026 Andrei Valcu
 */
import { test, expect } from '@playwright/test'

test('Appearance can switch to dark mode', async ({ page }) => {
    await page.goto('/settings/appearance')

    await page.getByRole('button', { name: 'Dark' }).click()

    await expect
        .poll(async () =>
            page.evaluate(() => localStorage.getItem('appearance')),
        )
        .toBe('dark')

    await expect(page.locator('html')).toHaveClass(/dark/)
})

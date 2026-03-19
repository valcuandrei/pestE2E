/**
 * Example E2E test for updating password on the security settings page.
 *
 * Assumes you are using the official Laravel starter kit (Vue/Inertia).
 * Pass currentPassword, newPassword, and newPasswordConfirmation from Pest via withParams.
 * If Fortify uses confirmPassword for 2FA, the confirm-password screen is handled first.
 *
 * Copyright (c) 2026 Andrei Valcu
 */
import { test, expect } from '@playwright/test'
import type { Page } from '@playwright/test'
import { readParams } from '../pest-e2e/core.mjs'

/**
 * Fortify may require password confirmation before the security settings page
 * when two-factor authentication uses confirmPassword.
 */
async function confirmAccountPasswordIfNeeded(
    page: Page,
    accountPassword: string,
): Promise<void> {
    const confirmBtn = page.getByTestId('confirm-password-button')

    if (!(await confirmBtn.isVisible())) {
        return
    }

    await page.locator('#password').fill(String(accountPassword))
    await confirmBtn.click()

    await expect(page.locator('#current_password')).toBeVisible({
        timeout: 15_000,
    })
}

test('SecurityPassword form updates password', async ({ page }) => {
    const {
        currentPassword,
        newPassword,
        newPasswordConfirmation,
    } = await readParams()

    await page.goto('/settings/security')

    await confirmAccountPasswordIfNeeded(page, currentPassword)

    await page.locator('#current_password').fill(String(currentPassword))
    await page.locator('#password').fill(String(newPassword))
    await page
        .locator('#password_confirmation')
        .fill(String(newPasswordConfirmation))

    await page.getByTestId('update-password-button').click()

    await expect(page.getByText('Saved.')).toBeVisible({
        timeout: 15_000,
    })
})

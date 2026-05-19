/**
 * Example E2E test for the user profile page.
 *
 * Assumes you are using the official Laravel starter kit (Vue/Inertia).
 * It should also work for the React or Livewire starter kits — if not, adjust the URL and selectors.
 *
 * Copyright (c) 2026 Andrei Valcu
 */
import { test, expect } from '@playwright/test';
import { readParams } from '../pest-e2e/core.mjs';

test('UserProfile can update their profile', async ({ page }) => {
    // Requires an authenticated session (handled by Pest E2E harness)
    const { name, email } = await readParams();
    await page.goto('/settings/profile');

    await page.locator('#name').fill(name);
    await page.locator('#email').fill(email);
    await page.getByTestId('update-profile-button').click();

    await expect(page.getByText('Profile updated.')).toBeVisible();
});

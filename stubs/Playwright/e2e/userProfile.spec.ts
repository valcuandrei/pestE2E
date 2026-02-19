/**
 * Example E2E test for the user profile page.
 *
 * Assumes you are using the official Laravel starter kit (Vue/Inertia).
 * It should also work for the React or Livewire starter kits — if not, adjust the URL and selectors.
 *
 * Copyright (c) 2026 Andrei Valcu
 */
import { test, expect } from '@playwright/test';

test('UserProfile can update their profile', async ({ page }) => {
    // Requires an authenticated session (handled by Pest E2E harness)
    await page.goto('/settings/profile');

    await page.locator('#name').fill('Test User');
    await page.locator('#email').fill('test@example.com');
    await page.getByTestId('update-profile-button').click();

    await expect(page.getByText('Saved.')).toBeVisible();
});

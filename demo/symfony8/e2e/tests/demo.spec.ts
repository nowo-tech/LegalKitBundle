import { test, expect, type Page } from '@playwright/test';

/**
 * REQ-DEMO-013 — Legal Kit demo e2e: footer links on every public page, seed pages per locale, the admin matrix,
 * editing a page in CKEditor 5, the placeholder banner and restoring the built-in text through the confirm dialog.
 */
async function signIn(page: Page): Promise<void> {
  await page.goto('/login');
  await page.getByLabel('Username').fill('admin');
  await page.getByLabel('Password').fill('admin');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByTestId('demo-navbar')).toContainText('admin');
  await page.evaluate(() => document.querySelectorAll('.sf-toolbar, .sf-minitoolbar').forEach((el) => el.remove()));
}

async function restoreIfCustom(page: Page, slug: string, locale: string): Promise<void> {
  await page.goto(`/admin/legal/${slug}/${locale}`);
  const restore = page.locator('[data-nowo-ui-region="danger-zone"] button').first();
  if ((await restore.count()) === 0) {
    return;
  }
  await restore.click();
  await page.locator('dialog[open] button[type="submit"]').click();
  await expect(page.getByText('The built-in text is published again')).toBeVisible();
}

test.describe('legal kit demo', () => {
  test('every public page has the footer legal navigation', async ({ page }) => {
    const response = await page.goto('/');
    expect(response?.ok()).toBeTruthy();
    const footer = page.getByTestId('demo-footer');
    for (const slug of ['notice', 'privacy', 'terms', 'cookies', 'manage-cookies']) {
      await expect(footer.locator(`[data-nowo-legal-link="${slug}"]`)).toHaveCount(1);
    }
    await footer.locator('[data-nowo-legal-link="privacy"]').click();
    await expect(page).toHaveURL(/\/legal\/privacy$/);
    await expect(page.locator('article h1')).toHaveText('Privacy policy');
    await expect(page.locator('article')).toContainText('Acme Cloud');
    await expect(page.locator('article nav a')).toHaveCount(3);
  });

  test('seed pages in other locales and the default-locale redirect', async ({ page }) => {
    await page.goto('/es/legal/notice');
    await expect(page.locator('article h1')).toHaveText('Aviso legal');
    await expect(page.locator('article')).toHaveAttribute('lang', 'es');
    await page.goto('/de/legal/cookies');
    await expect(page.locator('article h1')).toHaveText('Cookie-Hinweis');
    await expect(page.locator('.nowo-legal-cookie-table')).toContainText('PHPSESSID');
    const redirected = await page.goto('/en/legal/terms');
    expect(redirected?.url()).toMatch(/\/legal\/terms$/);
  });

  test('admin edits a page in CKEditor and restores the built-in text', async ({ page }) => {
    await signIn(page);
    await restoreIfCustom(page, 'notice', 'en');

    await page.goto('/admin/legal');
    await expect(page.getByTestId('nowo-legal-kit-edit-notice-en')).toContainText('Built-in');

    await page.goto('/ops');
    const banner = page.getByTestId('nowo-legal-kit-placeholder-banner');
    await expect(banner).toBeVisible();
    const before = await banner.locator('strong').innerText();

    await page.goto('/admin/legal/notice/en');
    await expect(page.locator('.nowo-ui-tabs')).toContainText('es');
    const editor = page.locator('.ck-editor__editable');
    await expect(editor).toBeVisible();
    await editor.fill('Acme Cloud Ltd — 1 Main Street, Dublin. Contact: legal@acme.test');
    await page.getByRole('button', { name: 'Save legal page' }).click();
    await expect(page.getByText('Legal page saved for this locale.')).toBeVisible();

    await page.goto('/legal/notice');
    await expect(page.locator('article')).toContainText('Acme Cloud Ltd');
    await page.goto('/admin/legal');
    await expect(page.getByTestId('nowo-legal-kit-edit-notice-en')).toContainText('Custom');
    await page.goto('/ops');
    await expect(page.getByTestId('nowo-legal-kit-placeholder-banner').locator('strong')).not.toHaveText(before);

    await restoreIfCustom(page, 'notice', 'en');
    await page.goto('/legal/notice');
    await expect(page.locator('article')).toContainText('Operator legal name');
  });
});

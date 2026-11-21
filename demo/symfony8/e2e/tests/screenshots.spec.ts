import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * REQ-DEMO-013 — README screenshots in the demo platform shell: a public legal page, the admin matrix and the editor.
 */
const outDir = process.env.SCREENSHOT_DIR
  ? resolve(process.env.SCREENSHOT_DIR)
  : resolve(__dirname, '../../../../docs/images/demo');

/** README screenshots never show the Symfony Web Debug Toolbar. */
const HIDE_TOOLBAR = '.sf-toolbar, .sf-minitoolbar, [id^="sfwdt"] { display: none !important; }';

test.beforeAll(() => {
  mkdirSync(outDir, { recursive: true });
});

test.use({ viewport: { width: 1280, height: 800 } });

test.describe('feature screenshots', () => {
  test('public page', async ({ page }) => {
    await page.goto('/legal/privacy');
    await expect(page.locator('article h1')).toBeVisible();
    await page.addStyleTag({ content: HIDE_TOOLBAR });
    await page.screenshot({ path: resolve(outDir, 'public-page.png') });
  });

  test('admin matrix and editor', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Username').fill('admin');
    await page.getByLabel('Password').fill('admin');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.goto('/admin/legal');
    await expect(page.getByTestId('nowo-legal-kit-admin-index')).toBeVisible();
    await page.addStyleTag({ content: HIDE_TOOLBAR });
    await page.screenshot({ path: resolve(outDir, 'admin-index.png') });

    await page.goto('/admin/legal/privacy/es');
    await expect(page.locator('.ck-editor__editable')).toBeVisible();
    await page.waitForTimeout(300);
    await page.addStyleTag({ content: HIDE_TOOLBAR });
    await page.screenshot({ path: resolve(outDir, 'admin-edit.png') });
  });
});

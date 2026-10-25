import assert from 'node:assert/strict'
import { log } from 'node:console'
import { URL } from 'node:url'
import { argv } from 'node:process'
import { cp, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { resolve } from 'node:path'
import { chromium } from '@playwright/test'

const source = resolve(argv[2] ?? 'apps/extension/dist')
const workspace = await mkdtemp(`${tmpdir()}/conwix-extension-storage-`)
const extension = resolve(workspace, 'extension')
await cp(source, extension, { recursive: true })
const manifest = JSON.parse(
  await readFile(resolve(extension, 'manifest.json'), 'utf8'),
)
const profile = resolve(workspace, 'profile')
let context

async function launch() {
  const browser = await chromium.launchPersistentContext(profile, {
    channel: 'chromium',
    headless: true,
    args: [
      `--disable-extensions-except=${extension}`,
      `--load-extension=${extension}`,
    ],
  })
  // Все страницы локальные фикстуры; расширение не обращается к production API.
  await browser.route('**/*', (route) => {
    if (route.request().url() === 'https://app.conwix.com/') {
      return route.fulfill({
        contentType: 'text/html',
        body: '<title>Local extension fixture</title>',
      })
    }
    return route.abort()
  })
  return browser
}

async function assertContentScriptCannotRead(browser, extensionId) {
  const page = await browser.newPage()
  const cdp = await browser.newCDPSession(page)
  const worlds = []
  cdp.on('Runtime.executionContextCreated', ({ context: world }) =>
    worlds.push(world),
  )
  await cdp.send('Runtime.enable')
  await page.goto('https://app.conwix.com/')
  // document_start content script is part of the real built manifest.
  let contentWorld
  for (const world of worlds) {
    const probe = await cdp
      .send('Runtime.evaluate', {
        expression: 'typeof chrome !== "undefined" && chrome.runtime?.id',
        contextId: world.id,
        returnByValue: true,
      })
      .catch(() => null)
    if (probe?.result.value === extensionId) contentWorld = world.id
  }
  assert.ok(contentWorld, 'The real extension content-script world must exist')
  const checked = await cdp.send('Runtime.evaluate', {
    expression:
      'Promise.resolve().then(() => globalThis.chrome.storage.local.get(["connection"])).then(value => ({ readable: "connection" in value }), () => ({ readable: false }))',
    contextId: contentWorld,
    awaitPromise: true,
    returnByValue: true,
  })
  assert.equal(
    checked.result.value?.readable,
    false,
    'Content script must not read connection',
  )
  await page.close()
}

try {
  let knownId
  for (const phase of ['first', 'restart', 'without-worker']) {
    if (phase === 'without-worker') {
      const changed = { ...manifest }
      Reflect.deleteProperty(changed, 'background')
      await writeFile(
        resolve(extension, 'manifest.json'),
        JSON.stringify(changed),
      )
    }
    context = await launch()
    const worker =
      phase === 'without-worker'
        ? null
        : (context.serviceWorkers()[0] ??
          (await context.waitForEvent('serviceworker')))
    const extensionId = worker ? new URL(worker.url()).host : knownId
    knownId = extensionId
    if (phase === 'first' && worker) {
      await worker.evaluate(() =>
        globalThis.chrome.storage.local.set({
          connection: {
            token: 'synthetic-browser-test-secret',
            companyId: 'ours',
            companyName: 'Fixture',
          },
        }),
      )
    }
    if (worker) {
      const persisted = await worker.evaluate(async () => {
        const value = await globalThis.chrome.storage.local.get(['connection'])
        return value.connection?.companyId
      })
      assert.equal(
        persisted,
        'ours',
        'Worker must retain connection across browser restarts',
      )
    }
    await assertContentScriptCannotRead(context, extensionId)
    const popup = await context.newPage()
    await popup.goto(
      `chrome-extension://${extensionId}/${manifest.action.default_popup}`,
    )
    const popupCanRead = await popup.evaluate(async () => {
      const value = await globalThis.chrome.storage.local.get(['connection'])
      return value.connection?.companyId === 'ours'
    })
    assert.equal(popupCanRead, true, 'Trusted popup must retain access')
    await context.close()
    context = undefined
  }
  log(
    'PASS: content script denied; trusted worker/popup allowed; connection and access restriction persist after restart, including without worker',
  )
} finally {
  await context?.close()
  await rm(workspace, { recursive: true, force: true })
}

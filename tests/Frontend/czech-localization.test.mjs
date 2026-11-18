import assert from 'node:assert/strict'
import { after, before, beforeEach, test } from 'node:test'
import { resolve } from 'node:path'
import { createServer as createHttpServer } from 'node:http'
import { createServer } from 'vite'

let server, createAppI18n, setI18nLanguage, notificationMessage, notificationTitle, handleApiError
before(async () => {
  server = await createServer({ configFile: false, server: { middlewareMode: true, hmr: { server: createHttpServer() } }, appType: 'custom', resolve: { alias: { '@': resolve('resources') } } })
  ;({ createAppI18n, setI18nLanguage } = await server.ssrLoadModule('/resources/scripts/plugins/i18n.ts'))
  ;({ notificationMessage, notificationTitle } = await server.ssrLoadModule('/resources/scripts/components/notifications/notification-text.ts'))
  ;({ handleApiError } = await server.ssrLoadModule('/resources/scripts/utils/error-handling.ts'))
  globalThis.window = {}
  globalThis.document = { documentElement: { lang: '', dir: '' } }
})
after(async () => { await server?.close() })
beforeEach(() => { delete window.app_locale })

test('Czech is available synchronously on fresh auth screens', () => {
  const i18n = createAppI18n()
  assert.equal(i18n.global.locale.value, 'cs')
  assert.equal(i18n.global.t('auth_pages.login.heading'), 'Vítejte zpět')
  assert.equal(i18n.global.t('auth_pages.register.heading'), 'Vytvoření účtu')
  assert.equal(i18n.global.t('auth_pages.forgot_password.heading'), 'Zapomenuté heslo')
  assert.equal(i18n.global.t('auth_pages.reset_password.heading'), 'Nastavení nového hesla')
})

test('an explicit install language and later user selections still work', async () => {
  window.app_locale = 'en'
  const i18n = createAppI18n()
  assert.equal(i18n.global.locale.value, 'en')
  assert.equal(i18n.global.t('auth_pages.login.heading'), 'Welcome back')
  await setI18nLanguage(i18n, 'cs')
  assert.equal(i18n.global.t('auth_pages.login.heading'), 'Vítejte zpět')
  assert.equal(document.documentElement.lang, 'cs')
  await setI18nLanguage(i18n, 'en')
  assert.equal(i18n.global.t('login.password'), 'Password')
})

test('toast titles and descriptions translate keys and legacy English messages', () => {
  const t = (key) => String(createAppI18n().global.t(key))
  assert.equal(notificationTitle({ type: 'success' }, t), 'Hotovo')
  assert.equal(notificationTitle({ type: 'error', title: 'Error' }, t), 'Chyba')
  assert.equal(notificationTitle({ type: 'error', title: 'notifications.error' }, t), 'Chyba')
  assert.equal(notificationMessage({ type: 'success', message: 'Logged out successfully.' }, t), t('general.logged_out_successfully'))
  assert.equal(notificationMessage({ type: 'success', message: 'Mail sent successfully' }, t), t('general.send_mail_successfully'))
  assert.equal(notificationMessage({ type: 'error', message: 'The provided credentials are incorrect.' }, t), t('errors.login_invalid_credentials'))
  assert.equal(notificationMessage({ type: 'error', message: handleApiError({}).message }, t), t('errors.network_error'))
  assert.equal(notificationMessage({ type: 'info', message: 'Moje vlastní zpráva' }, t), 'Moje vlastní zpráva')
})

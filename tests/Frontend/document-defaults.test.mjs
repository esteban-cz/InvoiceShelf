import assert from 'node:assert/strict'
import { after, before, beforeEach, test } from 'node:test'
import { resolve } from 'node:path'
import { readFileSync } from 'node:fs'
import { createServer as createHttpServer } from 'node:http'
import { createServer } from 'vite'
import { createPinia, setActivePinia } from 'pinia'

let server, useInvoiceStore, usePaymentStore, useCompanyStore, invoiceService, paymentService

before(async () => {
  const storage = new Map()
  globalThis.localStorage = {
    getItem: (key) => storage.get(key) ?? null,
    setItem: (key, value) => storage.set(key, String(value)),
    removeItem: (key) => storage.delete(key),
  }
  server = await createServer({
    configFile: false,
    // Attach Vite's websocket handlers to an unbound server; these tests need no ports.
    server: { middlewareMode: true, hmr: { server: createHttpServer() } },
    appType: 'custom',
    resolve: { alias: { '@': resolve('resources') } },
    define: {
      __INVOICESHELF_CLIENT__: 'false',
      __INVOICESHELF_CLIENT_VERSION__: JSON.stringify(readFileSync('version.md', 'utf8').trim()),
    },
  })
  ;({ useInvoiceStore } = await server.ssrLoadModule('/resources/scripts/features/company/invoices/store.ts'))
  ;({ usePaymentStore } = await server.ssrLoadModule('/resources/scripts/features/company/payments/store.ts'))
  ;({ useCompanyStore } = await server.ssrLoadModule('/resources/scripts/stores/company.store.ts'))
  ;({ invoiceService } = await server.ssrLoadModule('/resources/scripts/api/services/invoice.service.ts'))
  ;({ paymentService } = await server.ssrLoadModule('/resources/scripts/api/services/payment.service.ts'))
  invoiceService.getTemplates = async () => ({ invoiceTemplates: [{ name: 'invoice1' }] })
  invoiceService.getNextNumber = async () => ({ nextNumber: 'INV-1', nextSequenceNumber: 1 })
  paymentService.listMethods = async () => ({ data: [{ id: 7, name: 'Převod' }, { id: 9, name: 'Hotovost' }] })
  paymentService.getNextNumber = async () => ({ nextNumber: 'PAY-1' })
})

after(async () => { await server?.close() })
beforeEach(() => { setActivePinia(createPinia()) })

test('new invoices enable QR and signing and preload the default receiving account', async () => {
  const company = useCompanyStore()
  company.selectedCompanySettings = { invoice_default_receiving_iban: 'CZ8120100000002102589740' }
  const invoices = useInvoiceStore()
  await invoices.fetchInvoiceInitialSettings(false)
  assert.equal(invoices.newInvoice.invoice_number, 'INV-1')
  assert.equal(invoices.newInvoice.sign_document, true)
  assert.equal(invoices.newInvoice.payment_qr.iban, 'CZ8120100000002102589740')
})

test('editing preserves disabled QR and an invoice-specific issuer and signature', async () => {
  const invoices = useInvoiceStore()
  const signature = { issuer_name: 'Jiný vystavitel', image: 'data:image/png;base64,sample' }
  invoiceService.get = async () => ({ data: { id: 12, invoice_number: 'INV-EDIT', payment_qr: null, sign_document: false, signature, discount_type: 'fixed', discount: 0 } })
  await invoices.fetchInvoiceInitialSettings(true, { id: '12' })
  assert.equal(invoices.newInvoice.payment_qr, null)
  assert.equal(invoices.newInvoice.sign_document, false)
  assert.deepEqual(invoices.newInvoice.signature, signature)
})

test('new payments preselect the default while editing preserves the recorded method', async () => {
  useCompanyStore().selectedCompanySettings = { default_payment_method_id: '7' }
  const payments = usePaymentStore()
  await payments.fetchPaymentInitialData(false)
  assert.equal(payments.currentPayment.payment_number, 'PAY-1')
  assert.equal(payments.currentPayment.payment_method_id, 7)
  paymentService.get = async () => ({ data: { id: 12, payment_method_id: 9, allocations: [] } })
  await payments.fetchPaymentInitialData(true, { id: '12' })
  assert.equal(payments.currentPayment.payment_method_id, 9)
})

test('a removed or unset default does not choose a different payment method', async () => {
  for (const value of ['none', '999']) {
    useCompanyStore().selectedCompanySettings = { default_payment_method_id: value }
    const payments = usePaymentStore()
    payments.resetCurrentPayment()
    await payments.fetchPaymentInitialData(false)
    assert.equal(payments.currentPayment.payment_method_id, null)
  }
})

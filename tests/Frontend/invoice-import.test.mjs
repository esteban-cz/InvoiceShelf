import assert from 'node:assert/strict'
import { after, before, beforeEach, test } from 'node:test'
import { resolve } from 'node:path'
import { readFileSync } from 'node:fs'
import { createServer as createHttpServer } from 'node:http'
import { createServer } from 'vite'
import { createPinia, setActivePinia } from 'pinia'

let server, useInvoiceStore, usePaymentStore, useCompanyStore, invoiceService, paymentService, prefillImportedInvoice, importedNotes

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
  ;({ prefillImportedInvoice, importedNotes } = await server.ssrLoadModule('/resources/scripts/features/company/invoices/import.ts'))
  invoiceService.getTemplates = async () => ({ invoiceTemplates: [{ name: 'invoice1' }] })
  invoiceService.getNextNumber = async () => ({ nextNumber: 'INV-1', nextSequenceNumber: 1 })
  paymentService.listMethods = async () => ({ data: [{ id: 7, name: 'Převod' }, { id: 9, name: 'Hotovost' }] })
  paymentService.getNextNumber = async () => ({ nextNumber: 'PAY-1' })
})

after(async () => { await server?.close() })
beforeEach(() => { setActivePinia(createPinia()) })

function preview(overrides = {}) {
  return {
    invoice_number: '202601', invoice_date: '2026-01-06', due_date: '2026-01-20',
    currency: { id: 8, code: 'CZK' }, tax_mode: 'NO', taxes: [], notes: '<img src=x onerror=alert(1)>\nOld note',
    issuer_name: 'Imported issuer', payment_qr: { iban: 'CZ8120100000002102589740', variable_symbol: '202601', save_account: true },
    items: [{ name: 'Website update', quantity: 1, price: 700000, total: 700000, unit_name: null, taxes: [] }],
    ...overrides,
  }
}

test('PDF prefill keeps historical numbers and dates with editable minor-unit rows', async () => {
  const invoices = useInvoiceStore()
  await invoices.fetchInvoiceInitialSettings(false)
  invoices.newInvoice.signature = { issuer_name: 'Company default', image: 'company-image' }
  prefillImportedInvoice(invoices.newInvoice, preview(), () => 'imported-row')
  assert.equal(invoices.newInvoice.invoice_number, '202601')
  assert.equal(invoices.newInvoice.invoice_date, '2026-01-06')
  assert.equal(invoices.newInvoice.due_date, '2026-01-20')
  assert.equal(invoices.newInvoice.currency_id, 8)
  assert.equal(invoices.getTotal, 700000)
  assert.equal(invoices.newInvoice.payment_qr.save_account, false)
  assert.equal(invoices.newInvoice.signature.image, 'company-image')
  assert.equal(invoices.newInvoice.signature.issuer_name, 'Imported issuer')
  invoices.newInvoice.items[0].price = 600000
  assert.equal(invoices.newInvoice.items[0].price, 600000)
  assert.equal(invoices.newInvoice.customer_id, null)
})

test('customer number refresh preserves an imported number and reset restores normal numbering', async () => {
  const invoices = useInvoiceStore()
  invoices.preserveImportedNumber = true
  invoices.newInvoice.invoice_number = '202501'
  await invoices.getNextNumber({ userId: 7 }, true)
  assert.equal(invoices.newInvoice.invoice_number, '202501')
  invoices.resetCurrentInvoice()
  await invoices.getNextNumber({ userId: 7 }, true)
  assert.equal(invoices.newInvoice.invoice_number, 'INV-1')
})

test('PDF content cannot inject HTML into rich text notes', () => {
  assert.equal(importedNotes('<script>doBad()</script>\nA & B'), '<p>&lt;script&gt;doBad()&lt;/script&gt;<br>A &amp; B</p>')
})

test('VAT suggestions are separate row objects and do not mutate the preview', () => {
  const invoices = useInvoiceStore()
  const tax = { tax_type_id: 4, name: 'VAT', percent: 21, calculation_type: 'percentage', compound_tax: false, fixed_amount: 0, type: 'GENERAL' }
  const data = preview({ tax_mode: 'YES', items: [{ name: 'Work', price: 10000, quantity: 2, taxes: [tax] }] })
  prefillImportedInvoice(invoices.newInvoice, data, () => 'row')
  assert.equal(invoices.newInvoice.items[0].total, 20000)
  assert.equal(invoices.newInvoice.items[0].taxes[0].tax_type_id, 4)
  invoices.newInvoice.items[0].taxes[0].percent = 10
  assert.equal(data.items[0].taxes[0].percent, 21)
})

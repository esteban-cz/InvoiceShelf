import type { Currency } from '@/scripts/types/domain/currency'
import { TaxTypeCategory } from '@/scripts/types/domain/tax'
import type { InvoicePaymentQrInput } from '@/scripts/types/domain/invoice'
import type { DocumentTax } from '../../shared/document-form/use-document-calculations'
import type { InvoiceFormData } from './store'

export interface ImportedParty {
  name: string | null
  registration_number: string | null
  tax_id: string | null
  email: string | null
  phone: string | null
  address_street_1: string | null
  address_street_2: string | null
  city: string | null
  zip: string | null
  country_code: string | null
  country_id?: number | null
}

export interface InvoiceImportPreview {
  filename: string
  pages: number
  ocr: boolean
  format: 'ucto2000' | 'generic'
  invoice_number: string | null
  invoice_date: string | null
  due_date: string | null
  currency_code: string | null
  currency: Currency | null
  total: number | null
  items: Array<{
    name: string
    quantity: number
    price: number
    total: number
    unit_name: string | null
    vat_percent: number | null
    taxes: DocumentTax[]
  }>
  notes: string | null
  issuer_name: string | null
  customer: ImportedParty
  supplier: ImportedParty
  matched_customer_id: number | null
  duplicate_number: boolean
  payment_qr: InvoicePaymentQrInput | null
  warnings: string[]
  source_text: string
  taxes: DocumentTax[]
  tax_mode: 'YES' | 'NO'
}

/** PDF text is escaped before it reaches the rich-text notes field. */
export function importedNotes(text: string): string {
  const escaped = text.replace(
    /[&<>"']/g,
    (char) =>
      ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[
        char
      ]!,
  )
  return `<p>${escaped.replace(/\r?\n/g, '<br>')}</p>`
}

/** Only form fields; never creates an invoice, payment, customer or bank setting. */
export function prefillImportedInvoice(
  form: InvoiceFormData,
  data: InvoiceImportPreview,
  nextId: () => string,
): void {
  if (data.invoice_number) form.invoice_number = data.invoice_number
  if (data.invoice_date) form.invoice_date = data.invoice_date
  if (data.due_date) form.due_date = data.due_date
  if (data.currency) {
    form.selectedCurrency = data.currency
    form.currency_id = data.currency.id
  }
  form.discount = 0
  form.discount_val = 0
  form.discount_type = 'fixed'
  // Imported unit prices are treated as net prices; a total comparison flags other layouts.
  form.tax_included = false
  form.tax_per_item = data.tax_mode
  form.taxes = data.taxes.map((tax) => ({ ...tax, id: nextId(), amount: 0 }))
  if (data.items.length) {
    form.items = data.items.map((item) => ({
      id: nextId(),
      item_id: null,
      invoice_id: null,
      name: item.name,
      description: null,
      price: item.price,
      quantity: item.quantity,
      unit_name: item.unit_name,
      discount: 0,
      discount_val: 0,
      discount_type: 'fixed',
      total: Math.round(item.price * item.quantity),
      tax: 0,
      totalTax: 0,
      totalSimpleTax: 0,
      totalCompoundTax: 0,
      taxes: item.taxes.map((tax) => ({
        ...tax,
        id: tax.tax_type_id,
        percent: tax.percent ?? 0,
        type: TaxTypeCategory.GENERAL,
        amount: 0,
      })),
    }))
  }
  form.notes = data.notes ? importedNotes(data.notes) : ''
  form.selectedNote = null
  form.payment_qr = data.payment_qr
    ? { ...data.payment_qr, save_account: false }
    : null
  if (data.issuer_name && form.signature)
    form.signature = { ...form.signature, issuer_name: data.issuer_name }
}

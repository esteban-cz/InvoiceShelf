export const invoiceVariableSymbolFormats = ['manual', 'invoice_number', 'year_sequence', 'sequence'] as const

export function previewInvoiceVariableSymbol(
  format: string | undefined,
  invoiceNumber: string,
  invoiceDate: string,
  sequence: number | null | undefined,
): string {
  let symbol = ''

  if (format === 'invoice_number') {
    symbol = invoiceNumber.replace(/[^0-9]/g, '')
  } else if (format === 'sequence' && sequence != null) {
    symbol = String(sequence).padStart(6, '0')
  } else if (format === 'year_sequence' && sequence != null) {
    const year = /^([0-9]{4})[-/]/.exec(invoiceDate)?.[1]
    if (year) symbol = year + String(sequence).padStart(3, '0')
  }

  return /^[0-9]{1,10}$/.test(symbol) ? symbol : ''
}

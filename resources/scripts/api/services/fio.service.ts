import { client } from '../client'

export interface FioConnection {
  id: number
  name: string
  iban: string
  currency_code: string
  enabled: boolean
  auto_match: boolean
  payment_method_id: number | null
  sync_from: string
  imported_through: string | null
  last_synced_at: string | null
  last_error: string | null
}
export interface FioTransaction {
  id: number
  connection_id: number | null
  external_id: string
  account_iban: string
  transaction_date: string
  amount: number
  currency_code: string
  variable_symbol: string | null
  counterparty_name: string | null
  counterparty_account: string | null
  message: string | null
  status: string
  reason: string | null
  payment_id: number | null
  payment_number: string | null
}
export interface FioInvoiceCandidate {
  id: number
  number: string
  customer: string
  due_amount: number
  currency_code: string
  variable_symbol: string | null
  requires_exchange_rate: boolean
}
export interface FioPaymentCandidate {
  id: number
  number: string
  customer: string | null
  date: string
  amount: number
}
export interface FioConnectionInput {
  name: string
  token?: string
  sync_from?: string
  enabled: boolean
  auto_match: boolean
  payment_method_id: number | null
}
const base = '/api/v1/fio'
export const fioService = {
  async connections(): Promise<FioConnection[]> {
    const { data } = await client.get(`${base}/connections`)
    return data.data
  },
  async save(input: FioConnectionInput, id?: number): Promise<FioConnection> {
    const { data } = id
      ? await client.patch(`${base}/connections/${id}`, input)
      : await client.post(`${base}/connections`, input)
    return data.data
  },
  async sync(id: number): Promise<void> {
    await client.post(`${base}/connections/${id}/sync`)
  },
  async disconnect(id: number): Promise<void> {
    await client.delete(`${base}/connections/${id}`)
  },
  async transactions(
    status: string,
    page: number,
  ): Promise<{
    data: FioTransaction[]
    meta: { last_page: number; total: number }
  }> {
    const { data } = await client.get(`${base}/transactions`, {
      params: { status, page },
    })
    return data
  },
  async candidates(
    id: number,
  ): Promise<{
    invoices: FioInvoiceCandidate[]
    payments: FioPaymentCandidate[]
  }> {
    const { data } = await client.get(`${base}/transactions/${id}/candidates`)
    return data.data
  },
  async review(
    id: number,
    action: string,
    invoiceId?: number,
    paymentId?: number,
    exchangeRate?: number,
  ): Promise<void> {
    await client.patch(`${base}/transactions/${id}`, {
      action,
      invoice_id: invoiceId,
      payment_id: paymentId,
      exchange_rate: exchangeRate,
    })
  },
}

import type { CustomerForm } from '@/scripts/features/company/customers/store'
import type { AresCompany } from '@/scripts/api/services/customer.service'

/** Apply registry fields after confirmation, preserving contact and delivery details. */
export function applyAresCompany(
  customer: CustomerForm,
  data: AresCompany,
): void {
  Object.assign(customer, {
    registration_number: data.registration_number,
    name: data.name,
    company_name: data.name,
    tax_id: data.tax_id,
  })
  Object.assign(customer.billing, data.billing)
}

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { applyAresCompany } from '../../resources/scripts/utils/ares.ts'

test('applying ARES fills registry fields and preserves contact and shipping details', () => {
  const customer = {
    name: 'Old name',
    email: 'billing@example.com',
    phone: '+420123456789',
    contact_name: 'Accountant',
    currency_id: 6,
    billing: { phone: '+420987654321', address_street_1: 'Old address' },
    shipping: { city: 'Brno' },
  }
  const record = {
    registration_number: '00006947',
    name: 'Registered name',
    tax_id: 'CZ00006947',
    billing: {
      name: 'Registered name',
      address_street_1: 'Letenská 525/15',
      address_street_2: null,
      city: 'Praha',
      state: null,
      zip: '118 00',
      country_id: 57,
    },
  }
  applyAresCompany(customer, record)
  assert.equal(customer.name, 'Registered name')
  assert.equal(customer.company_name, 'Registered name')
  assert.equal(customer.registration_number, '00006947')
  assert.equal(customer.tax_id, 'CZ00006947')
  assert.equal(customer.billing.address_street_1, 'Letenská 525/15')
  assert.equal(customer.billing.phone, '+420987654321')
  assert.equal(customer.phone, '+420123456789')
  assert.equal(customer.email, 'billing@example.com')
  assert.equal(customer.contact_name, 'Accountant')
  assert.equal(customer.currency_id, 6)
  assert.deepEqual(customer.shipping, { city: 'Brno' })
})

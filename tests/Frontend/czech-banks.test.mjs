import assert from 'node:assert/strict'
import { test } from 'node:test'
import { czechBankName, suggestedCzechBankName } from '../../resources/scripts/utils/czech-banks.ts'

test('recognizes Czech account numbers, prefixes, spaces and leading-zero bank codes', () => {
  assert.equal(czechBankName('2102589740/2010'), 'Fio banka, a.s.')
  assert.equal(czechBankName('19-123457 / 0710'), 'ČESKÁ NÁRODNÍ BANKA')
  assert.equal(czechBankName('123457/0100'), 'Komerční banka, a.s.')
})

test('recognizes a formatted Czech IBAN as well as its domestic account number', () => {
  assert.equal(czechBankName('cz81 2010 0000 0021 0258 9740'), 'Fio banka, a.s.')
  assert.equal(czechBankName('CZ4907100000000000123457'), czechBankName('123457/0710'))
})

test('leaves unknown banks, foreign IBANs and incomplete accounts available for manual entry', () => {
  for (const account of ['', '123457/201', '123457/9999', '123457/0000', '2010', 'CZ812010', 'DE89370400440532013000']) {
    assert.equal(czechBankName(account), '', account)
  }
})

test('fills an empty name and replaces the last suggestion when the bank changes', () => {
  assert.equal(suggestedCzechBankName('2102589740/2010', '', ''), 'Fio banka, a.s.')
  assert.equal(suggestedCzechBankName('123457/0100', '2102589740/2010', 'Fio banka, a.s.'), 'Komerční banka, a.s.')
})

test('removes a stale suggestion for an unknown or cleared account', () => {
  assert.equal(suggestedCzechBankName('123457/9999', '2102589740/2010', 'Fio banka, a.s.'), '')
  assert.equal(suggestedCzechBankName('', '2102589740/2010', 'Fio banka, a.s.'), '')
})

test('keeps a manual correction while typing, changing banks or encountering an unknown code', () => {
  for (const account of ['2102589740/2010', '123457/0100', '123457/9999', '']) {
    assert.equal(suggestedCzechBankName(account, '2102589740/2010', 'Moje banka'), 'Moje banka')
  }
})

test('changing the account format preserves the suggestion and a saved custom bank name', () => {
  assert.equal(suggestedCzechBankName('CZ8120100000002102589740', '2102589740/2010', 'Fio banka, a.s.'), 'Fio banka, a.s.')
  assert.equal(suggestedCzechBankName('2102589740/2010', 'CZ8120100000002102589740', 'Fio'), 'Fio')
})

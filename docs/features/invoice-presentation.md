# Invoice emails, signatures and payment details

Invoice emails use the issuing company's language, including when no PDF is
attached. The email contains the invoice number, due date and outstanding
amount. The public page opened from the email loads the same company language.
The configurable Powered by line defaults to `tomecka.com` in this fork.

## Company signature

Open Company Settings → Customization → Invoices → Issuer and signature.
Set the issuer's name and optionally upload a PNG or JPEG, up to 256 KB and
2400 × 1200 pixels. A PNG with a transparent background gives the best result.
The company owner can update or remove this image.

New invoices have **Sign document** enabled by default. Disable it on an
individual invoice to omit the signature image; the issuer's name is still
printed. An invoice without an uploaded company signature prints the name
alone. Existing invoices stay unsigned until explicitly enabled.

The issuer and small signature image are snapshotted with the invoice. Changing
the company settings does not change an already issued document. An invoice
that has no signature image yet can pick up a newly uploaded one when signing
is enabled. Cloned invoices, converted estimates and recurring invoice runs
take their own snapshot from the current company settings.

## Receiving accounts

Open Company Settings → Payment modes to add or edit a receiving account.
Enter either a valid Czech account number, including an optional prefix and
bank code, or a Czech IBAN. Add the bank's name if it should appear on invoices.
The canonical Czech account number is derived from the IBAN, so both forms
remain consistent and existing saved accounts need no manual conversion.

The invoice's payment block records the bank name with its IBAN and variable
symbol. The PDF shows a rounded payment card below the notes and totals, with
the QR code, bank name, Czech account number, IBAN and variable symbol. Paid
invoices retain the account details without a QR code requesting payment.
The issuer name and optional signature sit beside this card in all three stock
invoice templates. Outdated archived PDFs are regenerated for the new footer
or a changed signature.

The existing bank-account API remains compatible: `GET
/api/v1/invoices/bank-accounts` returns IBAN strings. Add `?details=1` to receive
objects with `iban`, `account_number` and `bank_name`. The existing POST accepts
an optional `bank_name` and updates that name when the account already exists.

Apply the database migration during deployment before serving the new code.

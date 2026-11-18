# Importing an older invoice PDF

Choose **Invoices → Import PDF** (Czech: **Faktury → Importovat PDF**), select a
PDF, and choose **Read PDF**. Review the preview, then choose **Fill invoice
form**. This opens the usual editable invoice fields with the original number
and dates when they can be read. Save the invoice normally after reviewing it.

Uploading or applying a preview does not save an invoice, record a payment,
advance a serial number, change company settings or create a customer. A unique
customer with the same registration number, tax ID, or exact name can be
selected within the active company, provided its currency agrees with the PDF.
Otherwise select a customer, or use **Add customer from PDF** to review and save
a prefilled customer form. Existing customer records are never overwritten by
the import. Duplicate invoice numbers are flagged and remain subject to the
normal save validation.

The parser primarily supports the older Czech Účto2000 layout. For other
layouts it attempts Czech/English labels and tabular line items; uncertain or
missing fields are reported. It suggests existing sales tax types only when a
rate has one unambiguous match. Discounts, tax-inclusive prices, complex VAT
layouts, handwritten invoices and unusual tables need manual review. The form
compares its computed total and currency with the PDF total, when readable.
The extracted text is available alongside the preview for checking missing
details. Importing a historical paid invoice still requires recording its
payment separately.

## Server requirements

The production and development Docker images include **Poppler**
(`pdftotext`, `pdfinfo`, `pdftoppm`) and **Tesseract** with Czech and English
language data. Rebuild the image when deploying this feature. Native installs
must install these tools themselves and make them available on the PHP
process's `PATH`. Text PDFs need Poppler; scanned pages also need Tesseract and
its `ces` and `eng` language data. No external AI service or API key is used.

The authenticated, company-scoped endpoint is
`POST /api/v1/invoices/import/preview` with a multipart `file` field and the
normal invoice creation permission. It accepts PDFs up to 10 MB and 10 pages,
with OCR limited to two pages that lack a usable text layer. Text size,
rendered image dimensions, subprocess timeouts and six requests per minute
bound the work. Limits are defined in `config/invoice-import.php`.

Working files live in a randomly named private directory under
`storage/app/private/invoice-import`, which is removed after processing,
including on failures. The uploaded original is not attached to the new
invoice or stored in the media library. Preview responses use `no-store`, and
the browser holds the preview only for the current form session.

## Verification

```sh
php artisan test --compact tests/Unit/InvoiceImportParserTest.php tests/Feature/InvoiceImportTest.php tests/Feature/Pdf/InvoicePdfExtractionTest.php
node --test tests/Frontend/invoice-import.test.mjs
```

The extraction tests exercise real Poppler and OCR when installed; the preview
API tests fake extraction and verify authorization, validation, matching and
absence of domain writes. Committed examples contain fictional contact data.

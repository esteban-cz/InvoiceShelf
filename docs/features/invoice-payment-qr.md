# QR Platba on Czech invoices

Create or edit a CZK invoice and select **Add payment QR code**. Choose a saved receiving account or enter a Czech IBAN or a domestic account number such as `123457/0710`. Domestic numbers can include a prefix, as in `19-123457/0710`. Select **Save this bank account for future invoices** to make that account available to the company on subsequent invoices. Each invoice stores the receiving account used for that document.

Enter a **Variable symbol** if you use one for matching bank transfers. The field accepts up to ten digits and preserves leading zeroes. It is independent of the invoice number and reference number.

Under **Settings → Customization → Invoices → Variable symbol format**, choose manual entry, digits from the invoice number, invoice year plus sequence, or sequence only. The sequence is the company's invoice sequence. The invoice form previews the default for new QR payments and lets you replace or clear it. InvoiceShelf generates the saved value from the invoice at save time.

On an invoice with a positive CZK balance, the three built-in PDF layouts print the locally generated QR code below the totals. The code pre-fills the receiving IBAN, outstanding amount and, when supplied, the variable symbol in a compatible Czech banking app.

Custom invoice templates can use the same block by including `app.pdf.invoice.partials.payment-qr` below their totals.

- [QR Platba format](https://qr-platba.cz/pro-vyvojare/specifikace-formatu/)
- [Czech National Bank account number examples](https://www.cnb.cz/en/payments/iban/how-to-enter-an-account-number-into-the-calculator/)

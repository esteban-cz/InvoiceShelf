# Fio imports and Czech customer lookup

## Connect Fio

Open **Settings → Payment modes → Fio bank connection** as the company owner.
Create a token in Fio Internetbanking with **Sledování účtu** (read-only) access,
wait approximately five minutes for activation, then enter it in the connection
form. The app reads the account's IBAN and currency from Fio, encrypts the token
at rest and never returns it to the browser. An empty token when editing keeps
the existing secret. Renew expiring tokens in Fio or replace them here.

The initial import date defaults to today and can go back 89 days. Check it
carefully if payments have already been entered manually. History is fetched in
30-day windows until caught up; later imports overlap the previous seven days to
pick up late postings. Fio requires at least 30 seconds between API calls; this
integration enforces 35 seconds per token.

Enable **Automatically record exact matches** to record a payment only when one
issued invoice has the same saved QR variable symbol, receiving IBAN, currency
and outstanding amount. Leading zeros in variable symbols do not affect the
comparison. Drafts, uncertain matches, possible existing payments and payments
requiring a foreign-currency exchange rate wait for review. Outgoing and zero
movements are retained as ignored; they do not create expenses or reverse
payments. No customer receipt email is sent by the importer.

Under **Bank transactions**, choose an invoice to record a reviewed incoming
payment. Partial payments reduce its balance; overpayments leave the remainder
as customer credit. For a payment already entered manually, use **Link existing
payment**; this leaves its existing allocations unchanged. Suggestions use the
same amount and currency within three days of the bank date, or an existing
allocation to an invoice with the matching variable symbol and receiving IBAN. Ignoring a movement
is reversible. A matched movement cannot be booked a second time, even if its
payment is subsequently deleted.

Disconnecting deletes the token and stops imports while retaining transaction
history and payments. Reconnecting the same account restores access to pending
movements and preserves deduplication. Deleting the company also deletes its
bank credentials and import history.

The Laravel scheduler runs `fio:sync` every 15 minutes. Keep the installation's
scheduler running; the command can also be invoked manually. A failed account
does not prevent other connections from syncing. Check the connection's last
import date and error in settings. This importer only reads bank statements; it
cannot initiate bank transfers.

Official protocol: [Fio API banking documentation](https://www.fio.cz/docs/cz/API_Bankovnictvi.pdf).

## Look up a Czech customer

On the customer form or quick-create modal, enter an eight-digit **IČO** and
choose **Look up in ARES**. Review the registered name and billing address, then
choose **Apply details**. The lookup alone does not save or replace customer
data. Applying it fills the name, company name, IČO, available DIČ and billing
address; it preserves email, phone, contact person, currency and shipping details.
Review the details before saving the customer. All fields remain editable.

IČO is stored separately from the tax ID (DIČ). Other countries' registration
numbers can be entered manually. Successful ARES results are cached for 24 hours;
missing records or temporary service failures leave the form unchanged.

Official API: [ARES documentation](https://ares.gov.cz/swagger-ui/).

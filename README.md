<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset=".github/assets/logo-dark.svg">
    <img src="resources/static/img/logo-full.svg" alt="InvoiceShelf" width="232">
  </picture>
</p>

<p align="center">
  Open-source invoicing for people who want to own their business data.
</p>

<p align="center">
  <a href="https://invoiceshelf.com/download"><strong>Download InvoiceShelf</strong></a>
  ·
  <a href="https://demo.invoiceshelf.com/"><strong>Try the live demo</strong></a>
  ·
  <a href="https://docs.invoiceshelf.com/">Documentation</a>
  ·
  <a href="https://discord.gg/eHXf4zWhsR">Join Discord</a>
</p>

> [!WARNING]
> The default `3.x` branch is an alpha preview. It is ready for testing and
> feedback, but not for production data. Use the supported
> [`2.x` release](https://github.com/InvoiceShelf/InvoiceShelf/tree/2.x) for a
> production installation.

<picture>
  <source media="(prefers-color-scheme: dark)" srcset=".github/assets/dashboard-dark.webp">
  <img alt="The InvoiceShelf dashboard: what customers owe, split into overdue and due soon, and a year of sales, receipts and expenses" src=".github/assets/dashboard-light.webp">
</picture>

## Run your invoicing from one place

InvoiceShelf is a self-hosted web application for creating invoices, tracking
payments and expenses, and keeping customer accounts organised. It is built for
freelancers and small businesses that want a focused workflow without giving up
control of their data.

Want to look around before installing? The [public demo](https://demo.invoiceshelf.com/)
includes sample data and resets automatically.

- Create invoices and estimates, then export polished PDFs.
- Record payments and see what each customer still owes.
- Track expenses, taxes, and business reports.
- Schedule recurring invoices for repeat work.
- Give customers a portal for invoices, estimates, and payment history.
- Manage multiple companies and invite team members with scoped roles.
- Issue EN 16931 e-invoices: ZUGFeRD / Factur-X PDFs with the e-invoice XML
  embedded (3.x, with the official E-Invoicing module).

Optional official modules can add specialised features without making the core
application heavier.

### E-invoicing

InvoiceShelf 3.x issues e-invoices to the European standard EN 16931 through
the official [E-Invoicing module](https://github.com/InvoiceShelf/module-e-invoicing):

- An invoice's PDF becomes a **ZUGFeRD / Factur-X** hybrid e-invoice, a
  PDF/A-3 file with the EN 16931 XML inside that accounting software reads.
  This meets the German B2B e-invoicing rules and is one of the formats the
  French reform accepts.
- The XML can also be downloaded on its own, for portals that take a file.
- Each invoice shows whether it qualifies and, if not, exactly what is missing.
- Tax types carry their EN 16931 tax category and units their UN/ECE unit code.

It needs the Gotenberg PDF driver. UBL formats (Peppol BIS Billing, XRechnung
UBL) and sending through Peppol are planned.

## Install InvoiceShelf

### Production: InvoiceShelf 2.x

Install the current stable release from the
[self-hosted download page](https://invoiceshelf.com/download), or run the
official Docker image with the `:latest` tag. Follow the
[installation guide](https://docs.invoiceshelf.com/installation.html) for the
complete setup and upgrade instructions.

### Preview: InvoiceShelf 3.x

Use the preview only with disposable or backed-up data:

- Download the latest 3.x preview from the
  [self-hosted download page](https://invoiceshelf.com/download).
- For Docker, use `invoiceshelf/invoiceshelf:next` instead of `:latest` in the
  [official Compose setup](https://github.com/InvoiceShelf/docker).

A minimal SQLite Docker setup looks like this:

```bash
git clone https://github.com/InvoiceShelf/docker.git invoiceshelf
cd invoiceshelf
cp docker-compose.sqlite.yml docker-compose.yml
# For the 3.x preview, change the image tag in docker-compose.yml to :next.
docker compose up -d
```

Open <http://localhost:8090> and finish the setup wizard. Read the
[Docker guide](https://docs.invoiceshelf.com/install/docker.html) before using
InvoiceShelf on a public server.

For a traditional web-server installation, see the
[manual installation guide](https://docs.invoiceshelf.com/install/manual.html).
InvoiceShelf 3.x requires PHP 8.4 or 8.5 and supports MySQL/MariaDB,
PostgreSQL, and SQLite. Docker includes the required application runtime.

## Personal fork releases

This fork starts its own version history at **1.0.8-[3.0.0-alpha.15]**. The fork
version lives in `fork-version.md`; `version.md` records the upstream version
used for module compatibility, mobile clients and upstream update checks. The
administration dashboard and update settings show the combined version, and
`GET /api/v1/app/version` exposes `fork_version` and `display_version` alongside
the upstream `version`.

Configure Coolify to deploy the permanent branch **`1.x.x-3.0.0-alpha.15`**.
Future work starts on a feature or fix branch based on that branch. Once it is
tested, bump `fork-version.md`, add the release notes to `CHANGELOG.md`, and merge
the result into `1.x.x-3.0.0-alpha.15`. Coolify can keep tracking that branch for
every fork release.

Each finalized release also gets an immutable source tag, such as
`fork-v1.0.8-upstream-3.0.0-alpha.15`. Square brackets are for the displayed label;
Git refs use the spelling shown above. Fork tags use the `fork-` prefix so they
do not trigger the original project's release and publication workflows.

The source link defaults to the matching tag in this fork. An explicit
`INVOICESHELF_SOURCE_URL` overrides it and supports `{version}` (upstream),
`{fork_version}` and `{fork_tag}` placeholders. Docker images include both
version files, as do the release archives built by the Makefile.

For the company signature, Czech bank details and customer-facing invoice
emails, see [Invoice presentation](docs/features/invoice-presentation.md).

## Health monitoring

`GET /api/health` is a public readiness check for uptime monitors. It runs a
database query and writes and removes a temporary file on the configured media
upload disk. A healthy response has HTTP status 200:

```json
{"status":"healthy","database":"connected","uploads":"writable"}
```

If either check fails, the endpoint returns HTTP 503 with `status` set to
`unhealthy`. The individual checks report `database: "unavailable"` and/or
`uploads: "not writable"`. Responses are not cached. `/up` remains the basic
application liveness check.

## Learn and get help

- [User and installation documentation](https://docs.invoiceshelf.com/)
- [API reference](https://api-docs.invoiceshelf.com/)
- [Discord community](https://discord.gg/eHXf4zWhsR)
- [Bug reports and feature requests](https://github.com/InvoiceShelf/InvoiceShelf/issues)

## Contribute

Code contributions are welcome. Start with the
[contribution guide](CONTRIBUTING.md) and use the development environment in
[`docker/development`](docker/development/README.md).

You can also help translate InvoiceShelf on
[Crowdin](https://crowdin.com/project/invoiceshelf).

## License

InvoiceShelf is released under the
[GNU Affero General Public License v3.0](LICENSE).

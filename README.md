# AI Search Assistant

AI Search Assistant is a Laravel SaaS application for building customer-facing
AI assistants. A bot can search company knowledge, product catalogs, and
remote REST or GraphQL data sources, then respond through an embeddable website
widget. The application also includes teams, permissions, billing, analytics,
CRM workflows, customer records, conversations, and optional voice input.

## Main capabilities

- Multi-team workspaces with roles and permissions.
- Bots with configurable instructions, welcome/fallback messages, domains,
  channels, capabilities, and widget design.
- Company knowledge articles with indexed retrieval.
- REST API and GraphQL data sources.
- Dataset imports, field discovery, field mappings, synchronization, and
  manually managed records.
- Product cards, product comparison, links, prices, images, and configurable
  widget styling.
- Public website chat widget with persisted visitor conversations.
- Human handoff, inbox conversations, leads, customers, deals, pipelines,
  tasks, appointments, support tickets, and workflows.
- Stripe-backed Starter, Pro, and Business subscriptions.
- AssemblyAI speech-to-text voice input for paid plans.

## Technology

- PHP 8.3+
- Laravel 13
- Inertia.js 3 and React 19
- TypeScript, Tailwind CSS 4, Vite, and Wayfinder
- PostgreSQL or SQLite for local development
- OpenAI Responses API for AI orchestration
- Typesense for optional indexed search infrastructure
- Stripe for billing
- AssemblyAI for production speech transcription
- Pest 4, PHPStan/Larastan, Pint, ESLint, and Prettier

## Requirements

Install the following before starting:

- PHP 8.3 or newer with the project’s required extensions
- Composer
- Node.js and npm
- A database: SQLite for a simple local setup, or PostgreSQL
- An OpenAI API key for live AI responses
- An AssemblyAI API key if voice input is enabled
- Docker, optionally, for Typesense

## Installation

Clone the repository and enter the project directory:

```bash
git clone <repository-url>
cd chatbot
```

Install the backend and frontend dependencies:

```bash
composer install
npm install
```

Create the environment file and application key:

```bash
cp .env.example .env
php artisan key:generate
```

Configure the database in `.env`. The default example uses SQLite. Create the
SQLite file if it does not exist:

```bash
touch database/database.sqlite
php artisan migrate
```

Create the public storage link when using uploaded avatars or public files:

```bash
php artisan storage:link
```

Build the frontend:

```bash
npm run build
```

The bundled setup shortcut performs the common installation steps:

```bash
composer run setup
```

## Environment configuration

At minimum, configure these values for a useful local installation:

```env
APP_URL=http://localhost:8000
DB_CONNECTION=sqlite
OPENAI_API_KEY=your-openai-key
OPENAI_MODEL=your-supported-model
```

The application reads all secrets from the server environment. Never commit
`.env`, API keys, Stripe secrets, or speech provider tokens.

### OpenAI

```env
OPENAI_API_KEY=your-openai-key
OPENAI_MODEL=your-supported-model
OPENAI_TIMEOUT=30
OPENAI_MAX_TOOL_ROUNDS=3
OPENAI_MAX_RESULTS=10
```

`OPENAI_MODEL` must be set to a model available to the configured OpenAI
account. The AI layer uses the Responses API and can call catalog, knowledge,
workflow, appointment, and other registered tools.

### Search

The default search engine is configured through `SEARCH_ENGINE`:

```env
SEARCH_ENGINE=postgres
```

Typesense is available for indexed search deployments:

```env
SEARCH_ENGINE=typesense
TYPESENSE_HOST=127.0.0.1
TYPESENSE_PORT=8108
TYPESENSE_PROTOCOL=http
TYPESENSE_API_KEY=dev-typesense-key
```

Start the included development Typesense service with:

```bash
docker compose -f docker-compose.typesense.yml up -d
```

Reindex a dataset with:

```bash
php artisan search:reindex-dataset DATASET_ID
```

### Billing and Stripe

The public plans are Free, Starter, Pro, and Business. A hidden Legacy plan is
used to preserve access for existing teams without a subscription.

```env
STRIPE_KEY=pk_test_...
STRIPE_SECRET=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
STRIPE_PRICE_STARTER=price_...
STRIPE_PRICE_PRO=price_...
STRIPE_PRICE_BUSINESS=price_...
BILLING_CURRENCY=usd
```

Billing state and feature access are resolved centrally through the billing
services. Do not add plan-name checks directly to controllers.

Current voice entitlement rules:

| Plan | Voice input |
| --- | --- |
| Free | Unavailable |
| Starter | Available |
| Pro | Available |
| Business | Available |
| Legacy | Preserved as available |

Voice input has no minute quota yet. It is only gated by entitlement in this
version.

### Voice input

Production voice input uses AssemblyAI:

```env
SPEECH_TO_TEXT_DRIVER=assemblyai
ASSEMBLYAI_API_KEY=your-assemblyai-api-key
ASSEMBLYAI_BASE_URL=https://api.assemblyai.com
ASSEMBLYAI_TIMEOUT=30
ASSEMBLYAI_CONNECT_TIMEOUT=5
ASSEMBLYAI_POLL_INTERVAL_MS=500
ASSEMBLYAI_MAX_POLL_SECONDS=30
SPEECH_TO_TEXT_MAX_UPLOAD_KILOBYTES=10240
SPEECH_TO_TEXT_MAX_DURATION_SECONDS=60
SPEECH_TO_TEXT_RATE_LIMIT_PER_MINUTE=10
```

The AssemblyAI key is used only by Laravel. It is never sent to the browser.
The public widget receives only `capabilities.voice_input`. Free teams do not
see an active microphone, and the server rejects unauthorized transcription
requests before audio is stored or sent to any provider.

The previous self-hosted Whisper service remains available for local or
specialized deployments:

```env
SPEECH_TO_TEXT_DRIVER=self_hosted_whisper
SPEECH_TO_TEXT_URL=http://127.0.0.1:8001
SPEECH_TO_TEXT_TOKEN=a-long-private-token
```

See [services/speech-to-text/README.md](services/speech-to-text/README.md) for
the Python service setup and Whisper options.

## Running locally

For the normal Laravel development process:

```bash
composer run dev
```

This starts the application’s development processes. If you prefer separate
terminals:

```bash
php artisan serve
npm run dev
```

Open `http://localhost:8000` in a browser. If Vite assets do not update, keep
`npm run dev` running or rebuild with `npm run build`.

## First-time product catalog setup

The basic flow is:

1. Create or select a team.
2. Open **Data Sources** and connect a REST API or GraphQL API.
3. Use **Test connection** to verify the base connection. This tests the
   source; it does not create a dataset or map dataset fields.
4. Create an API operation for the endpoint that returns products. Choose
   **Synced searchable data** for an importable catalog, or **Live information**
   for an on-demand operation.
5. Create a dataset under **Datasets**, select the data source, and choose the
   entity type, primary key path, and retrieval mode.
6. Return to the data source, select the dataset as the operation’s target, and
   run the import. Configure field mappings when the importer requests them.
7. Open **Datasets → Records** to inspect imported products or add a record
   manually with **Add record**.
8. Open the bot and attach the ready dataset under **Datasets**.
9. Configure the bot’s **Design** page and product-card field mappings.
10. Add the website host under **Allowed domains**.
11. Copy the generated widget snippet into the allowed website.

For a product endpoint such as `https://example.com/api/v1/products`, the
operation path is normally `/` when the base URL already includes
`/api/v1/products`. Do not duplicate the path by entering
`/api/v1/products` again.

## REST and GraphQL data sources

REST connections support common authentication methods, default headers,
query parameters, pagination, imports, and live operations. API operation
configuration controls:

- HTTP method and endpoint path
- Synced searchable data versus live information
- Query/body argument mappings
- Safe response-field mappings
- Pagination and checkpoint settings

GraphQL connections use the same general dataset flow but define a GraphQL
document and operation-specific variables. Only explicitly mapped safe fields
are exposed to the assistant.

## Website widget

After a bot is ready and has an allowed domain, the bot page provides a snippet
similar to:

```html
<script
  src="https://your-app.example.com/widget.js"
  data-bot="BOT_PUBLIC_ID"
  data-position="bottom-right"
  async
></script>
```

For local testing, use the same host and port serving Laravel, for example:

```html
<script
  src="http://localhost:8000/widget.js"
  data-bot="BOT_PUBLIC_ID"
  data-position="bottom-right"
  async
></script>
```

The exact website host must be present in the bot’s allowed domains. Add both
`localhost` and `127.0.0.1` if you test using both addresses. The widget uses
the public bot identifier, not the internal database ID.

Public widget endpoints are available under `/api/widget/{botPublicId}`:

- `POST /session` — starts or restores a visitor conversation
- `GET /status` — checks bot availability
- `POST /messages` — sends a text message
- `GET /messages` — polls for new messages
- `POST /transcribe` — transcribes voice input when entitled
- Action, form, and appointment endpoints — handle supported assistant tools

## Bot configuration

The bot page contains the main configuration areas:

- **Setup** — readiness and onboarding requirements.
- **Edit** — name, slug, language, instructions, welcome message, and fallback.
- **Datasets** — datasets the bot can search.
- **Design** — assistant identity, avatar, appearance, send button, product
  card mappings, and live preview.
- **Capabilities** — supported assistant capabilities.
- **Channels** — website and connected messaging channels.
- **Tests** — saved test scenarios and bot test runs.

A bot must be usable and have the required ready datasets before it can answer
publicly. If the widget loads but returns an unavailable response, check the
bot status, attached dataset status, AI configuration, and application logs.

## Manual product records

Manual products are managed as dataset records:

1. Open **Datasets**.
2. Select the product dataset.
3. Click **Records**.
4. Click **Add record**.
5. Fill the mapped fields, including the primary key, title, image URL, price,
   and product URL where applicable.

Imported records and manually created records share the dataset search and
product-card pipeline.

## Scheduled synchronization

API operations configured as synchronized data can run manually or on a
schedule. The scheduler command dispatches due synchronization jobs:

```bash
php artisan api-operations:dispatch-due-syncs
```

In production, run Laravel’s scheduler every minute:

```bash
php artisan schedule:work
```

Use a queue worker when the application is configured with the database queue:

```bash
php artisan queue:work
```

## Testing and quality checks

Run the complete application test suite:

```bash
php artisan test --compact
```

Run selected tests:

```bash
php artisan test --compact tests/Unit/AssemblyAiSpeechToTextProviderTest.php
php artisan test --compact tests/Feature/WidgetTranscriptionTest.php
```

Frontend and static-analysis checks:

```bash
npm run lint:check
npm run types:check
npm run build
vendor/bin/pint --dirty --format agent
git diff --check
```

Feature tests use the database configured in `.env.testing`. The current test
configuration uses PostgreSQL database `chatbot_testing`; that database and
server must be available before running database-backed feature tests.

## Troubleshooting

### The widget does not appear

- Confirm the script URL points to the running Laravel application.
- Confirm `data-bot` contains the bot’s public identifier.
- Add the page host to **Allowed domains**.
- Check that the bot is ready or published.
- Check the browser Network and Console tabs for `/widget/{id}` and asset
  failures.
- Run `npm run build` or leave `npm run dev` running after frontend changes.

### The widget appears but chat returns an error

- Confirm `OPENAI_API_KEY` and `OPENAI_MODEL` are configured.
- Confirm the bot has a ready attached dataset when catalog search is needed.
- Inspect `storage/logs/laravel.log`.
- Check that the configured AI tool limit is not too low for the requested
  operation.
- Start a new conversation after changing bot or dataset configuration.

### Products are present in the database but are not found

- Confirm the records are active.
- Confirm the dataset is ready and attached to the bot.
- Confirm searchable field mappings exist.
- Re-run the relevant import or reindex the dataset.
- Ask using product terms present in the record, then test broader wording.

### Voice transcription fails

- Confirm the team is on Starter, Pro, Business, or an entitled legacy plan.
- Confirm `SPEECH_TO_TEXT_DRIVER=assemblyai` and `ASSEMBLYAI_API_KEY` are set.
- Run `php artisan config:clear` after changing `.env`.
- Check that the browser granted microphone permission.
- Check the recorded MIME type and the configured upload/duration limits.
- Remember that the API key belongs in Laravel’s `.env`, never in widget code.

### Configuration changes are ignored

Clear cached configuration:

```bash
php artisan config:clear
php artisan cache:clear
```

Restart `php artisan serve`, Vite, queue workers, and scheduler processes after
changing environment variables.

## Project structure

```text
app/
  Data/              Typed data objects
  Enums/             Domain enums, including billing and statuses
  Http/              Controllers, middleware, and requests
  Jobs/              Queue jobs
  Models/            Eloquent models
  Policies/          Authorization policies
  Services/          Billing, AI, search, imports, widget, CRM, and integrations
database/
  factories/         Test factories
  migrations/        Database schema
  seeders/            Development seeders
resources/js/
  components/        Reusable React components
  pages/              Inertia pages
  widget-frame.tsx    Public widget application
resources/views/
  widget.blade.php    Public widget shell
routes/
  api.php             Public widget and integration endpoints
  web.php             Dashboard and resource routes
services/
  speech-to-text/     Optional self-hosted Whisper service
tests/
  Feature/            HTTP and database-backed tests
  Unit/               Isolated service tests
```

## Security notes

- Public widget requests are restricted by allowed origin/domain validation.
- Public clients identify bots with opaque public IDs.
- Secrets and provider credentials stay server-side.
- Dataset imports and API operations expose only mapped safe fields.
- Voice entitlement is enforced server-side before provider calls.
- Billing and team access must use existing policies and entitlement services.
- Do not commit `.env`, database dumps, uploaded secrets, or production logs.

## License

This project currently uses the license declared by the repository owner. Add a
license file and update this section before distributing the application.

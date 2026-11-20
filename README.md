# MossyTrunk

Back-office for a small creative business that sells prints, stickers and illustrations at conventions and markets, and on Etsy. It follows the whole path of an item: from a design on the workbench to a product in the catalogue, into stock, sold at an event or online, and finally declared to the URSSAF.

The interface is in French; code, commits and documentation are in English.

[![CI](https://github.com/mossyleaf-studio/mossytrunk/actions/workflows/ci.yml/badge.svg)](https://github.com/mossyleaf-studio/mossytrunk/actions/workflows/ci.yml)
[![Docker Hub](https://img.shields.io/docker/v/injust/mossytrunk?label=docker.io%2Finjust%2Fmossytrunk&sort=date)](https://hub.docker.com/r/injust/mossytrunk)

## Features

| Area | What it does |
|---|---|
| **Designs** | Illustrations and series declined onto gabarits (print 15×15, glossy sticker…), validated into products |
| **Catalogue** | Products, product types and variants, buying price taken from the last purchase |
| **Events** | Conventions and markets with their period, location and expenses |
| **Orders** | Order entry during or after an event, automatic bundle discounts, cost of goods and margin |
| **Stock** | FIFO lots per sellable item, low-stock alerts, inventory after an event that flags probable missing orders |
| **Supplier orders** | Ordered, then received into stock at the real unit cost |
| **Reporting** | Profitability per event, results per month and per year |
| **Accounting** | URSSAF declarations per month or quarter, CSV export of orders |
| **Imports** | SumUp card payments, paid Etsy receipts and the orders of the studio's website shop turned into orders; catalogue and stock published to the website |
| **Workspaces** | Sign-in with mossyleaf accounts, each business sees only its own data, API keys encrypted at rest |

Business rules, with where they are modelled and which tests cover them: [`docs/business/`](docs/business/README.md).

## Stack

Symfony 8.1 · PHP 8.5 on FrankenPHP · Doctrine ORM 3 · PostgreSQL 18 · Vue 3 mounted by Symfony UX, Turbo Drive, Reka UI · PHPUnit 13 · Playwright · PHPStan level 10 · Deptrac. Everything runs in Docker.

The backend follows an onion architecture (`Domain` → `Application` → `Infrastructure` / `Presentation`), enforced by Deptrac. Conventions are described in [`CLAUDE.md`](CLAUDE.md).

## Getting started

Requirements: Docker with the Compose plugin, and `make`.

```bash
make build up        # http://localhost:8080 (set HTTP_PORT to change it)
make install         # composer + npm, first run only
make fixtures        # dev database with a season of mock data
```

People sign in with their mossyleaf account (OpenID Connect); in dev a mock accounts server runs on http://localhost:8092. Type the account `demo` (workspace « Atelier Mousse »); a second workspace, account `autre`, shows that data stays isolated. Any other account name with claims `{"email": "you@example.com", "name": "You"}` creates a new user in a workspace of their own; add `"mossytrunk_workspace": "Atelier Mousse"` to join that workspace instead.

SumUp and Etsy keys are entered per workspace in « Paramètres » and stored encrypted with `APP_ENCRYPTION_KEY` (`php bin/console app:encryption:generate-key`).

## Quality

```bash
make test        # PHPUnit, unit + functional
make phpstan     # static analysis, level 10
make deptrac     # onion layer rules
make cs          # PHP-CS-Fixer (make cs-fix to apply)
make e2e         # Playwright against a dedicated app container with fake SumUp and Etsy
make qa          # all of the above
```

## Production image

The `prod` stage of the [`Dockerfile`](Dockerfile) builds a self-contained image: vendor without dev dependencies, compiled assets, migrations run at boot. GitHub Actions publishes it on every push to `main` as `docker.io/injust/mossytrunk:<short sha>` and `:latest`.

A server only needs [`deploy/`](deploy/): a Compose file running the image with PostgreSQL, and an `.env`. Setup, reverse proxy and backups: [`deploy/README.md`](deploy/README.md).

```bash
make deploy DEPLOY_HOST=user@server DEPLOY_DIR=/path/to/mossytrunk   # run the current commit's image on the server
make image                                                            # build the production image locally
make push                                                             # build and push by hand (docker login first)
```

## License

[MIT](LICENSE)

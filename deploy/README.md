# MossyTrunk — running on a server

This folder is all a server needs: `compose.yaml` runs the app image (FrankenPHP, assets built in) and a PostgreSQL 18 database. No source checkout, no PHP or Node on the host.

## Prerequisites

- Docker Engine with the Compose plugin (`docker compose version`).
- A [mossyleaf accounts](../../accounts/deploy/README.md) server (Authentik) with the `mossytrunk` OIDC application: people sign in there.
- A reverse proxy (Caddy, nginx, Traefik…) that terminates HTTPS and forwards to the port you choose below.

## First install

```bash
mkdir -p ~/mossytrunk && cd ~/mossytrunk
# copy compose.yaml, .env.dist and README.md here (`make deploy-files DEPLOY_HOST=user@server` from a dev machine does it)
cp .env.dist .env
chmod 600 .env
```

Fill `.env`:

| Variable | Value |
|---|---|
| `IMAGE` / `TAG` | Image to run, e.g. `docker.io/injust/mossytrunk` / `latest` or a commit tag |
| `APP_PORT` | Host port the app listens on (the proxy targets it) |
| `APP_BIND` | `0.0.0.0` when the proxy is on another machine, `127.0.0.1` when it runs on this server |
| `TRUSTED_PROXIES` | Who may set `X-Forwarded-*`: the proxy IP/CIDR (e.g. `203.0.113.10`), `private_ranges`, or `REMOTE_ADDR` (trust whoever connects — only when a firewall lets nothing but the proxy reach `APP_PORT`) |
| `DEFAULT_URI` | Public URL, e.g. `https://mossytrunk.example.com` (used for links generated outside a request) |
| `APP_SECRET` | `openssl rand -hex 32` |
| `APP_ENCRYPTION_KEY` | `openssl rand -base64 32` (encrypts the per-workspace API keys, e.g. SumUp, entered in the settings page) |
| `POSTGRES_PASSWORD` | `openssl rand -hex 24` (`POSTGRES_DB` / `POSTGRES_USER` can stay `app`) |
| `OIDC_CLIENT_SECRET` | Client secret of the `mossytrunk` application in mossyleaf accounts (`MOSSYTRUNK_CLIENT_SECRET` in its `.env`) |
| `ACCOUNTS_URL` | Optional, defaults to `https://accounts.mossyleaf.studio`; the `OIDC_*_URL` endpoints derive from it (override `OIDC_TOKEN_URL`/`OIDC_USERINFO_URL` to reach Authentik through an internal URL) |

Never change `APP_SECRET`, `APP_ENCRYPTION_KEY` or `POSTGRES_PASSWORD` after the first start: sessions, stored API keys and the database depend on them. Keep a copy of `.env` with your backups.

## Start

```bash
docker compose pull
docker compose up -d
docker compose logs -f app
```

On every start the app waits for the database, runs pending migrations, warms the cache, then serves HTTP on `APP_PORT`.

## Accounts

There is no sign-up, password or invitation in MossyTrunk: people sign in with their mossyleaf account. To let someone in, invite them in mossyleaf accounts with the `mossytrunk` group (`make invite EMAIL=… NAME=… GROUPS=mossytrunk WORKSPACE="Atelier Mousse"` in `accounts`). Their first sign-in creates their MossyTrunk user in the workspace named by `WORKSPACE` (the `mossytrunk_workspace` claim; the workspace is opened if no workspace has that exact name yet). Invited without `WORKSPACE`, they get a new workspace of their own, named after them. The claim only counts on the first sign-in: moving an existing user to another workspace is not possible.

Users created before mossyleaf accounts keep their workspace and all its data: the first time they sign in with an account that has **the same email**, MossyTrunk links it to them. So invite every existing user with the email they already use here (`docker compose exec -T database psql -U app app -c 'select email from app_user where account_id is null'` lists those not linked yet).

## Shop of the studio's website

MossyTrunk reads the orders of the website's shop itself and publishes its catalogue to it (« Paramètres › Services connectés › Ajouter un service › Site web », then « Publier le catalogue » on its row): nothing to set in `.env`.

- **Network**: the app container must reach the website's **public https address** (`https://mossyleaf.studio`, port 443, through public DNS). A private, loopback or internal address is refused on purpose, also when both applications run on the same server: type the public address, never an internal Docker name. Check from the server: `docker compose exec app curl -s -o /dev/null -w '%{http_code}\n' https://mossyleaf.studio/integrations/orders` prints `401` (or `404` while the website has no token), not a timeout.
- **Token**: the value of `MOSSYTRUNK_TOKEN` in the website's `.env`, typed in the settings page (kept encrypted with `APP_ENCRYPTION_KEY`).
- **Payment fee**: the website does not report the fee SumUp takes on online payments. In « Canaux de vente », create an « En ligne » channel linked to the service « Site web » and give it a cost per order of **2.5 %** (« Frais par commande »): it stands in for that fee on every imported order.

## Reverse proxy

The app speaks plain HTTP. The proxy must forward `Host` and the `X-Forwarded-For`, `X-Forwarded-Proto` and `X-Forwarded-Host` headers, and its IP must match `TRUSTED_PROXIES`; otherwise the app thinks it is served over HTTP and rejects form and API submissions from the HTTPS origin.

Caddy:

```caddyfile
mossytrunk.example.com {
    reverse_proxy app-server:8080
}
```

nginx:

```nginx
server {
    listen 443 ssl;
    server_name mossytrunk.example.com;

    location / {
        proxy_pass http://app-server:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host $host;
    }
}
```

## Update / rollback

Every push to `main` runs the full test suite (`make ci`) and, only if it passes, publishes `docker.io/injust/mossytrunk:<short sha>` and `:latest` (GitHub Actions, [`.github/workflows/ci.yml`](../.github/workflows/ci.yml)). `make deploy` refuses a commit whose image is not published, so only tested commits reach the server. The image is public: the server pulls it without `docker login`.

From a dev machine, once the Actions run for the commit is green:

```bash
make deploy      # defaults to DEPLOY_HOST=debian@duprat.cloud DEPLOY_DIR=/mnt/mossytrunk REMOTE_DOCKER="sudo -n docker"; override them for another server
```

It writes `IMAGE` and `TAG` (the current commit, override with `TAG=<sha>`) into the server `.env`, pulls the image and restarts the app. By hand on the server:

```bash
TAG=<tag> docker compose pull app && TAG=<tag> docker compose up -d
```

`latest` is the most recent push; rolling back means starting a previous tag. Migrations only move forward: restore a backup before rolling back across a schema change.

## Operations

```bash
docker compose ps
docker compose logs -f app
docker compose exec -T database pg_dump -U app app | gzip > backup-$(date +%F).sql.gz
gunzip -c backup.sql.gz | docker compose exec -T database psql -U app app
docker compose down            # stop, data kept
docker compose down -v         # stop and DELETE the database
```

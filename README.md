# RevenuePack SPEC-1 Deployment Repo

This repository now serves as the Git deployment source for RevenuePack.

It replaces the old AMF placeholder repo content.

## Production target

```text
https://revenuepack.com/app/admin/
```

## Server-only file

Do not commit `.env`. Keep it on the server at:

```text
/public_html/.env
```

The app also checks:

```text
/public_html/app/.env
```

## Git deployment model

cPanel pulls this repo and runs `.cpanel.yml`.

The deploy task copies the repo into:

```text
$HOME/public_html/
```

It excludes:

```text
.git
.env
```

## Setup links

```text
https://revenuepack.com/app/admin/setup_admin.php
https://revenuepack.com/app/admin/login.php
https://revenuepack.com/app/admin/index.php
https://revenuepack.com/app/admin/pages/states.php
https://revenuepack.com/app/admin/pages/sources.php
https://revenuepack.com/app/admin/pages/runs.php
https://revenuepack.com/app/admin/pages/storage.php
https://revenuepack.com/app/admin/pages/apply-ai-advice.php
```

## Current build

This repo contains the SPEC-1 v0.1.2 installer hotfix foundation:

- PHP mobile admin shell
- MySQL schema installer
- seed installer
- owner-admin setup
- dashboard pages
- ChatGPT draft-advice bridge
- cPanel deployment config

The collector and full parser framework should be expanded in follow-up commits.

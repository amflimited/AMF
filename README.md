# RevenuePack SPEC-1 Deployment Repo

This repository is now the Git deployment source for RevenuePack.

Production admin target:

```text
https://revenuepack.com/app/admin/
```

Server-only environment file:

```text
/public_html/.env
```

Do not commit `.env`.

## cPanel deployment

Use cPanel Git Version Control to clone/pull this repo into the account. The `.cpanel.yml` file deploys the repo contents into `$HOME/public_html` using rsync while preserving `.env`.

## Setup flow

1. Confirm `/public_html/.env` has valid DB credentials.
2. Pull/deploy this repo from cPanel.
3. Open `https://revenuepack.com/app/admin/setup_admin.php`.
4. If tables are missing, press **Install Database Tables**.
5. Create owner admin.
6. Delete `/public_html/app/admin/setup_admin.php`.

## Admin links

```text
https://revenuepack.com/app/admin/login.php
https://revenuepack.com/app/admin/index.php
https://revenuepack.com/app/admin/pages/states.php
https://revenuepack.com/app/admin/pages/sources.php
https://revenuepack.com/app/admin/pages/runs.php
https://revenuepack.com/app/admin/pages/storage.php
https://revenuepack.com/app/admin/pages/apply-ai-advice.php
```

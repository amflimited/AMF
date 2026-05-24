# Immediate GitHub Webhook Deployment

The fastest supported update path for this Namecheap/cPanel setup is a GitHub push webhook.

Flow:

```text
GitHub commit to cpanel-deploy -> GitHub webhook -> https://revenuepack.com/deploy_webhook.php -> server git reset
```

Expected delay: seconds, not five minutes.

## Server .env requirement

Add a high-entropy secret to `/public_html/.env`:

```text
DEPLOY_WEBHOOK_SECRET=PUT_A_LONG_RANDOM_SECRET_HERE
```

Use at least 24 characters. Longer is better.

## GitHub webhook settings

Repository:

```text
amflimited/AMF
```

Settings -> Webhooks -> Add webhook

Payload URL:

```text
https://revenuepack.com/deploy_webhook.php
```

Content type:

```text
application/json
```

Secret:

```text
same value as DEPLOY_WEBHOOK_SECRET
```

Events:

```text
Just the push event
```

Active:

```text
enabled
```

The endpoint ignores pushes to any branch except `cpanel-deploy`.

## Admin status page

```text
https://revenuepack.com/app/admin/pages/deploy-status.php
```

## Security behavior

- GET requests return 404.
- POST requests without a valid GitHub HMAC signature return 403.
- Non-push events are ignored.
- Pushes to branches other than `cpanel-deploy` are ignored.
- `.env` is backed up and restored during deploy.

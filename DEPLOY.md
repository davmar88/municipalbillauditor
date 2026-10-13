# Deploying RevenueGuard next to Cable Elite

RevenueGuard (this app, at **revenueguard.co.za**) runs on the same Xneelo
server as Cable Elite: **154.65.108.197**, Ubuntu, nginx. nginx picks the
site by the name in the address, so both sites share ports 80 and 443.

Each site has its own folder, Linux user, PHP pool, database and background
worker. Setting up RevenueGuard changes nothing of Cable Elite's.

|              | Cable Elite                        | RevenueGuard                                                |
| ------------ | ---------------------------------- | ----------------------------------------------------------- |
| Code         | `/var/www/crm`                     | `/var/www/revenueguard`                                     |
| nginx site   | `cableelite.co.za`                 | `revenueguard.co.za`                                        |
| PHP runs as  | `www-data` (the default pool)      | `revenueguard` (its own pool, `/run/php/revenueguard.sock`) |
| Database     | `crm`                              | `revenueguard`, with a MySQL user that can only reach it    |
| Queue worker | `cable-elite-queue`                | `revenueguard-queue`                                        |
| Scheduler    | `www-data`'s crontab               | `/etc/cron.d/revenueguard`                                  |
| Mail         | Xneelo, `noreply@cableelite.co.za` | Xneelo, `info@revenueguard.co.za`                           |

Shared: the server, nginx, the MySQL server, PHP, Node, the firewall,
fail2ban, key-only SSH and Certbot's renewal timer.

**One limit to know about.** RevenueGuard's PHP can't read Cable Elite's
files or reach its PHP. Cable Elite's PHP can't read RevenueGuard's `.env` or
the bills customers upload. But nginx runs as `www-data`, and so does Cable
Elite's PHP. nginx needs RevenueGuard's PHP socket, so code running inside a
hacked Cable Elite could use that socket to run code as RevenueGuard. (FastCGI
lets the caller override PHP settings such as `open_basedir`, so that doesn't
stop it.) The fix is to run Cable Elite's PHP as its own user too, leaving
`www-data` to nginx alone. That is a change to Cable Elite's deployment, and
worth making before RevenueGuard holds real customers' bills.

---

## Before you start

1. **The code must be on `main`.** The server deploys `main`. Until the app
   is merged, deploy its branch instead: set `export BRANCH=<branch>` in
   step 1.
2. **The mailbox.** The site sends its email from `info@revenueguard.co.za`
   on Xneelo. You type its password in step 2. Sign-up emails can't be sent
   without it.
3. **An Anthropic API key** from console.anthropic.com, for reading bills.
   Without one, uploaded bills wait for someone to type them in.
4. **Who answers for privacy.** The company name, the information officer and
   a contact address appear in the privacy notice (POPIA).
5. **Memory.** On the server, `free -h`. Below 2 GB, add swap first, or the
   front-end build can run out of memory:
    ```bash
    sudo fallocate -l 2G /swapfile && sudo chmod 600 /swapfile
    sudo mkswap /swapfile && sudo swapon /swapfile
    echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
    ```

## 1. On the server: prepare and install

```bash
ssh cable-elite
export BRANCH=main
git clone --branch "$BRANCH" https://github.com/davmar88/municipalbillauditor.git /tmp/revenueguard
cd /tmp/revenueguard
./deploy/setup.sh prepare    # revenueguard user, database, PHP pool
./deploy/setup.sh app        # code in /var/www/revenueguard, production .env, front-end build
```

`prepare` refuses to run if password SSH is on, and checks that PHP, Node,
MySQL and Certbot are the versions the app needs. The repository is public, so
the server needs no deploy key (see "If the repository goes private").

## 2. By hand: the secrets

Type these in on the server. Don't paste them into chats or tickets.

```bash
nano /var/www/revenueguard/.env
```

```
MAIL_PASSWORD=          # the info@revenueguard.co.za mailbox
ANTHROPIC_API_KEY=      # reads bills
COMPANY_NAME=
INFORMATION_OFFICER=
```

The script set everything else: `APP_KEY`, the database password, production
mode, the Xneelo mail server (`smtp.revenueguard.co.za`, port 465) and
`info@revenueguard.co.za` as the sender and contact address. Then:

```bash
./deploy/setup.sh finish     # permissions, database, nginx site, scheduler, queue worker
```

## 3. Check it before DNS moves

On the server:

```bash
/var/www/revenueguard/deploy/verify.sh http://revenueguard.co.za 127.0.0.1
```

It must end with **All good**. It fetches the pages a visitor hits first, and
checks that RevenueGuard can write its storage and that `www-data` can't read
its secrets.

To see it in a browser, add `154.65.108.197 revenueguard.co.za` to your PC's
hosts file, open `http://revenueguard.co.za`, then remove the line. Signing in
won't work yet: the session cookie is HTTPS-only.

## 4. Point the domain at the server

In Xneelo's control panel, open the DNS settings for **revenueguard.co.za**,
the same screen you used for cableelite.co.za. Change two records:

| Record                   | Type | Now             | Change to          |
| ------------------------ | ---- | --------------- | ------------------ |
| `revenueguard.co.za`     | A    | 129.232.136.253 | **154.65.108.197** |
| `www.revenueguard.co.za` | A    | 129.232.136.253 | **154.65.108.197** |

Leave everything else as it is. The MX, `mail`, `smtp`, `imap`, `pop` and TXT
records keep pointing at Xneelo's mail server, so the mailbox keeps working.
Keep the Web Hosting product: it provides the DNS and the mailbox. Only its web
space goes unused.

- If there is an **AAAA** record for `revenueguard.co.za` or `www`, delete
  it. Otherwise IPv6 visitors, and Certbot, still reach the old host.
- `ftp` follows `www`. To use FTP on the Xneelo hosting, connect to
  `www545.jnb1.host-h.net` instead.
- Lowering the TTL an hour beforehand shortens the switch.

Wait until both names answer with the new address:

```bash
dig +short revenueguard.co.za
dig +short www.revenueguard.co.za    # both: 154.65.108.197
```

## 5. HTTPS

```bash
sudo certbot --nginx -d revenueguard.co.za -d www.revenueguard.co.za
/var/www/revenueguard/deploy/verify.sh       # All good, over https this time
```

Certbot adds HTTPS and the redirect to RevenueGuard's nginx site, and renews
the certificate together with Cable Elite's.

## 6. Your staff login

Staff see every customer's bills and disputes, so the only way to become staff
is from the server. Register on https://revenueguard.co.za with your own
address and open the verification email, then:

```bash
/var/www/revenueguard/deploy/artisan app:make-staff you@example.com
```

Then turn on two-factor sign-in under Settings → Security. `--revoke` takes
staff access away again.

Finally, register with a Gmail address too and check the verification email
arrives, and not in spam.

---

## Deploying updates

Code is pushed to GitHub from your PC; the server only pulls.

```bash
ssh cable-elite
/var/www/revenueguard/deploy/update.sh       # BRANCH=... for another branch
```

It pulls, installs, builds, migrates, rebuilds the caches, restarts the
worker, reloads PHP and runs `verify.sh`. If the check fails, fix it or roll
back before walking away.

`update.sh` doesn't touch the server files under `deploy/`. When one of those
changes, apply it by hand:

- **Queue worker or scheduler:** `/var/www/revenueguard/deploy/setup.sh finish`.
- **PHP pool:** `/var/www/revenueguard/deploy/setup.sh prepare`.
- **nginx site:** after Certbot, `finish` leaves the site alone, so copy the
  change into `/etc/nginx/sites-available/revenueguard.co.za` yourself. Then
  run `sudo nginx -t && sudo systemctl reload nginx`.

Every phase can be run again safely. A failed nginx or PHP-FPM test puts the
old file back, so Cable Elite's next reload never trips over a broken
RevenueGuard file.

## Card payments: Cable Elite's Paystack account

RevenueGuard takes card payments through the same Paystack account as Cable
Elite. Every RevenueGuard payment stays in the main (Mzanzigo) account and
settles to its bank account: RevenueGuard never sends Paystack a subaccount,
a split, a transfer or a refund. In Paystack's dashboard its payments show
**Site: revenueguard.co.za**, and their references start with `mba_`.

Copy the keys from Cable Elite's `.env` on the server, so they never cross
your screen:

```bash
cd /var/www/revenueguard
for k in PAYSTACK_PUBLIC_KEY PAYSTACK_SECRET_KEY; do
    sed -i "s|^$k=.*|$k=$(grep "^$k=" /var/www/crm/.env | cut -d= -f2-)|" .env
done
deploy/setup.sh permissions
deploy/artisan config:cache
```

**Leave Paystack's webhook URL as it is.** An account has one, and Cable
Elite's site uses it. Cable Elite ignores RevenueGuard's payments, and
RevenueGuard finds out about them itself in three ways:

- when the customer comes back from Paystack;
- from Paystack's answer when it charges a saved card;
- every five minutes, with `payments:reconcile`.

**Lock the secret key to this server.** The same key can send money out of
the account: Cable Elite pays suppliers with it, and OTP for transfers is
off. Now that the server holds a second copy, turn on IP whitelisting:

1. In Paystack, open Settings → API Keys & Webhooks and choose Add IP
   address.
2. Add 154.65.108.197. Both sites call Paystack from there.
3. Test a Cable Elite checkout straight after. If it fails, remove the
   address again.

## Forwarded e-bills and municipal replies

Customers forward e-bills, and municipalities reply to disputes, at
`…@bills.revenueguard.co.za`. Mail for that subdomain goes to an inbound email
service, not to Xneelo. For Mailgun:

1. Add `bills.revenueguard.co.za` as a receiving domain.
2. Add the MX records it gives you for `bills` in Xneelo's DNS. This doesn't
   touch revenueguard.co.za's own mail.
3. Create a route that forwards to `https://revenueguard.co.za/inbound/email`.
4. Set `MAILGUN_WEBHOOK_SIGNING_KEY` in `.env`.

After any change to `.env`:

```bash
/var/www/revenueguard/deploy/artisan config:cache
sudo systemctl restart revenueguard-queue
```

## When something is wrong

| Symptom                       | Look at                                                                                          |
| ----------------------------- | ------------------------------------------------------------------------------------------------ |
| A 500 with nothing in the log | Permissions: `/var/www/revenueguard/deploy/setup.sh permissions`                                 |
| Any error                     | `/var/www/revenueguard/storage/logs/laravel-<date>.log`, `/var/log/nginx/revenueguard.error.log` |
| Bills stay "reading"          | `sudo systemctl status revenueguard-queue` and `journalctl -u revenueguard-queue`                |
| A `.env` change does nothing  | `deploy/artisan config:cache`, then restart the worker                                           |
| Sign-up fails                 | `MAIL_PASSWORD`, and that the mailbox exists                                                     |

Run artisan as RevenueGuard's user, with `/var/www/revenueguard/deploy/artisan …`,
so the files it creates belong to the user that PHP and the worker run as.

## If the repository goes private

A deploy key belongs to one repository, so Cable Elite's key won't work here.
Make a second read-only key on the server:

```bash
ssh-keygen -t ed25519 -N "" -C "revenueguard-server-readonly" -f ~/.ssh/id_ed25519_revenueguard
cat ~/.ssh/id_ed25519_revenueguard.pub
#   → GitHub → municipalbillauditor → Settings → Deploy keys → Add. Leave "Allow write access" UNTICKED.
printf 'Host github-revenueguard\n  HostName github.com\n  IdentityFile ~/.ssh/id_ed25519_revenueguard\n  IdentitiesOnly yes\n' >> ~/.ssh/config
git -C /var/www/revenueguard remote set-url origin git@github-revenueguard:davmar88/municipalbillauditor.git
```

For a first install from a private repository, clone from
`git@github-revenueguard:davmar88/municipalbillauditor.git` and run the app
phase with `REPO=` set to that address.

## Backups

Nothing above backs anything up. Copy a nightly `mysqldump` of the
`revenueguard` database and the `storage/app/private` folder off the server.
Keep the copies as private as the server: they hold customers' bills.

## Never

- Never run `db:seed` or the `DemoSeeder` on the server. They create accounts
  with the password `password`.
- Never enable `PasswordAuthentication` in sshd. If you're locked out, use the
  Horizon console and add a key.
- Never commit `.env`, and never paste its secrets into a chat.
- Never give an account staff access by editing the database.
  `app:make-staff` checks the address is verified.
- Never change Cable Elite's pool, crontab or files from here. RevenueGuard
  has its own.

# Deploying RevenueGuard next to Cable Elite

RevenueGuard (this app, at **animalfarm.cc**) runs on the same Xneelo
server as Cable Elite: **154.65.108.197**, Ubuntu, nginx. nginx picks the
site by the name in the address, so both sites share ports 80 and 443. Its
first address, revenueguard.co.za, forwards to animalfarm.cc (see "The old
address").

Each site has its own folder, Linux user, PHP pool, database and background
worker. Setting up RevenueGuard changes nothing of Cable Elite's.

|              | Cable Elite                        | RevenueGuard                                                |
| ------------ | ---------------------------------- | ----------------------------------------------------------- |
| Code         | `/var/www/crm`                     | `/var/www/revenueguard`                                     |
| nginx site   | `cableelite.co.za`                 | `animalfarm.cc`, and `revenueguard.co.za`, which forwards   |
| PHP runs as  | `www-data` (the default pool)      | `revenueguard` (its own pool, `/run/php/revenueguard.sock`) |
| Database     | `crm`                              | `revenueguard`, with a MySQL user that can only reach it    |
| Queue worker | `cable-elite-queue`                | `revenueguard-queue`                                        |
| Scheduler    | `www-data`'s crontab               | `/etc/cron.d/revenueguard`                                  |
| Mail         | Xneelo, `noreply@cableelite.co.za` | Xneelo, `info@revenueguard.co.za`                           |

Shared: the server, nginx, the MySQL server, PHP, Node, the firewall,
fail2ban, key-only SSH and Certbot's renewal timer.

**One limit to know about.** RevenueGuard's PHP can't read Cable Elite's
files or reach its PHP. Cable Elite's PHP can't read RevenueGuard's `.env` or
its storage. But nginx runs as `www-data`, and so does Cable
Elite's PHP. nginx needs RevenueGuard's PHP socket, so code running inside a
hacked Cable Elite could use that socket to run code as RevenueGuard. (FastCGI
lets the caller override PHP settings such as `open_basedir`, so that doesn't
stop it.) The fix is to run Cable Elite's PHP as its own user too, leaving
`www-data` to nginx alone. That is a change to Cable Elite's deployment, and
worth making before the game takes real money.

---

## Before you start

1. **The code must be on `main`.** The server deploys `main`. Until the app
   is merged, deploy its branch instead: set `export BRANCH=<branch>` in
   step 1.
2. **The mailbox.** The site sends its email from `info@revenueguard.co.za`
   on Xneelo. You type its password in step 2. Sign-up emails can't be sent
   without it.
3. **An Anthropic API key** from console.anthropic.com, for translating chat.
   Without one, the Translate button is off.
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
ANTHROPIC_API_KEY=      # translates chat; or paste it on the admin page instead
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
/var/www/revenueguard/deploy/verify.sh http://animalfarm.cc 127.0.0.1
```

It must end with **All good**. It fetches the pages a visitor hits first, and
checks that RevenueGuard can write its storage and that `www-data` can't read
its secrets.

To see it in a browser, add `154.65.108.197 animalfarm.cc` to your PC's
hosts file, open `http://animalfarm.cc`, then remove the line. Signing in
won't work yet: the session cookie is HTTPS-only.

## 4. Point the domain at the server

In Xneelo's control panel, open the DNS settings for **animalfarm.cc**,
the same screen you used for cableelite.co.za. Change two records:

| Record              | Type | Now          | Change to          |
| ------------------- | ---- | ------------ | ------------------ |
| `animalfarm.cc`     | A    | 188.40.1.204 | **154.65.108.197** |
| `www.animalfarm.cc` | A    | 188.40.1.204 | **154.65.108.197** |

Leave everything else as it is. The MX, `mail`, `smtp`, `imap`, `pop` and TXT
records keep pointing at Xneelo's mail server, so the mailbox keeps working.
Keep the Web Hosting product: it provides the DNS and the mailbox. Only its web
space goes unused. revenueguard.co.za's two A records stay pointed here too,
so the old address can forward.

- If there is an **AAAA** record for `animalfarm.cc` or `www`, delete
  it. Otherwise IPv6 visitors, and Certbot, still reach the old host.
- `ftp` follows `www`. To use FTP on the Xneelo hosting, connect to the
  hosting server's own name from Xneelo's control panel instead.
- Lowering the TTL an hour beforehand shortens the switch.

Wait until both names answer with the new address:

```bash
dig +short animalfarm.cc
dig +short www.animalfarm.cc    # both: 154.65.108.197
```

## 5. HTTPS

```bash
sudo certbot --nginx -d animalfarm.cc -d www.animalfarm.cc
/var/www/revenueguard/deploy/verify.sh       # All good, over https this time
```

Certbot adds HTTPS and the redirect to RevenueGuard's nginx site, and renews
the certificate together with Cable Elite's.

## 6. Your staff login

Staff decide which players' item designs go on sale, so the only way to
become staff is from the server. Register on https://animalfarm.cc with your own
address and open the verification email, then:

```bash
/var/www/revenueguard/deploy/artisan app:make-staff you@example.com
```

Then turn on two-factor sign-in under Settings → Security. `--revoke` takes
staff access away again.

Finally, register with a Gmail address too and check the verification email
arrives, and not in spam.

## 7. Selling Apples

The payment keys live on the admin page, not in `.env`, so nobody types them
on the server. Only an admin can open that page, and only from the server
does an account become one:

```bash
/var/www/revenueguard/deploy/artisan app:make-admin you@example.com
```

Then, on https://animalfarm.cc/admin:

1. **PayPal** (US dollars): paste the client ID and secret from
   developer.paypal.com → Apps & Credentials. Leave the mode on Sandbox while
   you try it, then switch to Live with the live app's keys. PayPal needs no
   webhook.
2. Press **Test** next to it, set Sales to **On sale** and save.

Keys are encrypted with `APP_KEY`. If `APP_KEY` ever changes, type the keys
in again.

### Cash-outs

Cash-outs stay closed until you open them on the admin page. Open them only
once a lawyer has confirmed the game may pay out real money: dice decide raids
and crimes, and Apples pass between animals in bounties and shop sales, so it
may need a gambling or payments licence.

Every cash-out then waits for you at https://animalfarm.cc/admin/cashouts,
and Pay sends it to the animal's PayPal email. PayPal has to turn on Payouts
for your business account first. Ask PayPal for it, then tick Payouts in the
app's features on developer.paypal.com. Try a cash-out in Sandbox before going
live.

Turning a cash-out down gives the animal its Apples back, with your reason.

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
  change into `/etc/nginx/sites-available/animalfarm.cc` yourself. Then
  run `sudo nginx -t && sudo systemctl reload nginx`.

Every phase can be run again safely. A failed nginx or PHP-FPM test puts the
old file back, so Cable Elite's next reload never trips over a broken
RevenueGuard file.

## Google sign-in: Cable Elite's Google client

"Continue with Google" on the login and register pages uses the same Google
sign-in client as Cable Elite. In Google Cloud, that client's authorised
redirect URIs include `https://animalfarm.cc/auth/google/callback`,
alongside Cable Elite's. Copy its ID and secret across on the server; the
loop adds the lines if `.env` doesn't have them yet:

```bash
cd /var/www/revenueguard
for k in GOOGLE_CLIENT_ID GOOGLE_CLIENT_SECRET; do
    v=$(grep "^$k=" /var/www/crm/.env | cut -d= -f2-)
    if grep -q "^$k=" .env; then sed -i "s|^$k=.*|$k=$v|" .env; else echo "$k=$v" >> .env; fi
done
deploy/setup.sh permissions
deploy/artisan config:cache
```

The button only shows once `GOOGLE_CLIENT_ID` is set.

- **Branding.** Google's sign-in screen shows the name and logo of the
  Google Cloud project the client belongs to, so RevenueGuard players see
  Cable Elite's. To show RevenueGuard's, create a client in a project of its
  own and put its ID and secret in `.env` instead.
- **Authorised domains.** If Google's Branding page asks for them, add
  `animalfarm.cc`.

## The old address

The site started at revenueguard.co.za and moved to animalfarm.cc. The old
address still points at this server and forwards every page to the same page
on the new one, so old links and bookmarks keep working. Its nginx site is
`deploy/nginx/revenueguard.co.za.conf`, copied by hand to
`/etc/nginx/sites-available/revenueguard.co.za`. It keeps its certificate,
which Certbot renews with the others. The site still sends its email from
`info@revenueguard.co.za`.

Sessions belong to one address, so everyone had to sign in again once after
the move.

## After changing `.env`

```bash
/var/www/revenueguard/deploy/artisan config:cache
sudo systemctl restart revenueguard-queue
```

## When something is wrong

| Symptom                       | Look at                                                                                          |
| ----------------------------- | ------------------------------------------------------------------------------------------------ |
| A 500 with nothing in the log | Permissions: `/var/www/revenueguard/deploy/setup.sh permissions`                                 |
| Any error                     | `/var/www/revenueguard/storage/logs/laravel-<date>.log`, `/var/log/nginx/revenueguard.error.log` |
| Raids never settle            | The scheduler: `/etc/cron.d/revenueguard`, then `deploy/artisan schedule:list`                   |
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
Keep the copies as private as the server: they hold players' accounts and chat.

## Never

- Never run `db:seed` or the `DemoSeeder` on the server. They create accounts
  with the password `password`.
- Never enable `PasswordAuthentication` in sshd. If you're locked out, use the
  Horizon console and add a key.
- Never commit `.env`, and never paste its secrets into a chat.
- Never give an account staff or admin access by editing the database.
  `app:make-staff` and `app:make-admin` check the address is verified.
- Never change Cable Elite's pool, crontab or files from here. RevenueGuard
  has its own.

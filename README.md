[![Logo Image](https://cdn.pterodactyl.io/logos/new/pterodactyl_logo.png)](https://pterodactyl.io)

![GitHub Workflow Status](https://img.shields.io/github/actions/workflow/status/pterodactyl/panel/ci.yaml?label=Tests&style=for-the-badge&branch=1.0-develop)
![Discord](https://img.shields.io/discord/122900397965705216?label=Discord&logo=Discord&logoColor=white&style=for-the-badge)
![GitHub Releases](https://img.shields.io/github/downloads/pterodactyl/panel/latest/total?style=for-the-badge)
![GitHub contributors](https://img.shields.io/github/contributors/pterodactyl/panel?style=for-the-badge)

# Pterodactyl Panel

Pterodactyl® is a free, open-source game server management panel built with PHP, React, and Go. Designed with security
in mind, Pterodactyl runs all game servers in isolated Docker containers while exposing a beautiful and intuitive
UI to end users.

Stop settling for less. Make game servers a first class citizen on your platform.

![Image](https://cdn.pterodactyl.io/site-assets/pterodactyl_v1_demo.gif)

## Documentation

* [Panel Documentation](https://pterodactyl.io/panel/1.0/getting_started.html)
* [Wings Documentation](https://pterodactyl.io/wings/1.0/installing.html)
* [Community Guides](https://pterodactyl.io/community/about.html)
* Or, get additional help [via Discord](https://discord.gg/pterodactyl)

## This fork

Four things are added on top of the upstream Panel, each documented on its own:

* [`MCP.md`](MCP.md) - the Panel as a Model Context Protocol server, served at
  `POST /mcp`. There is no separate process to run and nothing to reverse proxy.
* [`OAUTH.md`](OAUTH.md) - the Panel as an OAuth 2.1 authorization server, so an
  external application can act on behalf of a real user instead of holding a
  shared static key.
* [`BACKUPS.md`](BACKUPS.md) - the Panel's Borg backup adapter, deduplicating,
  incremental and encrypted repositories driven by Wings on the node. Every
  backup feature this adds is gated on what the node's daemon advertises, so
  a node running upstream or outdated Wings refuses them rather than failing
  in an obscure way.
* [`ROADMAP.md`](ROADMAP.md) - the direction of the fork and what is not built yet.

Two-factor authentication is also enforced per user here, not only panel-wide. A
user can be required to use it, or exempted from it, independently of the global
setting; the control lives on the admin user pages and on the application API.
Left unset, an account follows the global setting exactly as before.

Schedules can be monitored with healthchecks.io. Setting a check UUID on a
schedule makes the Panel ping that check, hitting the bare check URL on
success and its `/fail` endpoint on failure; leaving the UUID unset, or
leaving `HEALTHCHECKS_URL` blank, keeps the Panel silent. A run opens with a
ping to `/start` on its first task, sent once the queued delay for that task
has already elapsed. If the schedule's last task is a backup, the run does not
report success when that task finishes; instead the ping is deferred to the
backup actually completing on the node, so success there means the archive
really finished, not just that Wings accepted the request. Because of that,
the healthchecks grace period must be set longer than your slowest backup, or
a run that is still archiving will be reported as down before it is actually
late. A backup that never reports its completion, for whatever reason, still
lands as down once that grace period passes. Put the backup task last in a
schedule for this reason: a backup task followed by other tasks pings success
as soon as the run ends, and the archive's real outcome only overrides that
report later, which is a worse signal than waiting for it up front. A task
marked to continue on failure swallows a connection error to the node rather
than ending the run, so a run that only hit those still pings success.

## Installing this fork

This fork publishes no GitHub release, unlike upstream, so the step in the
upstream installation guide that downloads `panel.tar.gz` from the releases
page does not apply here: the code comes from git instead, and the frontend
assets have to be built locally before the Panel will load without a 500 on a
missing `manifest.json`. Everything else the upstream guide covers - the
webserver, PHP, the database, redis, cron and the systemd unit - is
unchanged; see the [Panel Documentation](https://pterodactyl.io/panel/1.0/getting_started.html)
linked above for those parts. Building the assets needs Node >=22 and Yarn on
whatever machine does the build; PHP stays at the `^8.2 || ^8.3` upstream
already requires.

### Fresh install

Replace the upstream guide's download step with a clone of this fork, then
build the assets it does not ship pre-built:

```bash
git clone --branch 1.0-develop https://github.com/Fabrimat/Pterodactyl-Panel.git /var/www/pterodactyl
cd /var/www/pterodactyl
cp .env.example .env
composer install --no-dev --optimize-autoloader
yarn install --frozen-lockfile
yarn run build:production
```

Continue with the rest of the upstream guide from `php artisan key:generate`
onward: environment setup, database migration, the first admin user, and the
webserver, cron and queue worker configuration.

### Upgrading an existing upstream Panel

If the existing install is already a git checkout, add this fork as a second
remote and switch the local branch onto it instead of downloading a new
archive; the fresh install path above needs none of this, since it already
hands off to the upstream guide:

```bash
cd /var/www/pterodactyl
php artisan down
git remote add fork https://github.com/Fabrimat/Pterodactyl-Panel.git
git fetch fork
git checkout -B 1.0-develop fork/1.0-develop
composer install --no-dev --optimize-autoloader
yarn install --frozen-lockfile
yarn run build:production
php artisan view:clear
php artisan config:clear
php artisan migrate --force --seed
chown -R www-data:www-data /var/www/pterodactyl   # matches whatever user your webserver runs as
php artisan queue:restart
php artisan up
```

If the existing install is not a git checkout, clone the fork into a fresh
directory instead and move `.env` and `storage/` over before running the same
commands there.

Upgrade Wings on every node before upgrading the Panel to a build that gates
on its feature list. This Panel refuses borg create, restore and delete
against a node whose Wings does not advertise borg support, so a Panel
deployed ahead of its nodes has those operations refused rather than
attempted against a daemon that cannot serve them - that refusal is the
safeguard working, not data loss, and it clears once that node's Wings is
upgraded. See `BACKUPS.md`, "Deploy ordering", for the one failure this
ordering exists to avoid.

### Docker compose

The repo ships a `Dockerfile` that builds the assets in `node:22-alpine` and
then installs the PHP dependencies in `php:8.3-fpm-alpine`, and a
`docker-compose.example.yml` that otherwise pulls upstream's own image. Since
this fork publishes no image of its own, build it from that `Dockerfile`
instead of pulling one:

```bash
git clone --branch 1.0-develop https://github.com/Fabrimat/Pterodactyl-Panel.git
cd Pterodactyl-Panel
cp docker-compose.example.yml docker-compose.yml
```

In `docker-compose.yml`, replace the panel service's
`image: ghcr.io/pterodactyl/panel:latest` line with `build: .`, then bring it
up:

```bash
docker compose up -d
```

To upgrade later, pull the fork's changes and rebuild:
`git pull && docker compose up -d --build panel`. The queue worker runs
inside the same container under supervisord, so recreating the container also
restarts it.

Configuration specific to this fork - the `BORG_*` variables,
`APP_BACKUP_DRIVER` and `HEALTHCHECKS_URL` - is documented in
[`BACKUPS.md`](BACKUPS.md), `config/backups.php` and `config/healthchecks.php`,
not repeated here.

## Sponsors

I would like to extend my sincere thanks to the following sponsors for helping fund Pterodactyl's development.
[Interested in becoming a sponsor?](https://github.com/sponsors/pterodactyl)

| Company                                                                           | About                                                                                                                                                                                                                                           |
|-----------------------------------------------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| [**Buildurly**](https://buildurly.com/)                                           | Buildurly is a hardware procurement company. They deliver tailored, enterprise-grade hardware solutions designed around your unique needs. From sourcing to delivery, Buildurly's white-glove service ensures a seamless, worry-free, professional experience.                                                                                                                                          |
| [**Hosturly**](https://hosturly.com/)                                             | Hosturly is an enterprise hosting provider. They provide cost-effective, high-performance, and reliable services, including VPS, Web, Dedicated, and Colocation.                                                                                |
| [**indifferent broccoli**](https://indifferentbroccoli.com/)                      | indifferent broccoli is a game server hosting and rental company. With them, you get top-notch computer power for your gaming sessions. They destroy lag, latency, and complexity--letting you focus on the fun stuff.                         |
| [**Infraly, LLC**](https://infraly.co/)                                           | Infraly is an infrastructure company powering the next generation of online services. Through their brands, Infraly delivers cutting-edge solutions across multiple markets. Their vertically integrated approach provides unmatched performance, scalability, and reliability, giving our customers full control.                                                                                     |
| [**MineStrator**](https://minestrator.com/)                                       | MineStrator is a game server hosting provider. Looking for the most high-end French hosting company for your Minecraft server? More than 24,000 members on our Discord trust us. Give us a try!                                                |
| [**Physgun**](https://physgun.com/)                                               | Physgun is a game server hosting provider. Most providers rent rack space and rebrand a panel. At Physgun, they engineer the performance, write the features, and staff the support. Physgun truly is game hosting perfected!                   |
| [**WISP**](https://wisp.gg/)                                                      | WISP is an industry-leading SaaS platform for game server management, designed for hosting companies, gaming organizations, and enthusiasts. WISP combines modern, intuitive interfaces with powerful tools, making server deployment and administration seamless, scalable, and efficient.                                                                                                                 |


### Supported Games

Pterodactyl supports a wide variety of games by utilizing Docker containers to isolate each instance. This gives
you the power to run game servers without bloating machines with a host of additional dependencies.

Some of our core supported games include:

* Minecraft — including Paper, Sponge, Bungeecord, Waterfall, and more
* Rust
* Terraria
* Teamspeak
* Mumble
* Team Fortress 2
* Counter Strike: Global Offensive
* Garry's Mod
* ARK: Survival Evolved

In addition to our standard nest of supported games, our community is constantly pushing the limits of this software
and there are plenty more games available provided by the community. Some of these games include:

* Factorio
* San Andreas: MP
* Pocketmine MP
* Squad
* Xonotic
* Starmade
* Discord ATLBot, and most other Node.js/Python discord bots
* [and many more...](https://eggs.pterodactyl.io)

## License

Pterodactyl® Copyright © 2015 - 2022 Dane Everitt and contributors.

Code released under the [MIT License](./LICENSE.md).

# Measurements behind the slides

Run in the talk's containers (`docker compose up -d` in `rg-dev/cache`). They install php-library ^8.5.53, the release with every fix found while preparing the talk; use its latest version.

- `trips.php`: what travels between PHP and Redis for one job (station 8's table): the steps sent from PHP against php-library's functions, counted by Redis itself (`INFO stats`, `INFO commandstats`).

      docker compose cp docs/measurements/trips.php app:/tmp/trips.php
      docker compose exec app php /tmp/trips.php 100000

- `zbench.php`: deflate, gzip and zstd 3 on ~50 KB HTML (the codesafe home page and this deck's HTML, which the zstd slide's notes quote; the slide's own page row, a 335 KB page, is the Symfony Cache documentation from the table of symfony/symfony#68083): the generated fragment of versioned-cache's `benchmarks/marshaller.php` against real pages, and the fixed cost of one call.

      sed -n '302,428p' <versioned-cache>/benchmarks/marshaller.php | sed '1i <?php' > zgen.php
      docker compose cp zgen.php app:/tmp/zgen.php
      docker compose cp docs/measurements/zbench.php app:/tmp/zbench.php
      docker compose exec app php /tmp/zbench.php /tmp/zgen.php /app/site/index.html <page.html>...

- `ban.php`: forgetting tags in every design while a second client PINGs Redis all along (the RediSearch slide and its notes): our classic backend (`classic-cms`, the port `Talk\Store\RedisClassic`), Cm_Cache_Backend_Redis as it runs today (`classic` without Lua, `classic-lua` with it, both emulated with its own commands and script), php-library's stores. The last argument plants the names of long-expired pages in the classic lists, as weeks without garbage collection leave them.

      docker compose cp docs/measurements/ban.php app:/tmp/ban.php
      docker compose exec app php /tmp/ban.php classic-cms 1 1000000 1 5000000

- `expire.php`: what the tag lists keep once every page has expired on its own (the RediSearch slide's 28 MB).

      docker compose cp docs/measurements/expire.php app:/tmp/expire.php
      docker compose exec app php /tmp/expire.php classic 1000 1000 5

The compression numbers are the median of three runs. The `ban.php` and `expire.php` numbers are single runs (2026-11-12, on php-library's code of that day), RediSearch's 200 000 pages twice; they move by a few percent between runs. RediSearch on a million pages more: ten runs on that code, 4.99 to 5.91 s (median 5.3 s), and seven on the code of the day before, 4.58 to 5.42 s (median 4.8 s), the two run in turns; Redis ran the same commands for both (`INFO commandstats` around the ban: a million HGET, TYPE and UNLINK, 1 201 FCALL, 400 FT.CURSOR READ; the newer code adds one PTTL) and stored the same page (listpack, 162 B), so the slide says around 5 s.

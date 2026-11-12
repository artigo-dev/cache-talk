# Teaching Websites When to Forget

The surprising half of caching: the road of a production PHP cache, from no cache to versioned Redis, told in fifteen stations for rg-dev #69 (25 November 2026). Every chart in the deck is a real run.

- **The deck:** https://artigo-dev.github.io/cache-talk/ (arrow keys or a presentation remote; `O` shows every slide, `P` opens the presenter view), in English and Polish: `J` or the EN · PL toggle by the slide counter switches, and [`?lang=pl`](https://artigo-dev.github.io/cache-talk/?lang=pl) opens it in Polish.
- **The demos, live on your machine:** Docker with Compose, then

      docker compose up -d --build
      open http://localhost:8765

  Two containers: Redis 8 and PHP 8.5. The scenarios empty their own Redis; it is not published on the host.
- **The numbers behind the slides:** `docs/measurements/` holds the scripts and how to run them.

The demo engine is [ovos/php-library](https://github.com/ovos/php-library); the Symfony packages from the talk are [artigo/versioned-cache](https://github.com/artigo-dev/versioned-cache) and [artigo/cache-stampede](https://github.com/artigo-dev/cache-stampede).

## Layout

- `site/`: the deck, static HTML, CSS and JS; `site/data/results.js` holds the recorded runs it replays.
- `project/`: the php-library application that runs the demos (`php cli.php demo run scenario stampede`).
- `docker/`, `compose.yaml`: the containers.

## License

MIT, see `LICENSE`. The Rajdhani font in `site/fonts/rajdhani/` is under the SIL Open Font License, see its own `LICENSE` there.

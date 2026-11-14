/*	rg-dev / cache — the deck engine. No dependencies, no build step: it opens
	from file:// as well as from the talk's own server.

	Keys
		→ ↓ Space PgDn   next fragment, then next slide
		← ↑ PgUp         back
		Home / End       first / last slide
		<number> Enter   that slide
		O                every slide at once; there the arrows pick a slide, and a click,
		                 Enter or Esc opens it
		F                fullscreen
		B / .            blank screen
		T                light / dark (a projector that washes the dark out); the moon and the sun by
		                 the slide counter do the same
		P                the presenter's window: notes, next slide, clocks; there the arrows, R, S,
		                 L, B and J work as here, sent to the deck
		R                replay the demo on this slide
		L                run it live (only where the talk's server answers)
		S                replay speed
		J                English / Polish (the toggle by the slide counter does the same)
		? / H            this list of keys, in a panel over the deck (and the ? by the slide counter);
		                 Esc, ?, H or a click beside it closes it, any other key closes it and acts.
		                 Its rows are helpGroups below: a key added here is added there
	
	Touch
		swipe left       next fragment, then next slide
		swipe right      back (no swipe while zoomed in: the finger pans the slide then)
		tap              next (a tap is a click)
	
	?all in the URL shows every fragment at once, for reading the deck rather than presenting it.
	?lang=pl opens it in Polish; otherwise the language last chosen, else English.
	
	Every text of the slides is there in both languages, side by side (<span lang="en">…</span><span
	lang="pl">…</span>); <html lang> shows one. The engine's and the demos' own texts go through Deck.t(),
	their Polish in deck/i18n.pl.js.

	A demo slide holds <div class="demo" data-demo="name" data-…>; the
	renderer registered under that name draws the events of a run, recorded
	(data/results.js) or live (api/run, Server-Sent Events). It cannot tell the
	two apart. */

const Deck = (() =>
{
	const params = new URLSearchParams(location.search);
	const embedded = params.has('embed');
	const channel = 'BroadcastChannel' in window ? new BroadcastChannel('rg-dev-cache') : null;
	const slides = [...document.querySelectorAll('.deck > .slide')];
	// each slide's number, shown in the overview (O) only
	slides.forEach((slide, at) => slide.insertAdjacentHTML('beforeend', `<span class="slide-number" aria-hidden="true">${at + 1}</span>`));
	const renderers = {};
	const mounted = new Map();
	const speeds = [0.1, 0.25, 1];
	
	let index = 0;
	let typed = '';
	let live = false;
	let speed = 0;
	
	/* ---- the language: every text in English and Polish, <html lang> shows one ---- */
	
	const title = document.title;
	const missing = new Set();
	
	const language = () => document.documentElement.lang === 'pl' ? 'pl' : 'en';
	
	/** a text the engine or a demo draws, in the language on screen: the English is the key into
	    deck/i18n.pl.js, {name} a value filled in */
	const t = (text, values = {}) =>
	{
		let said = text;
		if(language() === 'pl')
		{
			said = window.DeckPolish?.[text];
			if(said === undefined)
			{
				said = text;
				if(missing.has(text) === false)
				{
					missing.add(text);
					console.warn('no Polish for', text);
				}
			}
		}
		
		return said.replace(/\{(\w+)\}/g, (match, name) => name in values ? String(values[name]) : match);
	};
	
	/** a number with its decimals: a decimal comma in Polish */
	const decimal = (value, digits) => language() === 'pl'
		? value.toFixed(digits).replace('.', ',')
		: value.toFixed(digits);
	
	/* ---- the road: every station once, every divider and the map drawn from it ---- */
	
	const stations = [
		'No cache',
		'Files + TTL',
		'Tags',
		'Many servers',
		'Redis',
		'zstd',
		'Tag lists',
		'Lua in Redis',
		'MemoLock',
		'RediSearch',
		'Versioned',
		'Guard',
		'SWR',
		'Soft invalidation',
		'Symfony',
	];
	
	/** the slide each station starts on: its divider (a road with data-here), or a slide marked data-station */
	const stationStarts = new Map();
	
	const findStations = () =>
	{
		slides.forEach((slide, at) =>
		{
			const number = parseInt(slide.dataset.station ?? slide.querySelector('.road[data-here]')?.dataset.here ?? '', 10);
			if(number > 0 && stationStarts.has(number) === false)
			{
				stationStarts.set(number, at);
			}
		});
	};
	
	/** the station a slide belongs to: the last one started at or before it, 0 before the first */
	const stationOf = (at) =>
	{
		let current = 0;
		for(const [number, start] of stationStarts)
		{
			if(start <= at && number > current)
			{
				current = number;
			}
		}
		
		return current;
	};
	
	/** a slide past the first station without a road of its own gets a small one at its foot: where we are, and a way back */
	const addMiniRoads = () =>
	{
		slides.forEach((slide, at) =>
		{
			const here = stationOf(at);
			if(here > 0 && slide.querySelector('.road') === null)
			{
				slide.insertAdjacentHTML('beforeend', `<div class="road mini" data-here="${here}"></div>`);
			}
		});
	};
	
	/** <div class="road" data-here="7"> lights station 7 and the ones before it; every station leads to its first slide. No
	    years: the road is a sequence of problems, and caching on these sites began long before the first station's code */
	const drawRoads = () =>
	{
		for(const road of document.querySelectorAll('.road'))
		{
			const here = parseInt(road.dataset.here ?? '0', 10);
			road.innerHTML = stations.map((name, at) =>
			{
				const state = at + 1 < here ? 'passed' : (at + 1 === here ? 'here' : '');
				
				return `<div class="station ${state}" data-to="${at + 1}" title="${t(name)}">${t(name)}</div>`;
			}).join('');
		}
	};
	
	/** a click on the slide is the next slide, a right click the previous one: a remote in mouse mode, or the
	    mouse itself. Links, the road's stations and selected text keep their own meaning; so does the overview */
	const followClicks = () =>
	{
		const ignored = (event) => document.body.classList.contains('overview')
			|| event.target.closest('a, button, input, select, textarea, .road, .help') !== null
			|| String(getSelection()) !== '';
		
		addEventListener('click', (event) =>
		{
			if(ignored(event))
			{
				return;
			}
			next();
		});
		addEventListener('contextmenu', (event) =>
		{
			if(ignored(event))
			{
				return;
			}
			event.preventDefault();
			previous();
		});
	};
	
	/** a swipe on a touch screen: to the left the next fragment or slide, to the right back. One finger,
	    mostly sideways; not while the page is zoomed in (the finger pans the slide then), not in the overview
	    (the grid scrolls), not while the list of keys is open, not from a form field, not when it selected
	    text. Touch events, not pointer events:
	    the browser cancels a pointer as soon as it takes the move for a pan, and the swipe would be lost.
	    Passive: pinch-zoom and panning stay the browser's */
	const followSwipes = () =>
	{
		const distance = 50;
		let start = null;
		
		addEventListener('touchstart', (event) =>
		{
			const zoomed = (visualViewport?.scale ?? 1) > 1.01;
			const [touch] = event.touches;
			start = event.touches.length === 1
				&& zoomed === false
				&& document.body.classList.contains('overview') === false
				&& help.hidden
				&& event.target.closest('input, select, textarea') === null
				? {x: touch.clientX, y: touch.clientY}
				: null;
		}, {passive: true});
		
		addEventListener('touchend', (event) =>
		{
			const from = start;
			start = null;
			// a finger still down: it was a pinch
			if(from === null || event.touches.length > 0)
			{
				return;
			}
			
			const [touch] = event.changedTouches;
			const dx = touch.clientX - from.x;
			const dy = touch.clientY - from.y;
			if(Math.abs(dx) < distance || Math.abs(dx) < 2 * Math.abs(dy) || String(getSelection()) !== '')
			{
				return;
			}
			
			dx < 0 ? next() : previous();
		}, {passive: true});
		
		addEventListener('touchcancel', () =>
		{
			start = null;
		}, {passive: true});
	};
	
	/** a click on a station goes to the slide it starts on. Not in the overview: there a click picks the
	    slide it lands on, and every thumbnail carries its road along the bottom */
	const followStations = () =>
	{
		document.addEventListener('click', (event) =>
		{
			if(document.body.classList.contains('overview'))
			{
				return;
			}
			
			const station = event.target.closest('.road .station');
			const start = stationStarts.get(parseInt(station?.dataset.to ?? '', 10));
			if(start === undefined)
			{
				return;
			}
			
			event.stopPropagation();
			show(start);
		}, true);
	};
	
	/** every demo's foot: where its run comes from, and the keys */
	const addDemoFeet = () =>
	{
		for(const demo of document.querySelectorAll('.demo'))
		{
			if(demo.querySelector('.demo-foot') === null)
			{
				demo.insertAdjacentHTML('beforeend', `
					<div class="demo-foot">
						<span class="source"></span>
						<span class="keys"><button type="button" data-key="replay"><kbd>R</kbd> <span lang="en">replay</span><span lang="pl">powtórz</span></button> · <button type="button" data-key="speed"><kbd>S</kbd> <span lang="en">speed</span><span lang="pl">tempo</span></button><span class="live-only"> · <button type="button" data-key="live"><kbd>L</kbd> <span lang="en">live</span><span lang="pl">na żywo</span></button></span></span>
					</div>`);
			}
		}
	};
	
	/** the foot's keys are buttons too: a click does what the key does, for its own demo */
	const followFootKeys = () =>
	{
		document.addEventListener('click', (event) =>
		{
			const button = event.target.closest('.demo-foot button[data-key]');
			const demo = button === null ? null : demoOf(button.closest('.demo'));
			if(demo === null)
			{
				return;
			}
			
			// the keys keep working after the click: Space and Enter must not press the button again
			button.blur();
			switch(button.dataset.key)
			{
				case 'replay':
					replay(demo);
					break;
				case 'speed':
					speed = (speed + 1) % speeds.length;
					currentDemos().forEach(replay);
					break;
				case 'live':
					if(live)
					{
						runDemoLive(demo);
					}
					break;
			}
		});
	};
	
	/* ---- the stage ---- */
	
	const fit = () =>
	{
		const scale = Math.min(innerWidth / 1920, innerHeight / 1080);
		document.documentElement.style.setProperty('--scale', String(scale));
	};
	
	/* ---- ?audit: how low every slide reaches. The bottom of the stage stays free (below --safe): the
	   screen may hang low, behind the room. With ?all every fragment shows; the slides that reach below the line are listed in
	   #audit (and the console), and the line is drawn on every slide ---- */
	
	const audit = async () =>
	{
		await document.fonts.ready;
		// the line, from the stylesheet (--safe)
		const safeHeight = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--safe')) || 1080;
		const scale = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--scale')) || 1;
		const report = slides.map((slide, at) =>
		{
			const top = slide.getBoundingClientRect().top;
			const boxes = (elements) => elements
				.filter((element) => element.closest('aside.notes') === null)
				.map((element) => element.getBoundingClientRect())
				.filter((rect) => rect.width > 0 && rect.height > 0)
				.map((rect) => ({top: (rect.top - top) / scale, bottom: (rect.bottom - top) / scale}));
			const all = boxes([...slide.querySelectorAll('*')]);
			// what is pinned to the floor (the term box, the mini road), and what flows from the top down to it
			const pinned = boxes([...slide.querySelectorAll('.term, .road.mini')]);
			const flowing = boxes([...slide.querySelectorAll('*')].filter((element) => element.closest('.term, .road.mini, .demo') === null));
			const floor = Math.min(safeHeight, ...pinned.map((box) => box.top));
			
			return {
				slide: at + 1,
				lowest: Math.round(Math.max(0, ...all.map((box) => box.bottom))),
				flow: Math.round(Math.max(0, ...flowing.map((box) => box.bottom))),
				floor: Math.round(floor),
			};
		});
		const over = report.filter(({lowest, flow, floor}) => lowest > safeHeight || flow > floor - 12);
		
		slides.forEach((slide) => slide.insertAdjacentHTML('beforeend', '<div class="safe-line"></div>'));
		document.body.insertAdjacentHTML('beforeend', `<pre id="audit" hidden>${JSON.stringify({safeHeight, over, report})}</pre>`);
		console.table(over);
	};
	
	const fragments = (slide) => [...slide.querySelectorAll('.fragment')];
	
	const shownCount = (slide) => fragments(slide).filter((fragment) => fragment.classList.contains('shown')).length;
	
	const show = (target, step = 0) =>
	{
		index = Math.max(0, Math.min(slides.length - 1, target));
		slides.forEach((slide, at) => slide.classList.toggle('current', at === index));
		
		const slide = slides[index];
		const all = fragments(slide);
		// ?all: every fragment shown, for reading the deck rather than presenting it
		const count = step < 0 || params.has('all') ? all.length : step;
		all.forEach((fragment, at) => fragment.classList.toggle('shown', at < count));
		
		if(embedded === false)
		{
			history.replaceState(null, '', `${location.pathname}${location.search}#/${index + 1}`);
		}
		
		chrome.querySelector('.count').textContent = `${index + 1} / ${slides.length}`;
		enterDemos(slide);
		broadcast();
	};
	
	const next = () =>
	{
		const slide = slides[index];
		const hidden = fragments(slide).find((fragment) => fragment.classList.contains('shown') === false);
		if(hidden)
		{
			hidden.classList.add('shown');
			broadcast();
			
			return;
		}
		
		show(index + 1);
	};
	
	const previous = () =>
	{
		const slide = slides[index];
		const shown = fragments(slide).filter((fragment) => fragment.classList.contains('shown'));
		if(shown.length > 0)
		{
			shown.at(-1).classList.remove('shown');
			broadcast();
			
			return;
		}
		
		show(index - 1, -1);
	};
	
	/** #/12 is the twelfth slide; #68051 the slide with that id */
	const fromHash = () =>
	{
		const hash = decodeURIComponent(location.hash.slice(1));
		// no hash: the first slide (an empty one would match every slide that has no id)
		if(hash === '')
		{
			return 0;
		}
		
		if(hash.startsWith('/'))
		{
			return Math.max(0, (parseInt(hash.slice(1), 10) || 1) - 1);
		}
		
		const at = slides.findIndex((slide) => slide.id === hash);
		
		return at < 0 ? 0 : at;
	};
	
	/* ---- the presenter's window, kept in step over a BroadcastChannel ---- */
	
	const broadcast = () =>
	{
		if(channel && embedded === false)
		{
			channel.postMessage({type: 'state', index, step: shownCount(slides[index])});
		}
	};
	
	const presenter = () =>
	{
		window.open(`${location.pathname}?presenter#/${index + 1}`, 'rg-dev-cache-presenter', 'width=1400,height=900');
	};
	
	const presenterView = () =>
	{
		document.body.innerHTML = `
			<div class="presenter">
				<div class="clock"><span class="now"></span><span class="elapsed">00:00</span><span class="where"></span></div>
				<div class="frames">
					<iframe class="current" title="current slide"></iframe>
					<iframe class="upcoming" title="next slide"></iframe>
				</div>
				<div class="notes"><h4><span lang="en">NOTES</span><span lang="pl">NOTATKI</span></h4><div class="text"></div></div>
			</div>`;
		const base = `${location.pathname}?embed`;
		const current = document.querySelector('.presenter .current');
		const upcoming = document.querySelector('.presenter .upcoming');
		const notes = document.querySelector('.presenter .notes .text');
		const where = document.querySelector('.presenter .where');
		const started = Date.now();
		let last = -1;
		
		current.src = `${base}#/${index + 1}`;
		upcoming.src = `${base}#/${index + 2}`;
		
		const follow = ({index: at, step}) =>
		{
			current.contentWindow?.postMessage({type: 'state', index: at, step}, '*');
			upcoming.contentWindow?.postMessage({type: 'state', index: at + 1, step: 0}, '*');
			where.textContent = `${at + 1} / ${slides.length}`;
			if(at !== last)
			{
				last = at;
				notes.innerHTML = slides[at]?.querySelector('aside.notes')?.innerHTML ?? '<p class="m">—</p>';
			}
		};
		
		channel?.addEventListener('message', ({data}) =>
		{
			if(data.type === 'state')
			{
				follow(data);
			}
		});
		
		addEventListener('keydown', (event) =>
		{
			if(event.key === 'j' || event.key === 'J')
			{
				switchLanguage();
				
				return;
			}
			
			// the arrows, and the demo keys: the deck's window does what its own key does
			const command = {
				ArrowRight: 'next',
				ArrowDown: 'next',
				PageDown: 'next',
				' ': 'next',
				ArrowLeft: 'previous',
				ArrowUp: 'previous',
				PageUp: 'previous',
				r: 'replay',
				R: 'replay',
				s: 'speed',
				S: 'speed',
				l: 'live',
				L: 'live',
				b: 'blank',
				B: 'blank',
				'.': 'blank',
			}[event.key];
			if(command)
			{
				event.preventDefault();
				channel?.postMessage({type: 'command', command});
			}
		});
		
		const tick = () =>
		{
			const seconds = Math.floor((Date.now() - started) / 1000);
			document.querySelector('.presenter .now').textContent = new Date().toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
			document.querySelector('.presenter .elapsed').textContent = `${String(Math.floor(seconds / 60)).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`;
		};
		
		setInterval(tick, 1000);
		tick();
		follow({index, step: 0});
		channel?.postMessage({type: 'command', command: 'announce'});
	};
	
	/* ---- demos ---- */
	
	const recorded = (name) => window.RESULTS?.[name] ?? null;
	
	/**
	 * A live run over Server-Sent Events: every event as it comes, all of them
	 * once the run says "done". The stream is closed at "done" - an
	 * EventSource left open reconnects, and the run would start again.
	 */
	const runLive = (name, query, onEvent) => new Promise((resolve, reject) =>
	{
		const events = [];
		const source = new EventSource(`api/run?scenario=${encodeURIComponent(name)}&${new URLSearchParams(query)}`);
		source.onmessage = ({data}) =>
		{
			const event = JSON.parse(data);
			events.push(event);
			onEvent?.(event);
			if(event.type === 'done')
			{
				source.close();
				resolve(events);
			}
		};
		source.onerror = () =>
		{
			source.close();
			reject(new Error('the live run was cut off'));
		};
	});
	
	const demoOf = (element) =>
	{
		if(mounted.has(element) === false)
		{
			const factory = renderers[element.dataset.demo];
			if(factory === undefined)
			{
				// a demo whose scenario is not built yet says so, rather than an empty stage
				const stage = element.querySelector('.demo-stage');
				if(stage && stage.childElementCount === 0)
				{
					stage.innerHTML = `<div class="demo-placeholder">${t('demo · {name} · in preparation', {name: element.dataset.demo})}</div>`;
				}
				
				return null;
			}
			
			const foot = element.querySelector('.demo-foot .source');
			mounted.set(element, {element, foot, view: factory(element)});
		}
		
		return mounted.get(element);
	};
	
	const describe = (env, how) => env
		? `${how} · ${t(env.machine)} · PHP ${env.php} · Redis ${env.redis} · ${env.date}`
		: how;
	
	/** the pace a demo plays at: the deck's speed, times the renderer's own scale (the ban plays in real time) */
	const pace = (demo) => speeds[speed] * (demo.view.scale ?? 1);
	
	/** the pace as the foot writes it: ×0.5, in Polish ×0,5 */
	const paceText = (demo) => language() === 'pl' ? String(pace(demo)).replace('.', ',') : String(pace(demo));
	
	const replay = (demo) =>
	{
		const run = recorded(demo.element.dataset.demo);
		if(run === null)
		{
			demo.foot.textContent = t('no recording yet: run `php cli.php demo record`');
			demo.foot.className = 'source error';
			
			return;
		}
		
		demo.foot.textContent = describe(run.env, t('recorded ×{pace}', {pace: paceText(demo)}));
		demo.foot.className = 'source';
		demo.view.play(run.events, pace(demo));
	};
	
	const runDemoLive = async (demo) =>
	{
		const query = {...demo.element.dataset};
		delete query.demo;
		demo.foot.textContent = t('live: running…');
		demo.foot.className = 'source live';
		demo.view.reset?.();
		
		try
		{
			let count = 0;
			const events = await runLive(demo.element.dataset.demo, query, () =>
			{
				count++;
				demo.foot.textContent = t('live: running… {count} events', {count});
			});
			const begin = events.find((event) => event.type === 'begin');
			const failure = events.find((event) => event.type === 'error');
			demo.foot.textContent = failure
				? t('live run failed: {message}', {message: failure.message})
				: describe(begin?.env, t('live, just now ×{pace}', {pace: paceText(demo)}));
			demo.foot.className = failure ? 'source error' : 'source live';
			demo.view.play(events, pace(demo));
		}
		catch(error)
		{
			demo.foot.textContent = t('live: {message}', {message: t(error.message)});
			demo.foot.className = 'source error';
		}
	};
	
	const enterDemos = (slide) =>
	{
		for(const element of slide.querySelectorAll('.demo[data-demo]'))
		{
			const demo = demoOf(element);
			if(demo)
			{
				replay(demo);
			}
		}
	};
	
	const currentDemos = () => [...slides[index].querySelectorAll('.demo[data-demo]')].map(demoOf).filter(Boolean);
	
	/* ---- a small highlighter for the code on the slides ---- */
	
	const escape = (text) => text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
	
	const grammars = {
		php: /(\/\/[^\n]*|#[^\n[]*$|\/\*[\s\S]*?\*\/)|('(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*")|(\$[a-zA-Z_]\w*)|(\b\d[\d_.]*\b)|(\b(?:function|fn|return|new|class|public|protected|static|use|if|else|foreach|as|do|while|match|true|false|null|array|string|int|bool|float|mixed|void|readonly|extends|implements|throw|try|catch|finally|const)\b)|(\b[a-zA-Z_]\w*(?=\())/gm,
		yaml: /(#[^\n]*)|('(?:[^'])*'|"(?:\\.|[^"\\])*")|(^\s*[\w.-]+(?=:))|(\b\d[\d_.]*\b)|(\b(?:yes|no|true|false|null)\b)()/gm,
		lua: /(--[^\n]*)|('(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*")|()(\b\d+\b)|(\b(?:local|function|return|if|then|else|end|for|do|in|and|or|not|nil|true|false)\b)|(\b[a-zA-Z_][\w.]*(?=\())/gm,
		redis: /(#[^\n]*)|("(?:\\.|[^"\\])*")|()(\b\d+\b)|(\b[A-Z]{3,}\b)()/gm,
	};
	
	const classes = ['c', 's', 'v', 'n', 'k', 'f'];
	
	const highlight = (block) =>
	{
		const grammar = grammars[block.dataset.lang];
		const lines = block.textContent.replace(/^\n|\n\s*$/g, '').split('\n');
		block.innerHTML = lines.map((line) =>
		{
			// a diff: "+ " added, "- " removed, "! " the line to look at
			const mark = {'+': 'add', '-': 'del', '!': 'hl'}[line[0]] && line[1] === ' ' ? {'+': 'add', '-': 'del', '!': 'hl'}[line[0]] : null;
			const text = mark ? line.slice(2) : line;
			const html = grammar
				? escape(text).replace(grammar, (...match) =>
				{
					const group = match.slice(1, 7).findIndex((part) => part !== undefined && part !== '');
					
					return group < 0 ? match[0] : `<span class="${classes[group]}">${match[0]}</span>`;
				})
				: escape(text);
			
			return mark ? `<span class="${mark}">${html || ' '}</span>` : html;
		}).join('\n');
	};
	
	/* ---- keys ---- */
	
	/** what the demo keys do, from the deck's own window or sent from the presenter's */
	const demoCommands = {
		replay: () => currentDemos().forEach(replay),
		speed: () =>
		{
			speed = (speed + 1) % speeds.length;
			currentDemos().forEach(replay);
		},
		live: () =>
		{
			if(live)
			{
				currentDemos().forEach(runDemoLive);
			}
		},
		blank: () => document.body.classList.toggle('blank'),
	};
	
	const keydown = (event) =>
	{
		if(event.altKey || event.ctrlKey || event.metaKey)
		{
			return;
		}
		
		// the list of keys: Esc, ? and H close it; any other key closes it and does what it does. Shift on
		// its way to a ?, and Tab moving inside the list, leave it open
		if(help.hidden === false)
		{
			if(event.key === 'Shift' || event.key === 'Tab')
			{
				return;
			}
			
			toggleHelp(false);
			if(['Escape', '?', 'h', 'H'].includes(event.key))
			{
				event.preventDefault();
				
				return;
			}
		}
		else if(['?', 'h', 'H'].includes(event.key))
		{
			toggleHelp(true);
			
			return;
		}
		
		if(/^[0-9]$/.test(event.key))
		{
			typed+= event.key;
			
			return;
		}
		
		const overview = document.body.classList.contains('overview');
		if(event.key === 'Enter' && (typed !== '' || overview))
		{
			// the overview: Enter opens the slide the arrows picked, or the one whose number was typed
			if(overview)
			{
				toggleOverview(false);
			}
			if(typed !== '')
			{
				show(parseInt(typed, 10) - 1);
			}
			typed = '';
			
			return;
		}
		
		typed = '';
		if(overview && ['ArrowRight', 'ArrowLeft', 'ArrowDown', 'ArrowUp'].includes(event.key))
		{
			event.preventDefault();
			overviewStep({ArrowRight: 1, ArrowLeft: -1, ArrowDown: overviewColumns, ArrowUp: -overviewColumns}[event.key]);
			
			return;
		}
		
		// a remote's back button may send Shift+Space
		if(event.key === ' ' && event.shiftKey)
		{
			event.preventDefault();
			overview ? overviewStep(-1) : previous();
			
			return;
		}
		
		switch(event.key)
		{
			case 'ArrowRight':
			case 'ArrowDown':
			case 'PageDown':
			case ' ':
				event.preventDefault();
				overview ? overviewStep(1) : next();
				break;
			case 'ArrowLeft':
			case 'ArrowUp':
			case 'PageUp':
			case 'Backspace':
				event.preventDefault();
				overview ? overviewStep(-1) : previous();
				break;
			case 'Home':
				show(0);
				break;
			case 'End':
				show(slides.length - 1);
				break;
			case 'o':
			case 'O':
			case 'Escape':
				toggleOverview(event.key === 'Escape' ? false : null);
				break;
			case 'f':
			case 'F':
				document.fullscreenElement ? document.exitFullscreen() : document.documentElement.requestFullscreen();
				break;
			case 'F5':
				// a remote's "start the show" (F5, Shift+F5): full screen, not a reload; Ctrl+R still reloads
				event.preventDefault();
				if(document.fullscreenElement === null)
				{
					document.documentElement.requestFullscreen();
				}
				break;
			case 'b':
			case 'B':
			case '.':
				demoCommands.blank();
				break;
			case 't':
			case 'T':
				toggleTheme();
				break;
			case 'p':
			case 'P':
				presenter();
				break;
			case 'r':
			case 'R':
				demoCommands.replay();
				break;
			case 'l':
			case 'L':
				demoCommands.live();
				break;
			case 's':
			case 'S':
				demoCommands.speed();
				break;
			case 'j':
			case 'J':
				switchLanguage();
				break;
		}
	};
	
	const overviewColumns = 6;
	
	/** in the overview: a step through the grid, the current slide kept in view */
	const overviewStep = (delta) =>
	{
		show(Math.max(0, Math.min(slides.length - 1, index + delta)));
		slides[index].scrollIntoView({block: 'nearest'});
	};
	
	const toggleOverview = (force = null) =>
	{
		const on = document.body.classList.toggle('overview', force ?? undefined);
		const columns = overviewColumns;
		// the deck's own width: its scrollbar's room is kept free (deck.css)
		const deck = document.querySelector('.deck');
		const width = deck.clientWidth;
		slides.forEach((slide, at) =>
		{
			if(on)
			{
				const scale = width / columns / 1920 * 0.92;
				const x = (at % columns + 0.5) * width / columns;
				const y = (Math.floor(at / columns) + 0.5) * 1080 * scale / 0.92 + 10;
				slide.style.transform = `translate(-50%, -50%) scale(${scale})`;
				slide.style.left = `${x}px`;
				slide.style.top = `${y}px`;
			}
			else
			{
				slide.style.transform = slide.style.left = slide.style.top = '';
			}
		});
		// the grid is taller than the window: .deck scrolls (deck.css), opened at the current slide
		if(on)
		{
			slides[index].scrollIntoView({block: 'center'});
		}
		else
		{
			deck.scrollTop = 0;
		}
	};
	
	/** English or Polish: <html lang>, the title, the road's names, the buttons' titles; remembered, and in
	    the address (?lang=pl) so that a copied link opens in the language on screen */
	const applyLanguage = (chosen) =>
	{
		document.documentElement.lang = chosen;
		document.title = t(title);
		try
		{
			localStorage.setItem('rg-dev-cache-language', chosen);
		}
		catch
		{
			// a private window: the language lasts until the tab closes
		}
		
		const url = new URL(location.href);
		const wanted = chosen === 'pl' ? 'pl' : null;
		if(embedded === false && params.has('presenter') === false && url.searchParams.get('lang') !== wanted)
		{
			wanted === null ? url.searchParams.delete('lang') : url.searchParams.set('lang', wanted);
			history.replaceState(null, '', url);
		}
		
		drawRoads();
		const titles = {replay: 'Replay the demo (R)', speed: 'Change the speed (S)', live: 'Run it live (L)'};
		document.querySelectorAll('.demo-foot button[data-key]').forEach((button) =>
		{
			button.title = t(titles[button.dataset.key]);
		});
		const buttons = {'.language': 'English / Polish (J)', '.theme': 'Dark / light (T)', '.help-toggle': 'Shortcuts (?)'};
		for(const [selector, text] of Object.entries(buttons))
		{
			const button = chrome.querySelector(selector);
			if(button)
			{
				button.title = t(text);
				button.setAttribute('aria-label', t(text));
			}
		}
		if(help.hidden === false)
		{
			renderHelp();
		}
	};
	
	/** J, the toggle, the presenter's window: the other language, for every window of the deck */
	const switchLanguage = (chosen = language() === 'pl' ? 'en' : 'pl', tell = true) =>
	{
		applyLanguage(chosen);
		if(params.has('presenter') === false)
		{
			currentDemos().forEach(replay);
		}
		if(tell)
		{
			channel?.postMessage({type: 'language', language: chosen});
		}
	};
	
	const toggleTheme = () =>
	{
		const light = document.documentElement.dataset.theme !== 'light';
		document.documentElement.dataset.theme = light ? 'light' : 'dark';
		try
		{
			localStorage.setItem('rg-dev-cache-theme', light ? 'light' : 'dark');
		}
		catch
		{
			// a private window: the theme lasts until the tab closes
		}
	};
	
	/* ---- ? and H: the list of keys, the header of this file for whoever has no keyboard or forgot ---- */
	
	/** each row: its keys (an array, each in a <kbd>) or its gesture (a text), then what it does */
	const helpGroups = [
		['Moving', [
			[['→', '↓', 'Space', 'PgDn'], 'next fragment, then next slide'],
			[['←', '↑', 'PgUp', 'Backspace'], 'back'],
			[['Home', 'End'], 'first / last slide'],
			[['0–9', 'Enter'], 'the slide with that number'],
			[['O'], 'every slide at once; a click or Enter opens one'],
		]],
		['Screen', [
			[['F'], 'full screen'],
			[['B', '.'], 'blank screen'],
			[['T'], 'dark / light'],
			[['J'], 'English / Polish'],
			[['P'], 'the presenter\'s window: notes, next slide, clocks'],
			[['?', 'H'], 'this list'],
		]],
		['Demos', [
			[['R'], 'replay the demo on this slide'],
			[['S'], 'replay speed'],
			[['L'], 'run it live (only where the talk\'s server answers)'],
		]],
		['Mouse and touch', [
			['click, tap', 'next'],
			['right click', 'back'],
			['swipe left', 'next'],
			['swipe right', 'back'],
		]],
	];
	
	const help = Object.assign(document.createElement('div'), {className: 'help', hidden: true});
	
	/** the list in the language on screen: built when it opens, again when the language changes */
	const renderHelp = () =>
	{
		const keys = (names) => names.map((name) => `<kbd>${name === 'Space' ? t('Space') : name}</kbd>`).join(' ');
		const row = ([what, does]) => `<dt>${Array.isArray(what) ? keys(what) : `<span class="gesture">${t(what)}</span>`}</dt><dd>${t(does)}</dd>`;
		const group = ([name, rows]) => `<section><div class="help-group">${t(name)}</div><dl>${rows.map(row).join('')}</dl></section>`;
		help.innerHTML = `
			<div class="help-panel" role="dialog" aria-modal="true" aria-labelledby="help-title" tabindex="-1">
				<div class="help-title" id="help-title">${t('Shortcuts')}</div>
				<button type="button" class="help-close" title="${t('Close (Esc)')}" aria-label="${t('Close (Esc)')}">×</button>
				<div class="help-groups">${helpGroups.map(group).join('')}</div>
				<p class="help-foot">${t('Esc, ? or a click beside this list closes it')}</p>
			</div>`;
	};
	
	const toggleHelp = (force = null) =>
	{
		const open = force ?? help.hidden;
		if(open)
		{
			renderHelp();
		}
		help.hidden = open === false;
		if(open)
		{
			help.querySelector('.help-panel').focus();
		}
	};
	
	/** the moon and the sun of the theme toggle: drawn, so they need no Polish */
	const moon = '<svg viewBox="0 0 16 16" aria-hidden="true"><path class="solid" d="M14 8.53A6 6 0 1 1 7.47 2a4.67 4.67 0 0 0 6.53 6.53Z"/></svg>';
	const sun = '<svg viewBox="0 0 16 16" aria-hidden="true"><circle class="solid" cx="8" cy="8" r="3"/><path d="M8 1v1.6M8 13.4V15M1 8h1.6M13.4 8H15M3.05 3.05l1.13 1.13M11.82 11.82l1.13 1.13M3.05 12.95l1.13-1.13M11.82 4.18l1.13-1.13"/></svg>';
	
	/* ---- start ---- */
	
	const chrome = document.createElement('div');
	
	const start = async () =>
	{
		try
		{
			const theme = localStorage.getItem('rg-dev-cache-theme');
			if(theme)
			{
				document.documentElement.dataset.theme = theme;
			}
		}
		catch
		{
			// no storage: the default theme
		}
		
		let chosen = params.get('lang');
		try
		{
			chosen ??= localStorage.getItem('rg-dev-cache-language');
		}
		catch
		{
			// no storage: English, or the address's
		}
		
		chrome.className = 'chrome';
		chrome.innerHTML = `<button type="button" class="help-toggle">?</button><button type="button" class="theme"><span class="dark">${moon}</span> · <span class="light">${sun}</span></button><button type="button" class="language"><span class="en">EN</span> · <span class="pl">PL</span></button><span class="count"></span>`;
		findStations();
		addMiniRoads();
		addDemoFeet();
		applyLanguage(chosen === 'pl' ? 'pl' : 'en');
		channel?.addEventListener('message', ({data}) =>
		{
			if(data.type === 'language' && data.language !== language())
			{
				switchLanguage(data.language, false);
			}
		});
		
		if(params.has('presenter'))
		{
			index = fromHash();
			presenterView();
			
			return;
		}
		
		document.body.append(chrome);
		chrome.querySelector('.language').addEventListener('click', (event) =>
		{
			event.currentTarget.blur();
			switchLanguage();
		});
		chrome.querySelector('.theme').addEventListener('click', (event) =>
		{
			event.currentTarget.blur();
			toggleTheme();
		});
		chrome.querySelector('.help-toggle').addEventListener('click', (event) =>
		{
			event.currentTarget.blur();
			toggleHelp(true);
		});
		// the list closes from its × or a click beside its panel; a click on it never moves the deck
		document.body.append(help);
		help.addEventListener('click', (event) =>
		{
			if(event.target === help || event.target.closest('.help-close') !== null)
			{
				toggleHelp(false);
			}
		});
		document.querySelectorAll('pre.code').forEach(highlight);
		slides.forEach((slide, at) => slide.addEventListener('click', (event) =>
		{
			if(document.body.classList.contains('overview'))
			{
				// the click picks this slide and ends here: on the window it would also be "next" (a remote's
				// click), the overview being closed by then
				event.stopPropagation();
				toggleOverview(false);
				show(at);
			}
		}));
		
		fit();
		addEventListener('resize', fit);
		show(fromHash());
		
		if(params.has('audit'))
		{
			audit();
		}
		
		if(embedded)
		{
			chrome.hidden = true;
			addEventListener('message', ({data}) =>
			{
				if(data?.type === 'state')
				{
					show(data.index, data.step);
				}
			});
			
			return;
		}
		
		addEventListener('keydown', keydown);
		addEventListener('hashchange', () => show(fromHash()));
		followStations();
		followClicks();
		followSwipes();
		followFootKeys();
		channel?.addEventListener('message', ({data}) =>
		{
			if(data.type === 'command')
			{
				({next, previous, announce: broadcast, ...demoCommands})[data.command]?.();
			}
		});
		
		// live runs: only where the talk's own server answers
		if(location.protocol.startsWith('http'))
		{
			try
			{
				const reply = await fetch('api/ping', {signal: AbortSignal.timeout(1500)});
				live = reply.ok && (await reply.json()).live === true;
			}
			catch
			{
				live = false;
			}
		}
		
		document.documentElement.classList.toggle('can-run-live', live);
	};
	
	return {
		start,
		register: (name, factory) =>
		{
			renderers[name] = factory;
		},
		isLive: () => live,
		language,
		t,
		decimal,
	};
})();

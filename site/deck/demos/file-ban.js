/*	Forgetting one tag in a file cache, as the cached pages grow: in memory and
	on disk, side by side.

	x is the number of cached pages with the tag (linear: a straight line
	shows), y the time to forget the tag. Per number of pages two bars: the
	same pages in memory (tmpfs, the best case) and on disk (ext4 on an SSD).
	Every bar grows at once, in real time (scale 10, with the deck's ×0.1),
	for as long as its deletes took: the memory bars stop, the disk bars go on.
	Then per medium a dashed line through the origin, at its median time per
	page. */

Deck.register('file-ban', (element) =>
{
	const stage = element.querySelector('.demo-stage');
	const svgNs = 'http://www.w3.org/2000/svg';
	let frame = 0;
	
	const make = (name, attributes = {}, parent = null) =>
	{
		const node = document.createElementNS(svgNs, name);
		for(const [key, value] of Object.entries(attributes))
		{
			node.setAttribute(key, String(value));
		}
		
		parent?.append(node);
		
		return node;
	};
	
	const reset = () =>
	{
		cancelAnimationFrame(frame);
		stage.innerHTML = '';
	};
	
	const thousands = (n) => n.toLocaleString('en').replace(/,/g, ' ');
	
	const duration = (ms) => ms >= 1000 ? `${Deck.decimal(ms / 1000, 2)} s` : ms >= 10 ? `${Math.round(ms)} ms` : `${Deck.decimal(ms, 1)} ms`;
	// the time axis: whole steps as they are (1 s, 2 s, 500 ms), no trailing zeros
	const tick = (ms) => ms >= 1000 ? `${Deck.decimal(ms / 1000, Number.isInteger(ms / 1000) ? 0 : 1)} s` : `${ms} ms`;
	
	// the media in the order of a pair of bars: memory left, disk right
	const order = ['memory', 'disk'];
	
	const play = (events, pace = 0.5) =>
	{
		reset();
		const segments = events.filter((event) => event.type === 'segment').sort((a, b) => order.indexOf(a.mode) - order.indexOf(b.mode));
		const runs = events.filter((event) => event.type === 'forgotten');
		if(segments.length === 0 || runs.length === 0)
		{
			stage.textContent = Deck.t('This run has no bans.');
			
			return;
		}
		
		const width = stage.clientWidth || 1680;
		const height = stage.clientHeight || 600;
		const labelWidth = 440;
		const stampWidth = 300;
		const chartWidth = width - labelWidth - stampWidth - 40;
		const top = 30;
		const axisHeight = 40;
		const bottom = height - axisHeight;
		const middle = (top + bottom) / 2;
		
		const maxPages = Math.max(...runs.map((run) => run.pages));
		const pageStep = [1000, 2000, 5000, 10000, 20000, 25000, 50000, 100000].find((candidate) => maxPages / candidate <= 6) ?? 200000;
		// half a step past the largest run: room for its bars, no empty tick after it
		const pageSpan = maxPages + pageStep / 2;
		const maxMs = Math.max(...runs.map((run) => run.ms));
		const msStep = [1, 2, 5, 10, 20, 50, 100, 200, 250, 500, 1000, 2000].find((candidate) => maxMs * 1.15 / candidate <= 6) ?? 5000;
		const msSpan = Math.ceil(maxMs * 1.15 / msStep) * msStep;
		const x = (pages) => labelWidth + pages / pageSpan * chartWidth;
		const y = (ms) => bottom - Math.min(msSpan, ms) / msSpan * (bottom - top);
		
		const svg = make('svg', {width, height, viewBox: `0 0 ${width} ${height}`, class: 'ban file-ban'}, stage);
		
		const axis = make('g', {class: 'axis'}, svg);
		for(let pages = 0; pages <= pageSpan; pages+= pageStep)
		{
			make('line', {x1: x(pages), x2: x(pages), y1: top, y2: bottom + 6, class: 'tick'}, axis);
			make('text', {x: x(pages), y: height - 8, class: 'tick-label'}, axis).textContent = thousands(pages);
		}
		for(let ms = msStep; ms <= msSpan; ms+= msStep)
		{
			make('line', {x1: labelWidth, x2: labelWidth + chartWidth, y1: y(ms), y2: y(ms), class: 'tick'}, axis);
			make('text', {x: labelWidth + 8, y: y(ms) - 8, class: 'tick-label y'}, axis).textContent = tick(ms);
		}
		make('line', {x1: labelWidth, x2: labelWidth + chartWidth, y1: bottom, y2: bottom, class: 'floor'}, axis);
		make('text', {x: labelWidth - 40, y: height - 8, class: 'axis-title'}, axis).textContent = Deck.t('cached pages with the tag →');
		make('text', {x: labelWidth - 16, y: middle, transform: `rotate(-90 ${labelWidth - 16} ${middle})`, class: 'axis-title vertical'}, axis).textContent = Deck.t('time to forget the tag');
		
		// left: what was measured, then a line per medium, its color, its name and what it stands for
		make('text', {x: 0, y: top + 34, class: 'panel-label'}, svg).textContent = Deck.t('File cache');
		['Talk\\Store\\File', Deck.t('one tag on every page'), Deck.t('one invalidateTags() call')].forEach((line, row) =>
		{
			make('text', {x: 0, y: top + 66 + row * 30, class: 'panel-sub'}, svg).textContent = line;
		});
		const media = segments.map((segment, at) =>
		{
			const [name, ...notes] = Deck.t(segment.label).split(' · ');
			const ly = top + 210 + at * 74;
			make('rect', {x: 0, y: ly - 15, width: 34, height: 18, class: `swatch ${segment.mode}`}, svg);
			make('text', {x: 50, y: ly, class: 'legend-name'}, svg).textContent = name;
			notes.forEach((note, row) =>
			{
				make('text', {x: 50, y: ly + 28 + row * 28, class: 'legend-label'}, svg).textContent = note;
			});
			
			return {
				mode: segment.mode,
				name,
				runs: runs.filter((run) => run.mode === segment.mode).sort((a, b) => a.pages - b.pages),
				summary: events.find((event) => event.type === 'summary' && event.mode === segment.mode),
			};
		});
		
		// a pair of bars per number of pages, side by side; each label leans away from the other bar
		// (memory's ends at its bar's right edge, disk's starts at its bar's left edge: see deck.css)
		const barWidth = Math.max(8, Math.min(26, chartWidth / pageSpan * pageStep * 0.18));
		const gap = 4;
		const bars = media.flatMap((medium, side) => medium.runs.map((run) =>
		{
			const center = x(run.pages) + (side - (media.length - 1) / 2) * (barWidth + gap);
			const edge = medium.mode === 'memory' ? center + barWidth / 2 : center - barWidth / 2;
			const bar = {
				run,
				rect: make('rect', {x: center - barWidth / 2, y: bottom, width: barWidth, height: 0, class: `bar ${medium.mode}`}, svg),
				label: make('text', {x: edge, y: y(run.ms) - 12, class: `bar-label ${medium.mode}`, opacity: 0}, svg),
			};
			bar.label.textContent = duration(run.ms);
			
			return bar;
		}));
		
		// a pair whose times are close (the smallest tag): the longer one's label goes above the shorter one's
		for(const pages of new Set(runs.map((run) => run.pages)))
		{
			const pair = bars.filter((bar) => bar.run.pages === pages).sort((a, b) => a.run.ms - b.run.ms);
			for(let at = 1; at < pair.length; at++)
			{
				const below = Number(pair[at - 1].label.getAttribute('y'));
				const own = Number(pair[at].label.getAttribute('y'));
				pair[at].label.setAttribute('y', String(Math.min(own, below - 24)));
			}
		}
		
		// per medium, the median time per page: a dashed line through the origin, its figure top left
		const trend = make('g', {class: 'trend', opacity: 0}, svg);
		media.forEach((medium, at) =>
		{
			const last = medium.runs[medium.runs.length - 1];
			const usPerPage = medium.summary?.usPerPage ?? last.ms * 1000 / last.pages;
			const end = Math.min(pageSpan, msSpan * 1000 / usPerPage);
			make('line', {x1: x(0), y1: y(0), x2: x(end), y2: y(end * usPerPage / 1000), class: `trend-line ${medium.mode}`}, trend);
			// right of the time axis' own labels
			make('text', {x: x(pageStep * 0.6), y: y(msSpan * 0.92) + (media.length - 1 - at) * 30, class: `trend-label ${medium.mode}`}, trend).textContent = Deck.t('{medium}: about {us} µs a page', {medium: medium.name, us: Deck.decimal(usPerPage, 1)});
		});
		
		// right: the largest tag's time per medium, the slower one on top, counting as its bars grow
		const largest = maxPages;
		const stampX = labelWidth + chartWidth + 40 + stampWidth / 2;
		const stamps = [...media].reverse().map((medium, row) =>
		{
			const base = middle - 70 + row * 140;
			const value = make('text', {x: stampX, y: base, class: `stamp-v ${medium.mode}`}, svg);
			make('text', {x: stampX, y: base + 38, class: 'stamp-l'}, svg).textContent = Deck.t(medium.mode === 'disk' ? 'ON DISK' : 'IN MEMORY');
			
			return {value, run: medium.runs.find((run) => run.pages === largest)};
		});
		make('text', {x: stampX, y: middle + 150, class: 'stamp-l'}, svg).textContent = Deck.t('{n} PAGES', {n: thousands(largest)});
		
		const total = maxMs;
		const startedAt = performance.now();
		const draw = () =>
		{
			const now = (performance.now() - startedAt) * pace;
			for(const bar of bars)
			{
				const grown = Math.min(bar.run.ms, now);
				bar.rect.setAttribute('y', String(y(grown)));
				bar.rect.setAttribute('height', String(bottom - y(grown)));
				bar.label.setAttribute('opacity', grown >= bar.run.ms ? '1' : '0');
			}
			for(const stamp of stamps)
			{
				stamp.value.textContent = duration(Math.min(stamp.run?.ms ?? 0, now));
			}
			trend.setAttribute('opacity', now >= total ? '1' : '0');
			
			if(now < total)
			{
				frame = requestAnimationFrame(draw);
			}
		};
		
		frame = requestAnimationFrame(draw);
	};
	
	return {play, reset, scale: 10};
});

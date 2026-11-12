/*	Forgetting one tag in a file cache, as the cached pages grow.

	x is the number of cached pages with the tag (linear: a straight line
	shows), y the time to forget the tag. A bar per run grows to its time,
	one after the other, in real time (scale 10, with the deck's ×0.1): each bar
	grows for as long as its deletes took; then
	a dashed line through the origin at the median time per page. */

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
	
	const play = (events, pace = 0.5) =>
	{
		reset();
		const segment = events.find((event) => event.type === 'segment');
		const summary = events.find((event) => event.type === 'summary');
		const runs = events.filter((event) => event.type === 'forgotten').sort((a, b) => a.pages - b.pages);
		if(segment === undefined || runs.length === 0)
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
		
		const maxPages = Math.max(...runs.map((run) => run.pages));
		const pageStep = [1000, 2000, 5000, 10000, 20000, 25000, 50000, 100000].find((candidate) => maxPages / candidate <= 6) ?? 200000;
		// half a step past the largest run: room for its bar, no empty tick after it
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
		const middle = (top + bottom) / 2;
		make('text', {x: labelWidth - 16, y: middle, transform: `rotate(-90 ${labelWidth - 16} ${middle})`, class: 'axis-title vertical'}, axis).textContent = Deck.t('time to forget the tag');
		
		const label = Deck.t(segment.label);
		make('text', {x: 0, y: top + 34, class: 'panel-label'}, svg).textContent = label.split(' (')[0];
		const details = label.includes('(') ? label.slice(label.indexOf('(') + 1, -1).split(' · ') : [];
		[...details, Deck.t('one tag on every page'), Deck.t('one invalidateTags() call')].forEach((line, row) =>
		{
			make('text', {x: 0, y: top + 66 + row * 30, class: 'panel-sub'}, svg).textContent = line;
		});
		
		// a bar per run, grown one after the other
		const barWidth = Math.max(10, Math.min(36, chartWidth / pageSpan * pageStep * 0.3));
		let begins = 0;
		const bars = runs.map((run) =>
		{
			const bar = {
				run,
				begins,
				rect: make('rect', {x: x(run.pages) - barWidth / 2, y: bottom, width: barWidth, height: 0, class: 'bar'}, svg),
				label: make('text', {x: x(run.pages), y: y(run.ms) - 12, class: 'bar-label', opacity: 0}, svg),
			};
			bar.label.textContent = duration(run.ms);
			// the next bar starts when this one is done, a moment later
			begins+= run.ms + maxMs * 0.15;
			
			return bar;
		});
		const total = begins;
		
		// the trend: the median time per page, through the origin
		const usPerPage = summary?.usPerPage ?? runs[runs.length - 1].ms * 1000 / runs[runs.length - 1].pages;
		const trend = make('g', {class: 'trend', opacity: 0}, svg);
		const trendEnd = Math.min(pageSpan, msSpan * 1000 / usPerPage);
		make('line', {x1: x(0), y1: y(0), x2: x(trendEnd), y2: y(trendEnd * usPerPage / 1000), class: 'trend-line'}, trend);
		make('text', {x: x(pageSpan * 0.03), y: y(msSpan * 0.9), class: 'trend-label'}, trend).textContent = Deck.t('about {us} µs a page: a straight line', {us: Deck.decimal(usPerPage, 1)});
		
		const last = runs[runs.length - 1];
		const stampX = labelWidth + chartWidth + 40 + stampWidth / 2;
		const value = make('text', {x: stampX, y: middle + 8, class: 'stamp-v bad'}, svg);
		make('text', {x: stampX, y: middle + 46, class: 'stamp-l'}, svg).textContent = Deck.t('{n} PAGES', {n: thousands(last.pages)});
		
		const startedAt = performance.now();
		const draw = () =>
		{
			const now = (performance.now() - startedAt) * pace;
			let shown = 0;
			for(const bar of bars)
			{
				const grown = Math.max(0, Math.min(bar.run.ms, now - bar.begins));
				bar.rect.setAttribute('y', String(y(grown)));
				bar.rect.setAttribute('height', String(bottom - y(grown)));
				bar.label.setAttribute('opacity', grown >= bar.run.ms ? '1' : '0');
				shown = grown > 0 ? grown : shown;
			}
			value.textContent = duration(now >= total ? last.ms : shown);
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

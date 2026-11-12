/*	More visitors than the database has room for.

	A panel per mode, one time axis and one wait scale for both. A dot per
	visit at (its answer, how long it waited):

	red    it asked the database (and queued for one of its slots)
	mint   the cache answered

	Behind the dots, light: the visitors on the site, as they join. Drawn at
	×0.5 (scale 5, with the deck's ×0.1). The svg borrows the ban chart's
	styles. */

Deck.register('load', (element) =>
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
	
	/** the events of one segment (one mode): who joined when, every visit */
	const segmentsOf = (events, modes) =>
	{
		const segments = [];
		for(const event of events)
		{
			if(event.type === 'segment' && (modes.length === 0 || modes.includes(event.mode)))
			{
				segments.push({...event, joined: [], asked: new Map(), visits: [], summary: null});
			}
			
			const segment = segments.find((candidate) => candidate.mode === event.mode);
			if(segment === undefined)
			{
				continue;
			}
			
			if(event.type === 'joined')
			{
				segment.joined.push(event.t);
			}
			if(event.type === 'request')
			{
				segment.asked.set(`${event.w}:${event.visit}`, event.t);
			}
			if(event.type === 'respond')
			{
				const asked = segment.asked.get(`${event.w}:${event.visit}`) ?? event.t;
				segment.visits.push({at: event.t, wait: event.t - asked, db: event.db});
			}
			if(event.type === 'summary')
			{
				segment.summary = event;
			}
		}
		
		segments.forEach((segment) => segment.joined.sort((a, b) => a - b));
		
		return segments;
	};
	
	const duration = (ms) => ms >= 10 ? `${Math.round(ms)} ms` : `${Deck.decimal(ms, 1)} ms`;
	
	/** a number with as many decimals as it has (1.25), a decimal comma in Polish */
	const figure = (value) => Deck.decimal(value, (String(value).split('.')[1] ?? '').length);
	
	const play = (events, pace = 0.5) =>
	{
		reset();
		const modes = (element.dataset.modes ?? '').split(',').filter(Boolean);
		const segments = segmentsOf(events, modes);
		if(segments.length === 0)
		{
			stage.textContent = Deck.t('This run has no such mode.');
			
			return;
		}
		
		const width = stage.clientWidth || 1680;
		const height = stage.clientHeight || 600;
		const labelWidth = 440;
		const stampWidth = 300;
		const chartWidth = width - labelWidth - stampWidth - 40;
		const gap = 34;
		const axisHeight = 36;
		const panelHeight = (height - gap * (segments.length - 1) - axisHeight) / segments.length;
		
		const span = Math.max(...segments.map((segment) => segment.endMs ?? 0), ...segments.flatMap((segment) => segment.visits.map((visit) => visit.at)));
		const step = [250, 500, 1000].find((candidate) => span / candidate <= 8) ?? 2000;
		const x = (t) => labelWidth + Math.max(0, Math.min(span, t)) / span * chartWidth;
		const maxWait = Math.max(...segments.flatMap((segment) => segment.visits.map((visit) => visit.wait)));
		const waitStep = [10, 20, 25, 50, 100, 200, 500].find((candidate) => maxWait * 1.1 / candidate <= 4) ?? 1000;
		const waitSpan = Math.ceil(maxWait * 1.1 / waitStep) * waitStep;
		const maxVisitors = Math.max(...segments.map((segment) => segment.joined.length));
		
		const svg = make('svg', {width, height, viewBox: `0 0 ${width} ${height}`, class: 'ban load'}, stage);
		
		const axis = make('g', {class: 'axis'}, svg);
		for(let t = 0; t <= span; t+= step)
		{
			make('line', {x1: x(t), x2: x(t), y1: 0, y2: height - axisHeight + 6, class: 'tick'}, axis);
			make('text', {x: x(t), y: height - 8, class: 'tick-label'}, axis).textContent = t >= 1000 ? `${figure(t / 1000)} s` : `${t} ms`;
		}
		make('text', {x: labelWidth - 40, y: height - 8, class: 'axis-title'}, axis).textContent = Deck.t('time →');
		const cursor = make('line', {x1: x(0), x2: x(0), y1: 0, y2: height - axisHeight, class: 'cursor'}, svg);
		
		const panels = segments.map((segment, at) =>
		{
			const top = at * (panelHeight + gap);
			const bottom = top + panelHeight;
			const y = (wait) => bottom - Math.min(waitSpan, wait) / waitSpan * (panelHeight - 10);
			const group = make('g', {class: `panel mode-${segment.mode}`}, svg);
			const middle = top + panelHeight / 2;
			
			// the visitors on the site, behind everything: a step up as each one joins
			let path = `M ${x(0)} ${bottom}`;
			segment.joined.forEach((t, count) =>
			{
				const level = bottom - (count + 1) / maxVisitors * (panelHeight - 10);
				const previous = bottom - count / maxVisitors * (panelHeight - 10);
				path+= ` L ${x(t)} ${previous} L ${x(t)} ${level}`;
			});
			path+= ` L ${x(span)} ${bottom - segment.joined.length / maxVisitors * (panelHeight - 10)} L ${x(span)} ${bottom} Z`;
			make('path', {d: path, class: 'visitors'}, group);
			make('text', {x: x(span) - 10, y: bottom - segment.joined.length / maxVisitors * (panelHeight - 10) + 24, class: 'visitors-label'}, group).textContent = Deck.t('{n} visitors', {n: segment.joined.length});
			
			for(let wait = waitStep; wait < waitSpan; wait+= waitStep)
			{
				make('line', {x1: labelWidth, x2: labelWidth + chartWidth, y1: y(wait), y2: y(wait), class: 'tick'}, group);
				make('text', {x: labelWidth + 8, y: y(wait) - 6, class: 'tick-label y'}, group).textContent = `${wait} ms`;
			}
			make('line', {x1: labelWidth, x2: labelWidth + chartWidth, y1: bottom, y2: bottom, class: 'floor'}, group);
			make('text', {x: labelWidth - 16, y: middle, transform: `rotate(-90 ${labelWidth - 16} ${middle})`, class: 'axis-title vertical'}, group).textContent = Deck.t('a visit’s wait');
			
			const label = Deck.t(segment.label);
			make('text', {x: 0, y: top + 34, class: 'panel-label'}, group).textContent = label.split(' (')[0];
			const details = label.includes('(') ? label.slice(label.indexOf('(') + 1, -1) : '';
			[
				details,
				Deck.t('the database answers {n} at a time', {n: segment.slots}),
				Deck.t('a query takes {min}–{max} ms', {min: segment.queryMinMs, max: segment.queryMaxMs}),
			].filter(Boolean).forEach((line, row) =>
			{
				make('text', {x: 0, y: top + 66 + row * 30, class: 'panel-sub'}, group).textContent = line;
			});
			
			const dots = segment.visits.map((visit) => ({
				visit,
				node: make('circle', {cx: x(visit.at), cy: y(visit.wait), r: 3.5, class: visit.db ? 'visit db' : 'visit', opacity: 0}, group),
			}));
			
			// the stamp: the average wait, what a visitor gets; the longest beside it (the cached run's is its
			// first moment's stampede)
			const stampX = labelWidth + chartWidth + 40 + stampWidth / 2;
			const average = make('text', {x: stampX, y: middle - 6, class: `stamp-v ${segment.mode === 'none' ? 'bad' : 'good'}`}, group);
			make('text', {x: stampX, y: middle + 32, class: 'stamp-l'}, group).textContent = Deck.t('AVERAGE WAIT');
			const longest = make('text', {x: stampX, y: middle + 64, class: 'stamp-l'}, group);
			const queries = make('text', {x: stampX, y: middle + 92, class: 'stamp-l'}, group);
			
			return {segment, dots, average, longest, queries};
		});
		
		// the legend, bottom left under the last panel's labels
		const legend = make('g', {class: 'legend'}, svg);
		[
			['db', Deck.t('a visit that asked the database')],
			['cached', Deck.t('a visit the cache answered')],
			['visitors', Deck.t('visitors on the site')],
		].forEach(([kind, text], row) =>
		{
			const ly = height - axisHeight - 76 + row * 28;
			if(kind === 'visitors')
			{
				make('rect', {x: 0, y: ly - 14, width: 34, height: 16, class: 'visitors'}, legend);
			}
			else
			{
				make('circle', {cx: 17, cy: ly - 6, r: 6, class: kind === 'db' ? 'visit db' : 'visit'}, legend);
			}
			make('text', {x: 50, y: ly, class: 'legend-label'}, legend).textContent = text;
		});
		
		const startedAt = performance.now();
		const draw = () =>
		{
			const now = (performance.now() - startedAt) * pace;
			cursor.setAttribute('x1', String(x(now)));
			cursor.setAttribute('x2', String(x(now)));
			
			for(const panel of panels)
			{
				let longest = 0;
				let total = 0;
				let visits = 0;
				let queries = 0;
				for(const dot of panel.dots)
				{
					const shown = now >= dot.visit.at;
					dot.node.setAttribute('opacity', shown ? '1' : '0');
					if(shown)
					{
						longest = Math.max(longest, dot.visit.wait);
						total+= dot.visit.wait;
						visits++;
						queries+= dot.visit.db ? 1 : 0;
					}
				}
				panel.average.textContent = duration(visits > 0 ? total / visits : 0);
				panel.longest.textContent = Deck.t('LONGEST: {d}', {d: duration(longest).toUpperCase()});
				panel.queries.textContent = Deck.t('DATABASE QUERIES: {n}', {n: queries});
			}
			
			if(now < span)
			{
				frame = requestAnimationFrame(draw);
			}
		};
		
		frame = requestAnimationFrame(draw);
	};
	
	return {play, reset, scale: 5};
});

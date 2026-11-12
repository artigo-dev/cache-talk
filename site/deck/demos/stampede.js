/*	The stampede: a lane per request, one panel per lock.

	yellow  the request waits
	red     it computes the value at the origin
	mint    it is answered

	The stamp on the right counts the origin's calls as they happen, and shows the run's last answer when it is over. Time runs
	in slow motion (×0.1 by default: the 300 ms of the origin take 3 s), since
	what differs between the locks is a few milliseconds against a hundred. */

Deck.register('stampede', (element) =>
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
	
	/** the events of one segment (one lock), by worker */
	const segmentsOf = (events, modes) =>
	{
		const segments = [];
		for(const event of events)
		{
			if(event.type === 'segment' && (modes.length === 0 || modes.includes(event.mode)))
			{
				segments.push({...event, workers: new Map(), summary: null});
			}
			
			const segment = segments.find((candidate) => candidate.mode === event.mode);
			if(segment === undefined)
			{
				continue;
			}
			
			if(event.type === 'summary')
			{
				segment.summary = event;
			}
			
			if(['request', 'origin.start', 'origin.end', 'respond'].includes(event.type))
			{
				const lane = segment.workers.get(event.w) ?? {w: event.w, host: event.host ?? 0};
				lane[event.type] = event.t;
				if(event.host !== undefined)
				{
					lane.host = event.host;
				}
				
				segment.workers.set(event.w, lane);
			}
		}
		
		return segments;
	};
	
	const play = (events, speed = 0.1) =>
	{
		reset();
		const modes = (element.dataset.modes ?? '').split(',').filter(Boolean);
		const segments = segmentsOf(events, modes);
		if(segments.length === 0)
		{
			stage.textContent = Deck.t('This run has no such segment.');
			
			return;
		}
		
		const width = stage.clientWidth || 1680;
		const height = stage.clientHeight || 760;
		const labelWidth = 440;
		const stampWidth = 300;
		const lanesWidth = width - labelWidth - stampWidth - 40;
		const gap = 28;
		const panelHeight = (height - gap * (segments.length - 1) - 40) / segments.length;
		const end = Math.max(...segments.flatMap((segment) => [...segment.workers.values()].map((lane) => lane.respond ?? 0)));
		// a tick every 100 ms, or wider steps for a long run: never more than eight
		const step = [100, 200, 250, 500, 1000, 2000].find((candidate) => (end + 40) / candidate <= 8) ?? 5000;
		const span = Math.ceil((end + 40) / step) * step;
		const x = (t) => labelWidth + Math.max(0, Math.min(span, t)) / span * lanesWidth;
		
		const svg = make('svg', {width, height, viewBox: `0 0 ${width} ${height}`, class: 'stampede'}, stage);
		
		// the time axis, under every panel
		const axis = make('g', {class: 'axis'}, svg);
		for(let t = 0; t <= span; t+= step)
		{
			make('line', {x1: x(t), x2: x(t), y1: 0, y2: height - 34, class: 'tick'}, axis);
			make('text', {x: x(t), y: height - 8, class: 'tick-label'}, axis).textContent = `${t} ms`;
		}
		make('text', {x: labelWidth - 40, y: height - 8, class: 'axis-title'}, axis).textContent = Deck.t('time since the requests arrived →');
		
		const cursor = make('line', {x1: labelWidth, x2: labelWidth, y1: 0, y2: height - 34, class: 'cursor'}, svg);
		
		const panels = segments.map((segment, at) =>
		{
			const top = at * (panelHeight + gap);
			const lanes = [...segment.workers.values()].sort((a, b) => a.host - b.host || a.w - b.w);
			const row = panelHeight / Math.max(1, lanes.length);
			const bar = Math.max(3, Math.min(14, row * 0.62));
			const group = make('g', {class: `panel mode-${segment.mode}`}, svg);
			make('text', {x: labelWidth - 16, y: top + panelHeight / 2, transform: `rotate(-90 ${labelWidth - 16} ${top + panelHeight / 2})`, class: 'axis-title vertical'}, group).textContent = Deck.t('a row per request');
			
			const label = Deck.t(segment.label);
			make('text', {x: 0, y: top + 34, class: 'panel-label'}, group).textContent = label.split(' (')[0];
			// the label's details ("flock, per host · checks every 100 ms"): one line each, then the run's size
			const details = label.includes('(') ? label.slice(label.indexOf('(') + 1, -1).split(' · ') : [];
			const size = segment.hosts === 1
				? Deck.t('{n} requests · {hosts} server', {n: lanes.length, hosts: segment.hosts})
				: Deck.t('{n} requests · {hosts} servers', {n: lanes.length, hosts: segment.hosts});
			[...details, size].forEach((line, row) =>
			{
				make('text', {x: 0, y: top + 66 + row * 30, class: 'panel-sub'}, group).textContent = line;
			});
			
			const shapes = lanes.map((lane, position) =>
			{
				const y = top + position * row + (row - bar) / 2;
				const previous = lanes[position - 1];
				if(previous && previous.host !== lane.host)
				{
					make('line', {x1: labelWidth, x2: labelWidth + lanesWidth, y1: top + position * row, y2: top + position * row, class: 'host-split'}, group);
				}
				
				return {
					lane,
					track: make('rect', {x: x(0), y: y + bar / 2 - 1, width: 0, height: 2, class: 'track'}, group),
					// waiting is not work: a thin line through the lane; the thick bar is the database's work
					wait: make('rect', {x: x(lane.request ?? 0), y: y + bar * 0.38, width: 0, height: Math.max(2, bar * 0.24), class: 'wait'}, group),
					work: lane['origin.start'] === undefined ? null : make('rect', {x: x(lane['origin.start']), y, width: 0, height: bar, class: 'work'}, group),
					done: make('circle', {cx: x(lane.respond ?? 0), cy: y + bar / 2, r: Math.max(3, bar * 0.55), class: 'done', opacity: 0}, group),
				};
			});
			
			const stampX = labelWidth + lanesWidth + 40 + stampWidth / 2;
			const calls = make('text', {x: stampX, y: top + panelHeight / 2 + 10, class: `stamp-v ${segment.mode === 'memolock' ? 'good' : 'bad'}`}, group);
			make('text', {x: stampX, y: top + panelHeight / 2 + 50, class: 'stamp-l'}, group).textContent = Deck.t('ORIGIN CALLS');
			const behind = make('text', {x: stampX, y: top + panelHeight / 2 + 82, class: 'stamp-l'}, group);
			
			return {segment, shapes, calls, behind};
		});
		
		// the legend, bottom left under the last panel's labels: the shapes of the chart, in words
		const legend = make('g', {class: 'legend'}, svg);
		[
			['work', Deck.t('working: asking the database')],
			['wait', Deck.t('waiting: not asking the database')],
			['done', Deck.t('the user sees the result')],
		].forEach(([kind, text], row) =>
		{
			const y = height - 40 - 70 + row * 30;
			if(kind === 'done')
			{
				make('circle', {cx: 17, cy: y - 6, r: 7, class: 'done'}, legend);
			}
			else
			{
				make('rect', {x: 0, y: kind === 'work' ? y - 13 : y - 8, width: 34, height: kind === 'work' ? 14 : 3, class: kind}, legend);
			}
			make('text', {x: 50, y, class: 'legend-label'}, legend).textContent = text;
		});
		
		const startedAt = performance.now();
		const draw = () =>
		{
			const now = (performance.now() - startedAt) * speed;
			cursor.setAttribute('x1', String(x(now)));
			cursor.setAttribute('x2', String(x(now)));
			
			let running = false;
			for(const panel of panels)
			{
				let started = 0;
				for(const {lane, track, wait, work, done} of panel.shapes)
				{
					const answered = lane.respond ?? Infinity;
					track.setAttribute('width', String(Math.max(0, x(Math.min(now, answered)) - x(0))));
					
					// waiting: from the request until it is answered, except while it computes itself
					const waitEnd = Math.min(now, answered);
					wait.setAttribute('width', String(Math.max(0, x(waitEnd) - x(lane.request ?? 0))));
					
					if(work)
					{
						const from = lane['origin.start'];
						const to = Math.min(now, lane['origin.end'] ?? now);
						work.setAttribute('width', String(now < from ? 0 : Math.max(2, x(to) - x(from))));
						started+= now >= from ? 1 : 0;
					}
					
					done.setAttribute('opacity', now >= answered ? '1' : '0');
					running = running || now < answered;
				}
				
				panel.calls.textContent = String(started);
				const summary = panel.segment.summary;
				// when the run is over: its last answer, the number to compare between the panels (a lock that
				// halves the work can still answer later than no lock at all)
				panel.behind.textContent = summary && now >= span * 0.98
					? Deck.t('LAST ANSWER {n} MS', {n: Math.round(summary.latencyMax)})
					: '';
			}
			
			if(running || now < span)
			{
				frame = requestAnimationFrame(draw);
			}
			else
			{
				for(const panel of panels)
				{
					panel.calls.textContent = String(panel.segment.summary?.originCalls ?? panel.calls.textContent);
				}
			}
		};
		
		frame = requestAnimationFrame(draw);
	};
	
	return {play, reset};
});

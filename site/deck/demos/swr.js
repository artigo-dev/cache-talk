/*	Visitors around the moment a cached page ends: it expires, or the editor
	saves. A lane per visitor, one panel per mode, the stampede's look:

	yellow       the visitor waits
	red          it builds the page, and its visitor waits for it
	red outline  it builds the page after its own answer: nobody waits
	mint dot     answered with a fresh page
	white dot    answered with the previous page

	Time on x is counted from the moment (the dashed line), so a slide's two
	panels line up. The interesting part is under a second; it is drawn at
	×0.25 (scale 2.5, with the deck's ×0.1). The svg borrows the stampede's
	styles. */

Deck.register('swr', (element) =>
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
	
	/** the events of one segment (one mode): the moment, a lane per visitor, the summary */
	const segmentsOf = (events, modes) =>
	{
		const segments = [];
		for(const event of events)
		{
			if(event.type === 'segment' && (modes.length === 0 || modes.includes(event.mode)))
			{
				segments.push({...event, moment: null, lanes: new Map(), summary: null});
			}
			
			const segment = segments.find((candidate) => candidate.mode === event.mode);
			if(segment === undefined)
			{
				continue;
			}
			
			if(event.type === 'expires')
			{
				segment.moment = event.at;
			}
			if(event.type === 'edit')
			{
				segment.moment = event.t;
			}
			if(event.type === 'summary')
			{
				segment.summary = event;
			}
			// worker 0 built the page before the moment: not a visitor
			if(['request', 'respond', 'origin.start', 'origin.end'].includes(event.type) && event.w > 0)
			{
				const lane = segment.lanes.get(event.w) ?? {w: event.w};
				lane[event.type] = event.t;
				if(event.type === 'respond')
				{
					lane.previous = event.previous;
				}
				
				segment.lanes.set(event.w, lane);
			}
		}
		
		return segments.filter((segment) => segment.moment !== null);
	};
	
	const signed = (ms) => ms === 0 ? '0' : `${ms > 0 ? '+' : '−'}${Math.abs(ms)} ms`;
	
	/** a wait in the stamp: the number big, its unit half the size */
	const stamp = (node, ms) =>
	{
		node.textContent = ms >= 10 ? String(Math.round(ms)) : Deck.decimal(ms, 1);
		make('tspan', {class: 'unit'}, node).textContent = ' ms';
	};
	
	const play = (events, pace = 0.25) =>
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
		const lanesWidth = width - labelWidth - stampWidth - 40;
		const gap = 28;
		const axisHeight = 34;
		const panelHeight = (height - gap * (segments.length - 1) - axisHeight) / segments.length;
		
		// one axis for the panels, counted from the moment: from the first visitor to the last build
		const relative = (segment, t) => t - segment.moment;
		const times = segments.flatMap((segment) => [...segment.lanes.values()].flatMap((lane) =>
			[lane.request, lane.respond, lane['origin.end']].filter((t) => t !== undefined).map((t) => relative(segment, t))));
		const lo = Math.min(...times) - 40;
		const hi = Math.max(...times) + 40;
		const step = [100, 200, 250, 500].find((candidate) => (hi - lo) / candidate <= 10) ?? 1000;
		const x = (t) => labelWidth + (Math.max(lo, Math.min(hi, t)) - lo) / (hi - lo) * lanesWidth;
		
		const svg = make('svg', {width, height, viewBox: `0 0 ${width} ${height}`, class: 'stampede swr'}, stage);
		
		const axis = make('g', {class: 'axis'}, svg);
		for(let t = Math.ceil(lo / step) * step; t <= hi; t+= step)
		{
			make('line', {x1: x(t), x2: x(t), y1: 0, y2: height - axisHeight, class: 'tick'}, axis);
			make('text', {x: x(t), y: height - 8, class: 'tick-label'}, axis).textContent = signed(t);
		}
		const edits = segments.some((segment) => ['edit', 'soft'].includes(segment.mode));
		make('text', {x: labelWidth - 40, y: height - 8, class: 'axis-title'}, axis).textContent = edits ? Deck.t('time since the editor saved →') : Deck.t('time since the page expired →');
		
		const cursor = make('line', {x1: x(lo), x2: x(lo), y1: 0, y2: height - axisHeight, class: 'cursor'}, svg);
		
		const panels = segments.map((segment, at) =>
		{
			const top = at * (panelHeight + gap);
			const lanes = [...segment.lanes.values()].sort((a, b) => a.request - b.request);
			const row = (panelHeight - 30) / Math.max(1, lanes.length);
			const bar = Math.max(3, Math.min(12, row * 0.6));
			const group = make('g', {class: `panel mode-${segment.mode}`}, svg);
			const middle = top + panelHeight / 2;
			make('text', {x: labelWidth - 16, y: middle, transform: `rotate(-90 ${labelWidth - 16} ${middle})`, class: 'axis-title vertical'}, group).textContent = Deck.t('a row per visitor');
			
			const label = Deck.t(segment.label);
			make('text', {x: 0, y: top + 34, class: 'panel-label'}, group).textContent = label.split(' (')[0];
			const details = label.includes('(') ? label.slice(label.indexOf('(') + 1, -1) : '';
			[details, Deck.t('{n} visitors · a build takes {ms} ms', {n: lanes.length, ms: segment.originMs})].filter(Boolean).forEach((line, row) =>
			{
				make('text', {x: 0, y: top + 66 + row * 30, class: 'panel-sub'}, group).textContent = line;
			});
			
			// the moment: the page expires, or the editor saves
			make('line', {x1: x(0), x2: x(0), y1: top, y2: top + panelHeight, class: 'moment'}, group);
			make('text', {x: x(0) + 8, y: top + 18, class: 'moment-label'}, group).textContent = edits ? Deck.t('the editor saves') : Deck.t('the page expires');
			
			const shapes = lanes.map((lane, position) =>
			{
				const y = top + 30 + position * row + (row - bar) / 2;
				const asked = relative(segment, lane.request);
				const answered = relative(segment, lane.respond);
				const built = lane['origin.start'] === undefined ? null : {
					from: relative(segment, lane['origin.start']),
					to: relative(segment, lane['origin.end'] ?? lane['origin.start']),
				};
				// built after its own answer: the refresh, nobody waits for it
				const after = built !== null && built.from >= answered;
				
				return {
					asked,
					answered,
					built,
					previous: lane.previous === true,
					wait: make('rect', {x: x(asked), y: y + bar * 0.38, width: 0, height: Math.max(2, bar * 0.24), class: 'wait'}, group),
					work: built === null ? null : make('rect', {x: x(built.from), y, width: 0, height: bar, class: after ? 'work refresh' : 'work'}, group),
					done: make('circle', {cx: x(answered), cy: y + bar / 2, r: Math.max(3, bar * 0.55), class: lane.previous ? 'done previous' : 'done', opacity: 0}, group),
				};
			});
			
			const stampX = labelWidth + lanesWidth + 40 + stampWidth / 2;
			const longest = Math.max(0, ...shapes.map((shape) => shape.answered - shape.asked));
			const value = make('text', {x: stampX, y: middle + 2, class: `stamp-v ${longest > 100 ? 'bad' : 'good'}`}, group);
			make('text', {x: stampX, y: middle + 40, class: 'stamp-l'}, group).textContent = Deck.t('LONGEST WAIT');
			const rebuilds = make('text', {x: stampX, y: middle + 72, class: 'stamp-l'}, group);
			const previous = make('text', {x: stampX, y: middle + 100, class: 'stamp-l'}, group);
			
			return {segment, shapes, value, rebuilds, previous, longest};
		});
		
		// the legend, bottom left under the last panel's labels
		const legend = make('g', {class: 'legend'}, svg);
		[
			['work', Deck.t('building: its visitor waits')],
			['refresh', Deck.t('building after the answer')],
			['wait', Deck.t('waiting')],
			['done', Deck.t('answered: a fresh page')],
			['previous', Deck.t('answered: the previous page')],
		].forEach(([kind, text], row) =>
		{
			const ly = height - axisHeight - 132 + row * 28;
			if(kind === 'done' || kind === 'previous')
			{
				make('circle', {cx: 17, cy: ly - 6, r: 7, class: kind === 'done' ? 'done' : 'done previous'}, legend);
			}
			else
			{
				make('rect', {x: 0, y: kind === 'wait' ? ly - 8 : ly - 13, width: 34, height: kind === 'wait' ? 3 : 12, class: kind === 'refresh' ? 'work refresh' : kind}, legend);
			}
			make('text', {x: 50, y: ly, class: 'legend-label'}, legend).textContent = text;
		});
		
		const startedAt = performance.now();
		const draw = () =>
		{
			const now = lo + (performance.now() - startedAt) * pace;
			cursor.setAttribute('x1', String(x(now)));
			cursor.setAttribute('x2', String(x(now)));
			
			for(const panel of panels)
			{
				let waited = 0;
				let built = 0;
				let previous = 0;
				for(const shape of panel.shapes)
				{
					const until = Math.min(now, shape.answered);
					shape.wait.setAttribute('width', String(now < shape.asked ? 0 : Math.max(0, x(until) - x(shape.asked))));
					waited = now >= shape.asked ? Math.max(waited, until - shape.asked) : waited;
					
					if(shape.work)
					{
						const to = Math.min(now, shape.built.to);
						shape.work.setAttribute('width', String(now < shape.built.from ? 0 : Math.max(2, x(to) - x(shape.built.from))));
						built+= now >= shape.built.from ? 1 : 0;
					}
					
					const answered = now >= shape.answered;
					shape.done.setAttribute('opacity', answered ? '1' : '0');
					previous+= answered && shape.previous ? 1 : 0;
				}
				
				stamp(panel.value, now >= hi ? panel.longest : waited);
				panel.rebuilds.textContent = built === 1 ? Deck.t('{n} REBUILD', {n: built}) : Deck.t('{n} REBUILDS', {n: built});
				panel.previous.textContent = Deck.t('PREVIOUS PAGE: {n}', {n: previous});
			}
			
			if(now < hi)
			{
				frame = requestAnimationFrame(draw);
			}
		};
		
		frame = requestAnimationFrame(draw);
	};
	
	return {play, reset, scale: 2.5};
});

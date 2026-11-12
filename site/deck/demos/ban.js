/*	Forgetting one tag on many cached pages, and the visitors meanwhile.

	A panel per store, a row per visitor: each one arrives at its moment and
	reads a cached page.

	yellow  the visitor waits for Redis
	mint    the visitor has its answer

	While Redis is busy with one big job, every visitor who arrives waits
	until it is done: their lines end at the same moment. The shaded span is
	the tag being forgotten, and above it the classic backend's steps are
	named. One time axis for every panel.

	Real time by default (scale 10: the deck's ×0.1 slow motion is the
	stampede's), since what differs is a second against a few milliseconds. */

Deck.register('ban', (element) =>
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
	
	/** a step's name in lines of at most `fit` characters, broken between words (never inside
	    1 000 000), or on one line when it needs more than two */
	const wrap = (name, fit) =>
	{
		const lines = [];
		for(const word of name.split(/(?<!\d) | (?!\d)/))
		{
			const last = lines.length - 1;
			if(last >= 0 && lines[last].length + 1 + word.length <= fit)
			{
				lines[last]+= ` ${word}`;
			}
			else
			{
				lines.push(word);
			}
		}
		
		return lines.length <= 2 && lines.every((line) => line.length <= fit) ? lines : [name];
	};
	
	/** a step's name in the language on screen: the deleting step names its pages, a number that differs
	    between runs */
	const stepName = (name) =>
	{
		const deleting = /^deleting (.+) pages, in one go$/.exec(name);
		
		return deleting === null ? Deck.t(name) : Deck.t('deleting {n} pages, in one go', {n: deleting[1]});
	};
	
	const reset = () =>
	{
		cancelAnimationFrame(frame);
		stage.innerHTML = '';
	};
	
	/** the events of one segment (one store): the ban's span, its steps, the visitors, the summary */
	const segmentsOf = (events, stores) =>
	{
		const segments = [];
		for(const event of events)
		{
			if(event.type === 'segment' && (stores.length === 0 || stores.includes(event.store)))
			{
				segments.push({...event, start: null, end: null, phases: [], visitors: new Map(), summary: null});
			}
			
			const segment = segments.find((candidate) => candidate.store === event.store);
			if(segment === undefined)
			{
				continue;
			}
			
			if(event.type === 'ban.start')
			{
				segment.start = event.t;
			}
			if(event.type === 'ban.end')
			{
				segment.end = event.t;
			}
			if(event.type === 'phase.start')
			{
				segment.phases.push({name: event.name, start: event.t, end: null});
			}
			if(event.type === 'phase.end' && segment.phases.length > 0)
			{
				segment.phases[segment.phases.length - 1].end = event.t;
			}
			if(event.type === 'request' || event.type === 'respond')
			{
				const visitor = segment.visitors.get(event.w) ?? {w: event.w};
				visitor[event.type] = event.t;
				segment.visitors.set(event.w, visitor);
			}
			if(event.type === 'summary')
			{
				segment.summary = event;
			}
		}
		
		return segments.filter((segment) => segment.start !== null);
	};
	
	const duration = (ms) => ms >= 1000 ? `${Deck.decimal(ms / 1000, 1)} s` : ms >= 10 ? `${Math.round(ms)} ms` : `${Deck.decimal(ms, 1)} ms`;
	
	/** a number with as many decimals as it has (1.25), a decimal comma in Polish */
	const figure = (value) => Deck.decimal(value, (String(value).split('.')[1] ?? '').length);
	
	const play = (events, pace = 1) =>
	{
		reset();
		const stores = (element.dataset.stores ?? '').split(',').filter(Boolean);
		const segments = segmentsOf(events, stores);
		if(segments.length === 0)
		{
			stage.textContent = Deck.t('This run has no such store.');
			
			return;
		}
		
		const width = stage.clientWidth || 1680;
		const height = stage.clientHeight || 600;
		const labelWidth = 440;
		const stampWidth = 300;
		const chartWidth = width - labelWidth - stampWidth - 40;
		const gap = 34;
		const axisHeight = 40;
		const stepsHeight = 34;
		const panelHeight = (height - gap * (segments.length - 1) - axisHeight) / segments.length;
		
		// one time axis: from the ban's start to the last answer, and a little after
		const lead = 20;
		const lastAnswer = (segment) => Math.max(segment.end ?? 0, ...[...segment.visitors.values()].map((visitor) => visitor.respond ?? 0)) - segment.start;
		const end = Math.max(...segments.map(lastAnswer));
		const step = [50, 100, 200, 250, 500, 1000, 2000].find((candidate) => (end + lead) / candidate <= 8) ?? 5000;
		const span = Math.ceil((end + lead) / step) * step;
		const x = (t) => labelWidth + Math.max(0, Math.min(span, t + lead)) / (span + lead) * chartWidth;
		
		const svg = make('svg', {width, height, viewBox: `0 0 ${width} ${height}`, class: 'ban'}, stage);
		const axis = make('g', {class: 'axis'}, svg);
		for(let t = 0; t <= span; t+= step)
		{
			make('line', {x1: x(t), x2: x(t), y1: 0, y2: height - axisHeight + 6, class: 'tick'}, axis);
			make('text', {x: x(t), y: height - 8, class: 'tick-label'}, axis).textContent = t >= 1000 ? `${figure(t / 1000)} s` : `${t} ms`;
		}
		make('text', {x: labelWidth - 40, y: height - 8, class: 'axis-title'}, axis).textContent = Deck.t('time since forgetting began →');
		const cursor = make('line', {x1: x(0), x2: x(0), y1: 0, y2: height - axisHeight, class: 'cursor'}, svg);
		
		const panels = segments.map((segment, at) =>
		{
			const panelTop = at * (panelHeight + gap);
			const group = make('g', {class: `panel store-${segment.store}`}, svg);
			const middle = panelTop + panelHeight / 2;
			make('text', {x: labelWidth - 16, y: middle, transform: `rotate(-90 ${labelWidth - 16} ${middle})`, class: 'axis-title vertical'}, group).textContent = Deck.t('a row per visitor');
			
			// the store's name; php-library's stores, and a name wider than the space left of the chart, put the
			// last word on a second line
			// (its width from its length, about 16.5 px a letter: measured, it depends on whether the font has
			// loaded yet)
			const label = Deck.t(segment.label);
			const title = make('text', {x: 0, y: panelTop + 34, class: 'panel-label'}, group);
			title.textContent = label.split(' (')[0];
			let titleBelow = 0;
			if((title.textContent.startsWith('php-library ') || title.textContent.length * 16.5 > labelWidth - 36) && title.textContent.includes(' '))
			{
				const words = title.textContent.split(' ');
				const last = words.pop();
				title.textContent = words.join(' ');
				titleBelow = 34;
				make('text', {x: 0, y: panelTop + 34 + titleBelow, class: 'panel-label'}, group).textContent = last;
			}
			
			const details = label.includes('(') ? label.slice(label.indexOf('(') + 1, -1).split(' · ') : [];
			[...details, Deck.t('{n} cached pages · one tag', {n: segment.pages.toLocaleString('en').replace(/,/g, ' ')})].forEach((line, row) =>
			{
				make('text', {x: 0, y: panelTop + titleBelow + 66 + row * 30, class: 'panel-sub'}, group).textContent = line;
			});
			
			const banEnd = (segment.end ?? segment.start) - segment.start;
			const band = make('rect', {x: x(0), y: panelTop, width: 0, height: panelHeight, class: 'band'}, group);
			
			// the classic backend's steps, bracketed and named as they happen; a name longer than its
			// step wraps onto two lines that end before the next step begins; when one does not fit
			// even so (a long time axis), the brackets go alone
			const charWidth = 10.5;
			const done = segment.phases.filter((phase) => phase.end !== null);
			const names = done.map((phase, index) =>
			{
				const left = x(phase.start - segment.start) + 4;
				const next = index + 1 < done.length ? x(done[index + 1].start - segment.start) : labelWidth + chartWidth;
				const fit = Math.floor((next - 12 - left) / charWidth);
				
				return {left, fit, lines: wrap(stepName(phase.name), fit)};
			});
			const named = names.every(({fit, lines}) => lines.every((line) => line.length <= fit));
			const rows = named ? Math.max(0, ...names.map(({lines}) => lines.length)) : 0;
			const phases = done.map((phase, index) =>
			{
				const from = phase.start - segment.start;
				const to = phase.end - segment.start;
				const node = make('g', {class: 'phase', opacity: 0}, group);
				const bracket = make('line', {x1: x(from), x2: x(from), y1: panelTop + 6, y2: panelTop + 6, class: 'phase-line'}, node);
				if(named)
				{
					names[index].lines.forEach((line, at) =>
					{
						make('text', {x: names[index].left, y: panelTop + 24 + at * 20, class: 'phase-label'}, node).textContent = line;
					});
				}
				
				return {from, to, node, bracket};
			});
			
			// a lane per visitor, in the order they arrive, below the steps
			const visitors = [...segment.visitors.values()]
				.filter((visitor) => visitor.request !== undefined && visitor.respond !== undefined)
				.sort((a, b) => a.request - b.request);
			const stepsTop = rows > 0 ? stepsHeight + (rows - 1) * 20 + 10 : 16;
			const lanesTop = panelTop + (done.length > 0 ? stepsTop : 8);
			const laneHeight = (panelTop + panelHeight - lanesTop) / Math.max(1, visitors.length);
			const lanes = visitors.map((visitor, index) =>
			{
				const y = lanesTop + (index + 0.5) * laneHeight;
				const asked = visitor.request - segment.start;
				const answered = visitor.respond - segment.start;
				
				return {
					asked,
					answered,
					wait: make('line', {x1: x(asked), x2: x(asked), y1: y, y2: y, class: 'wait'}, group),
					done: make('circle', {cx: x(answered), cy: y, r: Math.max(3, Math.min(7, laneHeight * 0.35)), class: 'done', opacity: 0}, group),
				};
			});
			
			const stampX = labelWidth + chartWidth + 40 + stampWidth / 2;
			const longest = Math.max(0, ...lanes.map((lane) => lane.answered - lane.asked));
			const value = make('text', {x: stampX, y: middle + 8, class: `stamp-v ${longest > 100 ? 'bad' : 'good'}`}, group);
			make('text', {x: stampX, y: middle + 46, class: 'stamp-l'}, group).textContent = Deck.t('LONGEST WAIT');
			const forgot = make('text', {x: stampX, y: middle + 78, class: 'stamp-l'}, group);
			
			return {band, banEnd, phases, lanes, value, forgot, longest};
		});
		
		// a legend under the last panel's labels
		const legend = make('g', {class: 'legend'}, svg);
		[
			['wait', Deck.t('a visitor waiting for Redis')],
			['done', Deck.t('the visitor has its answer')],
			['band', Deck.t('while the tag is being forgotten')],
		].forEach(([kind, text], row) =>
		{
			const ly = height - axisHeight - 82 + row * 30;
			if(kind === 'wait')
			{
				make('line', {x1: 0, x2: 34, y1: ly - 6, y2: ly - 6, class: 'wait'}, legend);
			}
			else if(kind === 'done')
			{
				make('circle', {cx: 17, cy: ly - 6, r: 7, class: 'done'}, legend);
			}
			else
			{
				make('rect', {x: 0, y: ly - 14, width: 34, height: 16, class: 'band'}, legend);
			}
			make('text', {x: 50, y: ly, class: 'legend-label'}, legend).textContent = text;
		});
		
		const startedAt = performance.now();
		const draw = () =>
		{
			const now = (performance.now() - startedAt) * pace - lead;
			cursor.setAttribute('x1', String(x(now)));
			cursor.setAttribute('x2', String(x(now)));
			
			for(const panel of panels)
			{
				panel.band.setAttribute('width', String(Math.max(0, x(Math.min(now, panel.banEnd)) - x(0))));
				for(const phase of panel.phases)
				{
					phase.node.setAttribute('opacity', now >= phase.from ? '1' : '0');
					phase.bracket.setAttribute('x2', String(x(Math.max(phase.from, Math.min(now, phase.to)))));
				}
				
				let waited = 0;
				for(const lane of panel.lanes)
				{
					const until = Math.min(now, lane.answered);
					lane.wait.setAttribute('x2', String(now < lane.asked ? x(lane.asked) : x(until)));
					lane.done.setAttribute('opacity', now >= lane.answered ? '1' : '0');
					waited = now >= lane.asked ? Math.max(waited, until - lane.asked) : waited;
				}
				panel.value.textContent = duration(waited);
				panel.forgot.textContent = now >= panel.banEnd ? Deck.t('FORGOTTEN IN {t}', {t: duration(panel.banEnd).toUpperCase()}) : Deck.t('FORGETTING…');
			}
			
			if(now < span)
			{
				frame = requestAnimationFrame(draw);
			}
			else
			{
				for(const panel of panels)
				{
					panel.value.textContent = duration(panel.longest);
				}
			}
		};
		
		frame = requestAnimationFrame(draw);
	};
	
	return {play, reset, scale: 10};
});

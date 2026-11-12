/*	Two servers behind a load balancer, and an edit on one of them.

	A panel per mode, a lane per server: the visitors go to A and B in turn.
	Each visitor is a dot at its answer, with the version of the article it
	got:

	mint  the latest article
	red   an old one: the edit happened, this server never heard of it
	red bar  the visitor's server builds the page (from the database)

	The dashed line is the editor saving on server A. Drawn at ×0.25 (scale
	2.5, with the deck's ×0.1). The svg borrows the stampede's styles. */

Deck.register('fleet', (element) =>
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
	
	/** the events of one segment (one mode): the edit, a visitor per worker, the summary */
	const segmentsOf = (events, modes) =>
	{
		const segments = [];
		for(const event of events)
		{
			if(event.type === 'segment' && (modes.length === 0 || modes.includes(event.mode)))
			{
				segments.push({...event, edit: null, visitors: new Map(), summary: null});
			}
			
			const segment = segments.find((candidate) => candidate.mode === event.mode);
			if(segment === undefined)
			{
				continue;
			}
			
			if(event.type === 'edit')
			{
				segment.edit = event;
			}
			if(event.type === 'summary')
			{
				segment.summary = event;
			}
			if(['request', 'respond', 'origin.start', 'origin.end'].includes(event.type) && event.w > 0)
			{
				const visitor = segment.visitors.get(event.w) ?? {w: event.w};
				visitor[event.type] = event.t;
				if(event.type === 'respond')
				{
					Object.assign(visitor, {server: event.server, version: event.version, latest: event.latest});
				}
				
				segment.visitors.set(event.w, visitor);
			}
		}
		
		return segments.filter((segment) => segment.edit !== null);
	};
	
	/** a number with as many decimals as it has (1.25), a decimal comma in Polish */
	const figure = (value) => Deck.decimal(value, (String(value).split('.')[1] ?? '').length);
	
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
		
		const end = Math.max(...segments.flatMap((segment) => [...segment.visitors.values()].map((visitor) => visitor.respond ?? 0))) + 20;
		const step = [100, 200, 250, 500].find((candidate) => end / candidate <= 8) ?? 1000;
		const span = Math.ceil(end / step) * step;
		const x = (t) => labelWidth + Math.max(0, Math.min(span, t)) / span * lanesWidth;
		
		const svg = make('svg', {width, height, viewBox: `0 0 ${width} ${height}`, class: 'stampede fleet'}, stage);
		
		const axis = make('g', {class: 'axis'}, svg);
		for(let t = 0; t <= span; t+= step)
		{
			make('line', {x1: x(t), x2: x(t), y1: 0, y2: height - axisHeight, class: 'tick'}, axis);
			make('text', {x: x(t), y: height - 8, class: 'tick-label'}, axis).textContent = t >= 1000 ? `${figure(t / 1000)} s` : `${t} ms`;
		}
		make('text', {x: labelWidth - 40, y: height - 8, class: 'axis-title'}, axis).textContent = Deck.t('time →');
		
		const cursor = make('line', {x1: x(0), x2: x(0), y1: 0, y2: height - axisHeight, class: 'cursor'}, svg);
		
		const panels = segments.map((segment, at) =>
		{
			const top = at * (panelHeight + gap);
			const group = make('g', {class: `panel mode-${segment.mode}`}, svg);
			const label = Deck.t(segment.label);
			make('text', {x: 0, y: top + 34, class: 'panel-label'}, group).textContent = label.split(' (')[0];
			const details = label.includes('(') ? label.slice(label.indexOf('(') + 1, -1) : '';
			[details, Deck.t('{n} visitors, A and B in turn', {n: segment.visitors.size}), Deck.t('a build takes {ms} ms', {ms: segment.buildMs})].filter(Boolean).forEach((line, row) =>
			{
				make('text', {x: 0, y: top + 66 + row * 30, class: 'panel-sub'}, group).textContent = line;
			});
			
			// a lane per server, the server's name beside it
			const laneTop = top + 40;
			const laneHeight = (panelHeight - 40) / 2;
			const laneY = {A: laneTop + laneHeight * 0.5, B: laneTop + laneHeight * 1.5};
			for(const [server, y] of Object.entries(laneY))
			{
				make('rect', {x: x(0), y: y - laneHeight / 2 + 6, width: lanesWidth, height: laneHeight - 12, class: 'lane-band'}, group);
				make('text', {x: labelWidth - 16, y: y + 8, class: 'lane-label'}, group).textContent = Deck.t('server {name}', {name: server});
			}
			
			// the edit: on server A
			const editAt = segment.edit.t;
			const edit = make('g', {class: 'edit', opacity: 0}, group);
			make('line', {x1: x(editAt), x2: x(editAt), y1: top, y2: top + panelHeight, class: 'moment'}, edit);
			make('text', {x: x(editAt) + 8, y: top + 22, class: 'moment-label'}, edit).textContent = Deck.t('the editor saves, on server A');
			
			const radius = Math.min(12, laneHeight * 0.12);
			const shapes = [...segment.visitors.values()].filter((visitor) => visitor.respond !== undefined).map((visitor) =>
			{
				const y = laneY[visitor.server];
				const old = visitor.version < visitor.latest;
				const built = visitor['origin.start'] === undefined ? null : {from: visitor['origin.start'], to: visitor['origin.end'] ?? visitor['origin.start']};
				
				return {
					visitor,
					old,
					work: built === null ? null : make('rect', {x: x(built.from), y: y - radius * 0.5, width: 0, height: radius, class: 'work'}, group),
					built,
					done: make('circle', {cx: x(visitor.respond), cy: y, r: radius, class: old ? 'done old' : 'done', opacity: 0}, group),
					version: make('text', {x: x(visitor.respond), y: y - radius - 10, class: old ? 'version old' : 'version', opacity: 0}, group),
				};
			});
			shapes.forEach((shape) => shape.version.textContent = `v${shape.visitor.version}`);
			
			const middle = top + panelHeight / 2;
			const stampX = labelWidth + lanesWidth + 40 + stampWidth / 2;
			const count = make('text', {x: stampX, y: middle + 8, class: `stamp-v ${(segment.summary?.old ?? 0) > 0 ? 'bad' : 'good'}`}, group);
			make('text', {x: stampX, y: middle + 46, class: 'stamp-l'}, group).textContent = Deck.t('GOT THE OLD ARTICLE');
			make('text', {x: stampX, y: middle + 78, class: 'stamp-l'}, group).textContent = Deck.t('OF {n} AFTER THE EDIT', {n: segment.summary?.after ?? 0});
			
			return {segment, editAt, edit, shapes, count};
		});
		
		// the legend, bottom left under the last panel's labels
		const legend = make('g', {class: 'legend'}, svg);
		[
			['work', Deck.t('building the page, from the database')],
			['done', Deck.t('answered: the latest article')],
			['old', Deck.t('answered: an old article')],
		].forEach(([kind, text], row) =>
		{
			const ly = height - axisHeight - 76 + row * 28;
			if(kind === 'work')
			{
				make('rect', {x: 0, y: ly - 13, width: 34, height: 12, class: 'work'}, legend);
			}
			else
			{
				make('circle', {cx: 17, cy: ly - 6, r: 7, class: kind === 'old' ? 'done old' : 'done'}, legend);
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
				panel.edit.setAttribute('opacity', now >= panel.editAt ? '1' : '0');
				let old = 0;
				for(const shape of panel.shapes)
				{
					if(shape.work)
					{
						const to = Math.min(now, shape.built.to);
						shape.work.setAttribute('width', String(now < shape.built.from ? 0 : Math.max(2, x(to) - x(shape.built.from))));
					}
					
					const answered = now >= shape.visitor.respond;
					shape.done.setAttribute('opacity', answered ? '1' : '0');
					shape.version.setAttribute('opacity', answered ? '1' : '0');
					old+= answered && shape.old ? 1 : 0;
				}
				panel.count.textContent = String(old);
			}
			
			if(now < span)
			{
				frame = requestAnimationFrame(draw);
			}
		};
		
		frame = requestAnimationFrame(draw);
	};
	
	return {play, reset, scale: 2.5};
});

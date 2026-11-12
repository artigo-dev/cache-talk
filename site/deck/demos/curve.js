/*	Forgetting one tag, by the number of cached pages that carry it, in every
	store of the road. Log scale on both axes: 10 pages to a million, a
	hundredth of a millisecond to ten seconds.

	A line per store, its points appearing a size at a time (700 ms a step
	at the deck's ×0.1). The legend names each store and its time for the
	largest tag; the versioned store's line stays flat. */

Deck.register('curve', (element) =>
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
	
	/** a line per store: its points, smallest tag first */
	const storesOf = (events) =>
	{
		const stores = [];
		for(const event of events)
		{
			if(event.type === 'segment')
			{
				stores.push({...event, points: []});
			}
			const store = stores.find((candidate) => candidate.store === event.store);
			if(store && event.type === 'forgotten')
			{
				store.points.push({pages: event.pages, ms: Math.max(0.01, event.ms)});
			}
		}
		
		stores.forEach((store) => store.points.sort((a, b) => a.pages - b.pages));
		
		return stores.filter((store) => store.points.length > 0);
	};
	
	/** a number with as many decimals as it has (0.01), a decimal comma in Polish */
	const figure = (value) => Deck.decimal(value, (String(value).split('.')[1] ?? '').length);
	
	const pagesLabel = (pages) => pages >= 1e6 ? Deck.t('{n} M', {n: figure(pages / 1e6)}) : pages.toLocaleString('en').replace(/,/g, ' ');
	
	const msLabel = (ms) => ms >= 1000 ? `${figure(ms / 1000)} s` : `${figure(ms)} ms`;
	
	const duration = (ms) => ms >= 1000 ? `${Deck.decimal(ms / 1000, 1)} s` : ms >= 10 ? `${Math.round(ms)} ms` : ms >= 1 ? `${Deck.decimal(ms, 1)} ms` : `${Deck.decimal(ms, 2)} ms`;
	
	const play = (events, pace = 0.1) =>
	{
		reset();
		const stores = storesOf(events);
		if(stores.length === 0)
		{
			stage.textContent = Deck.t('This run has no stores.');
			
			return;
		}
		
		const width = stage.clientWidth || 1680;
		const height = stage.clientHeight || 600;
		const labelWidth = 440;
		// the time labels sit right of the chart: on the left they would cover the smallest tags' points
		const chartRight = width - 110;
		const top = 20;
		const axisHeight = 40;
		const bottom = height - axisHeight;
		
		const all = stores.flatMap((store) => store.points);
		const xMin = 1;
		const xMax = Math.log10(Math.max(...all.map((point) => point.pages)));
		// a decade of room under the fastest point: its label never sits on the axis
		const yMin = Math.floor(Math.log10(Math.min(...all.map((point) => point.ms))) - 0.3);
		const yMax = Math.ceil(Math.log10(Math.max(...all.map((point) => point.ms))));
		const x = (pages) => labelWidth + (Math.log10(pages) - xMin + 0.15) / (xMax - xMin + 0.3) * (chartRight - labelWidth);
		const y = (ms) => bottom - (Math.log10(ms) - yMin) / (yMax - yMin) * (bottom - top);
		
		const svg = make('svg', {width, height, viewBox: `0 0 ${width} ${height}`, class: 'ban curve'}, stage);
		
		const axis = make('g', {class: 'axis'}, svg);
		for(let power = xMin; power <= xMax; power++)
		{
			const pages = 10 ** power;
			make('line', {x1: x(pages), x2: x(pages), y1: top, y2: bottom + 6, class: 'tick'}, axis);
			make('text', {x: x(pages), y: height - 8, class: 'tick-label'}, axis).textContent = pagesLabel(pages);
		}
		for(let power = yMin; power <= yMax; power++)
		{
			const ms = 10 ** power;
			make('line', {x1: labelWidth, x2: chartRight, y1: y(ms), y2: y(ms), class: 'tick'}, axis);
			make('text', {x: chartRight + 12, y: y(ms) + 6, class: 'tick-label y'}, axis).textContent = msLabel(Number(ms.toPrecision(1)));
		}
		make('text', {x: labelWidth - 40, y: height - 8, class: 'axis-title'}, axis).textContent = Deck.t('cached pages with the tag →');
		const middle = (top + bottom) / 2;
		make('text', {x: labelWidth - 16, y: middle, transform: `rotate(-90 ${labelWidth - 16} ${middle})`, class: 'axis-title vertical'}, axis).textContent = Deck.t('time to forget it');
		
		make('text', {x: 0, y: top + 34, class: 'panel-label'}, svg).textContent = Deck.t('One tag');
		[Deck.t('one invalidateTags() call'), Deck.t('both axes: log scale')].forEach((line, row) =>
		{
			make('text', {x: 0, y: top + 66 + row * 30, class: 'panel-sub'}, svg).textContent = line;
		});
		
		// a line per store, drawn up to the step's size
		const lines = stores.map((store) =>
		{
			const group = make('g', {class: `series store-${store.store}`}, svg);
			const path = make('polyline', {points: '', class: 'series-line'}, group);
			const dots = store.points.map((point) => make('circle', {cx: x(point.pages), cy: y(point.ms), r: 6, class: 'series-dot', opacity: 0}, group));
			
			return {store, path, dots};
		});
		
		// the legend: each store, its time for the largest tag
		const legend = make('g', {class: 'legend'}, svg);
		const rowsTop = bottom - stores.length * 34 + 6;
		const values = stores.map((store, row) =>
		{
			const ly = rowsTop + row * 34;
			const group = make('g', {class: `series store-${store.store}`}, legend);
			make('line', {x1: 0, x2: 30, y1: ly - 7, y2: ly - 7, class: 'series-line'}, group);
			make('circle', {cx: 15, cy: ly - 7, r: 5, class: 'series-dot'}, group);
			make('text', {x: 44, y: ly, class: 'series-name'}, group).textContent = Deck.t(store.label);
			const value = make('text', {x: labelWidth - 50, y: ly, class: 'series-value'}, group);
			
			return value;
		});
		make('text', {x: 0, y: rowsTop - 34, class: 'legend-label'}, legend).textContent = Deck.t('the largest tag:');
		
		const sizes = [...new Set(all.map((point) => point.pages))].sort((a, b) => a - b);
		const stepMs = 70 / pace;
		const startedAt = performance.now();
		const draw = () =>
		{
			const shown = Math.min(sizes.length, Math.floor((performance.now() - startedAt) / stepMs) + 1);
			const upTo = sizes[shown - 1];
			lines.forEach((line, at) =>
			{
				const visible = line.store.points.filter((point) => point.pages <= upTo);
				line.path.setAttribute('points', visible.map((point) => `${x(point.pages)},${y(point.ms)}`).join(' '));
				line.dots.forEach((dot, index) => dot.setAttribute('opacity', index < visible.length ? '1' : '0'));
				const last = visible[visible.length - 1];
				values[at].textContent = last ? `${duration(last.ms)} · ${pagesLabel(last.pages)}` : '';
			});
			
			if(shown < sizes.length)
			{
				frame = requestAnimationFrame(draw);
			}
		};
		
		frame = requestAnimationFrame(draw);
	};
	
	return {play, reset};
});

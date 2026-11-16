/*	A slow page, a fast edit: 1 000 rounds, the editor saving mid-build in one
	round of ten, picked at random (in every round in recordings made before
	that: their rounds have no "race" field).

	A panel per mode, the rounds as a grid of cells filling in order:

	gray  no edit: the page was cached as built
	red   an edit mid-build, and the old page stayed in the cache
	mint  an edit mid-build, and its save was refused: the next visitor gets
	      the new text

	The stamp counts the old pages that stayed, of the edits mid-build. The
	rounds take a few hundred milliseconds; they are drawn over three seconds. */

Deck.register('race', (element) =>
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
	
	const segmentsOf = (events, modes) =>
	{
		const segments = [];
		for(const event of events)
		{
			if(event.type === 'segment' && (modes.length === 0 || modes.includes(event.mode)))
			{
				segments.push({...event, rounds: [], summary: null});
			}
			
			const segment = segments.find((candidate) => candidate.mode === event.mode);
			if(segment === undefined)
			{
				continue;
			}
			
			if(event.type === 'round')
			{
				// a recording without "race" forced it in every round
				const race = event.race ?? true;
				segment.rounds.push(race ? (event.stayed ? 'stayed' : 'refused') : 'calm');
			}
			if(event.type === 'summary')
			{
				segment.summary = event;
			}
		}
		
		return segments;
	};
	
	const play = (events, pace = 1) =>
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
		const gridWidth = width - labelWidth - stampWidth - 40;
		const gap = 34;
		const panelHeight = (height - gap * (segments.length - 1)) / segments.length;
		const rounds = Math.max(...segments.map((segment) => segment.rounds.length));
		// as many columns as fill the width at the panel's height
		const columns = Math.ceil(Math.sqrt(rounds * gridWidth / (panelHeight - 20)));
		const rows = Math.ceil(rounds / columns);
		const cell = Math.min(gridWidth / columns, (panelHeight - 20) / rows);
		
		const svg = make('svg', {width, height, viewBox: `0 0 ${width} ${height}`, class: 'race'}, stage);
		
		const panels = segments.map((segment, at) =>
		{
			const top = at * (panelHeight + gap);
			const group = make('g', {class: `panel mode-${segment.mode}`}, svg);
			const label = Deck.t(segment.label);
			// the store's name; php-library's stores have their own name on a second line, as in the ban chart
			const name = label.split(' (')[0];
			const library = name.startsWith('php-library ');
			const titleBelow = library ? 34 : 0;
			make('text', {x: 0, y: top + 34, class: 'panel-label'}, group).textContent = library ? 'php-library' : name;
			if(library)
			{
				make('text', {x: 0, y: top + 34 + titleBelow, class: 'panel-label'}, group).textContent = name.slice('php-library '.length);
			}
			
			const details = label.includes('(') ? label.slice(label.indexOf('(') + 1, -1).split(' · ') : [];
			const races = segment.rounds.filter((kind) => kind !== 'calm').length;
			const edits = races === segment.rounds.length
				? Deck.t('the edit always mid-build')
				: Deck.t('{n} with an edit mid-build', {n: races.toLocaleString('en').replace(/,/g, ' ')});
			[...details, Deck.t('{n} rounds', {n: segment.rounds.length.toLocaleString('en').replace(/,/g, ' ')}), edits].forEach((line, row) =>
			{
				make('text', {x: 0, y: top + titleBelow + 66 + row * 30, class: 'panel-sub'}, group).textContent = line;
			});
			
			const cells = segment.rounds.map((stayed, round) => make('rect', {
				x: labelWidth + (round % columns) * cell,
				y: top + 10 + Math.floor(round / columns) * cell,
				width: cell - 2,
				height: cell - 2,
				class: 'cell',
			}, group));
			
			const stampX = labelWidth + gridWidth + 40 + stampWidth / 2;
			const middle = top + panelHeight / 2;
			const count = make('text', {x: stampX, y: middle + 8, class: `stamp-v ${segment.summary?.stayed > 0 ? 'bad' : 'good'}`}, group);
			make('text', {x: stampX, y: middle + 46, class: 'stamp-l'}, group).textContent = Deck.t('OLD PAGES STAYED');
			if(races < segment.rounds.length)
			{
				make('text', {x: stampX, y: middle + 76, class: 'stamp-l'}, group).textContent = Deck.t('OF {n} EDITS MID-BUILD', {n: races.toLocaleString('en').replace(/,/g, ' ')});
			}

			return {segment, cells, count};
		});

		// a legend under the last panel's labels; the gray cell only when some rounds had no edit
		const calm = segments.some((segment) => segment.rounds.includes('calm'));
		const entries = [
			...(calm ? [['calm', Deck.t('no edit: cached as built')]] : []),
			['stayed', Deck.t(calm ? 'edit: the old page stayed' : 'the old page stayed in the cache')],
			['refused', Deck.t(calm ? 'edit: its save was refused' : 'its save was refused')],
		];
		const legend = make('g', {class: 'legend'}, svg);
		entries.forEach(([kind, text], row) =>
		{
			const ly = height - 40 - (entries.length - 2) * 30 + row * 30;
			make('rect', {x: 0, y: ly - 16, width: 18, height: 18, class: `cell ${kind}`}, legend);
			make('text', {x: 34, y: ly, class: 'legend-label'}, legend).textContent = text;
		});
		
		const duration = 3000;
		const startedAt = performance.now();
		const draw = () =>
		{
			const shown = Math.min(rounds, Math.floor((performance.now() - startedAt) * pace / duration * rounds));
			for(const panel of panels)
			{
				let stayed = 0;
				panel.cells.forEach((rect, round) =>
				{
					if(round < shown)
					{
						const kind = panel.segment.rounds[round];
						rect.setAttribute('class', `cell ${kind}`);
						stayed+= kind === 'stayed' ? 1 : 0;
					}
				});
				panel.count.textContent = stayed.toLocaleString('en').replace(/,/g, ' ');
			}
			
			if(shown < rounds)
			{
				frame = requestAnimationFrame(draw);
			}
		};
		
		frame = requestAnimationFrame(draw);
	};
	
	return {play, reset, scale: 10};
});

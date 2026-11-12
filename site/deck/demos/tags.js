/*	Tags in a file cache, on disk, step by step.

	Left, items/: a file per cached page, its tags as chips. Right, tags/: a
	file per tag, listing the pages that carry it. A visitor opens every page
	(built and saved, added to its tags' lists), the editor saves article 42,
	its list names the pages whose files are deleted, then the list goes; a
	visitor opens every page again. Entries on other lists that point to a
	deleted page are dimmed: the lists still name them.

	The run takes milliseconds: it is played a step at a time (800 ms a step
	at the deck's ×0.1), the line under the columns saying what happens. */

Deck.register('tags', (element) =>
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
	
	const short = (file) => `${file.slice(0, 10)}…`;
	
	const play = (events, pace = 0.1) =>
	{
		reset();
		const segment = events.find((event) => event.type === 'segment');
		if(segment === undefined)
		{
			stage.textContent = Deck.t('This run has no pages.');
			
			return;
		}
		
		const width = stage.clientWidth || 1680;
		const height = stage.clientHeight || 600;
		const keys = Object.keys(segment.pages);
		const tags = [...new Set(Object.values(segment.pages).flat())];
		const leftWidth = width * 0.5;
		const rightX = leftWidth + 60;
		const rightWidth = width - rightX;
		const top = 54;
		const captionHeight = 60;
		const rows = Math.max(keys.length, tags.length);
		const rowHeight = Math.min(96, (height - top - captionHeight) / rows);
		const cardHeight = rowHeight - 14;
		
		const svg = make('svg', {width, height, viewBox: `0 0 ${width} ${height}`, class: 'tags-demo'}, stage);
		make('text', {x: 0, y: 26, class: 'column-title'}, svg).textContent = 'items/';
		make('text', {x: 110, y: 26, class: 'column-sub'}, svg).textContent = Deck.t('a file per cached page');
		make('text', {x: rightX, y: 26, class: 'column-title'}, svg).textContent = 'tags/';
		make('text', {x: rightX + 96, y: 26, class: 'column-sub'}, svg).textContent = Deck.t('a file per tag, listing its pages');
		
		// a card per page, in the order of the site
		const files = new Map(events.filter((event) => event.type === 'read').map((event) => [event.key, event.file]));
		const pages = new Map(keys.map((key, row) =>
		{
			const y = top + row * rowHeight;
			const group = make('g', {class: 'card page', opacity: 0}, svg);
			make('rect', {x: 0, y, width: leftWidth, height: cardHeight, rx: 8, class: 'card-box'}, group);
			make('text', {x: 22, y: y + 36, class: 'page-name'}, group).textContent = key;
			make('text', {x: 22, y: y + cardHeight - 14, class: 'file-name'}, group).textContent = `items/${short(files.get(key) ?? '')}`;
			let chipX = 240;
			for(const tag of segment.pages[key])
			{
				const chipWidth = tag.length * 10 + 22;
				const chip = make('g', {class: tag === segment.edited ? 'chip edited' : 'chip'}, group);
				make('rect', {x: chipX, y: y + 16, width: chipWidth, height: 28, rx: 14}, chip);
				make('text', {x: chipX + chipWidth / 2, y: y + 36}, chip).textContent = tag;
				chipX+= chipWidth + 10;
			}
			const status = make('text', {x: leftWidth - 20, y: y + cardHeight - 14, class: 'status'}, group);
			
			return [key, {group, status}];
		}));
		
		// a card per tag file, the pages on its list
		const lists = new Map(tags.map((tag, row) =>
		{
			const y = top + row * rowHeight;
			const group = make('g', {class: tag === segment.edited ? 'card list edited' : 'card list', opacity: 0}, svg);
			make('rect', {x: rightX, y, width: rightWidth, height: cardHeight, rx: 8, class: 'card-box'}, group);
			const chipWidth = tag.length * 10 + 22;
			const chip = make('g', {class: tag === segment.edited ? 'chip edited' : 'chip'}, group);
			make('rect', {x: rightX + 22, y: y + 16, width: chipWidth, height: 28, rx: 14}, chip);
			make('text', {x: rightX + 22 + chipWidth / 2, y: y + 36}, chip).textContent = tag;
			const file = make('text', {x: rightX + 22, y: y + cardHeight - 14, class: 'file-name'}, group);
			const entries = make('text', {x: rightX + 40 + chipWidth, y: y + 36, class: 'entries'}, group);
			
			return [tag, {group, file, entries, keys: []}];
		}));
		
		const caption = make('text', {x: 0, y: height - 18, class: 'caption'}, svg);
		
		// which pages exist now: a list's entry pointing to a deleted page is dimmed
		const alive = new Set();
		const drawList = (list) =>
		{
			list.entries.textContent = '';
			list.keys.forEach((key, at) =>
			{
				if(at > 0)
				{
					make('tspan', {class: 'entry-gap'}, list.entries).textContent = ' · ';
				}
				make('tspan', {class: alive.has(key) ? 'entry' : 'entry dead'}, list.entries).textContent = key;
			});
		};
		const drawLists = () => lists.forEach(drawList);
		
		const setStatus = (key, text, kind) =>
		{
			const page = pages.get(key);
			page.status.textContent = text;
			page.status.setAttribute('class', `status ${kind}`);
		};
		
		// the steps: a visit (its read and save), the edit, the deletes
		const steps = [];
		const deleted = events.filter((event) => event.type === 'deleted');
		for(const event of events)
		{
			if(event.type === 'read')
			{
				const saved = events.find((other) => other.type === 'saved' && other.key === event.key && other.round === event.round);
				steps.push({
					text: event.hit
						? Deck.t('a visitor opens {key}: still cached', {key: event.key})
						: event.round === 1
							? Deck.t('a visitor opens {key}: not cached, so built and saved, and added to the lists of its tags', {key: event.key})
							: Deck.t('a visitor opens {key}: its file is gone, so it is built again, with the new article', {key: event.key}),
					kind: event.hit ? 'good' : event.round === 1 ? '' : 'good',
					apply: () =>
					{
						const page = pages.get(event.key);
						page.group.setAttribute('opacity', '1');
						page.group.setAttribute('class', event.hit ? 'card page hit' : 'card page');
						setStatus(event.key, event.hit ? Deck.t('cached') : event.round === 1 ? Deck.t('built') : Deck.t('rebuilt'), event.hit || event.round === 2 ? 'good' : '');
						if(saved)
						{
							alive.add(event.key);
							for(const tag of saved.tags)
							{
								const list = lists.get(tag);
								list.group.setAttribute('opacity', '1');
								list.group.setAttribute('class', list.group.getAttribute('class').replace(' gone', '').replace(' lit', ''));
								list.file.textContent = `tags/${short(saved.lists[tag])}`;
								if(list.keys.includes(event.key) === false)
								{
									list.keys.push(event.key);
								}
							}
							drawLists();
						}
					},
				});
			}
			if(event.type === 'edit')
			{
				steps.push({
					text: Deck.t('the editor saves article 42: invalidateTags([\'{tag}\'])', {tag: event.tag}),
					kind: 'edit',
					apply: () =>
					{
						const list = lists.get(event.tag);
						list.group.setAttribute('class', `${list.group.getAttribute('class')} lit`);
						for(const key of list.keys)
						{
							pages.get(key).group.setAttribute('class', 'card page lit');
						}
					},
				});
			}
			if(event.type === 'list.deleted')
			{
				steps.push({
					text: Deck.t('its list names {n} pages: their files are deleted, then the list', {n: deleted.length}),
					kind: 'bad',
					apply: () =>
					{
						for(const gone of deleted)
						{
							alive.delete(gone.key);
							pages.get(gone.key).group.setAttribute('class', 'card page gone');
							setStatus(gone.key, Deck.t('deleted'), 'bad');
						}
						const list = lists.get(event.tag);
						list.group.setAttribute('class', `${list.group.getAttribute('class').replace(' lit', '')} gone`);
						list.keys = [];
						drawLists();
					},
				});
			}
		}
		
		const stepMs = 80 / pace;
		let applied = 0;
		const startedAt = performance.now();
		const draw = () =>
		{
			const due = Math.min(steps.length, Math.floor((performance.now() - startedAt) / stepMs) + 1);
			for(; applied < due; applied++)
			{
				const step = steps[applied];
				step.apply();
				caption.textContent = `${applied + 1} · ${step.text}`;
				caption.setAttribute('class', `caption ${step.kind}`);
			}
			
			if(applied < steps.length)
			{
				frame = requestAnimationFrame(draw);
			}
		};
		
		frame = requestAnimationFrame(draw);
	};
	
	return {play, reset};
});

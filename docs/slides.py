"""Renders the slides the packages' READMEs show into site/slides/*.png.

    python docs/slides.py [path to Chrome or Edge]

A slide is found by its English title, since its number moves whenever the
deck changes. Rendered here and committed, not in the Pages workflow: the
deck's monospace font is the machine's own, and a CI runner has another.
Needs Pillow, which cuts the empty band under the road off each picture.
"""

import functools
import http.server
import os
import re
import subprocess
import sys
import tempfile
import threading

from PIL import Image

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SITE = os.path.join(ROOT, 'site')
OUT = os.path.join(SITE, 'slides')
WIDTH, HEIGHT, KEEP = 1600, 900, 770

# file name -> the slide's English title, as index.html has it
SLIDES = {
	'memolock-24': 'The same 24 requests,',
	'woken-by-message': 'Woken by a message,',
	'add-a-rule': 'Add a rule,',
	'one-tag-curve': 'Forgetting one tag:',
	'three-ways': 'Three ways',
	'guard-race': 'how many late cached pages stay?',
	'swr-expiry': 'The cached page expires,',
	'soft-edit': 'The same edit, <span class="g">nobody waits',
}

BROWSERS = [
	r'C:\Program Files\Google\Chrome\Application\chrome.exe',
	r'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',
	'/usr/bin/google-chrome',
	'/usr/bin/chromium',
	'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
]


def numbers():
	with open(os.path.join(SITE, 'index.html'), encoding='utf-8') as file:
		sections = re.split(r'<section class="slide"', file.read())[1:]
	found = {}
	for name, title in SLIDES.items():
		# the notes may quote another slide's title
		hits = [at for at, section in enumerate(sections, 1) if title in section.split('<aside')[0]]
		if len(hits) != 1:
			sys.exit(f'{name}: "{title}" is on {len(hits)} slides, expected one')
		found[name] = hits[0]
	return found


def main():
	browser = sys.argv[1] if len(sys.argv) > 1 else next((path for path in BROWSERS if os.path.exists(path)), None)
	if browser is None:
		sys.exit('no browser found: pass the path to Chrome or Edge')
	os.makedirs(OUT, exist_ok=True)
	handler = functools.partial(http.server.SimpleHTTPRequestHandler, directory=SITE)
	handler.log_message = lambda *args: None
	server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), handler)
	threading.Thread(target=server.serve_forever, daemon=True).start()
	with tempfile.TemporaryDirectory() as profile:
		for name, number in numbers().items():
			target = os.path.join(OUT, f'{name}.png')
			# ?all shows every step of a slide; the time budget lets a recorded run play to its end
			subprocess.run([
				browser, '--headless=new', '--disable-gpu', '--hide-scrollbars',
				f'--user-data-dir={profile}', f'--window-size={WIDTH},{HEIGHT}',
				'--virtual-time-budget=40000', f'--screenshot={target}',
				f'http://127.0.0.1:{server.server_port}/?lang=en&all&embed#/{number}',
			], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
			with Image.open(target) as shot:
				shot.convert('RGB').crop((0, 0, WIDTH, KEEP)).save(target, optimize=True)
			print(f'{name}.png  slide {number}  {os.path.getsize(target) // 1024} KB')
	server.shutdown()


main()

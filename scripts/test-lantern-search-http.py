#!/usr/bin/env python3
"""DDEV-only same-slug search regression; restores fixture pages and rebuilds.
Usage: python3 scripts/test-lantern-search-http.py DDEV_PROJECT [GRAV_SUBDIRECTORY]
"""
import json
from pathlib import Path
import secrets
import shutil
import ssl
import subprocess
import sys
import urllib.parse
import urllib.request

project = Path(sys.argv[1]).resolve()
relative = Path(sys.argv[2] if len(sys.argv) > 2 else '.')
assert not relative.is_absolute() and '..' not in relative.parts
root = project / relative
remote = '/var/www/html' + ('/' + relative.as_posix() if str(relative) != '.' else '')
assert (project / '.ddev/config.yaml').is_file()
assert (root / 'user/plugins/lantern-search/lantern-search.php').is_file()

def run(args):
    return subprocess.check_output(args, cwd=project, text=True, stderr=subprocess.STDOUT)

base = json.loads(run(['ddev', 'describe', '-j']))['raw']['primary_url'].rstrip('/')
assert urllib.parse.urlparse(base).hostname.endswith('.ddev.site')
ctx = ssl._create_unverified_context()  # Only the local DDEV CA.
prefix = 'lanterncheck' + secrets.token_hex(5)
created = []

def rebuild():
    run(['ddev', 'mutagen', 'sync'])
    run(['ddev', 'exec', '--dir', remote, 'php', 'bin/grav', 'clearcache', '--cache-only'])
    run(['ddev', 'exec', '--dir', remote, 'php', 'bin/plugin', 'lantern-search', 'index', '--force'])

def query(**params):
    with urllib.request.urlopen(base + '/lantern-search/query?' + urllib.parse.urlencode(params), context=ctx, timeout=30) as response:
        assert response.status == 200
        return json.load(response)

try:
    top = root / 'user/pages' / ('99.' + prefix)
    parent = root / 'user/pages' / ('99.' + prefix + 'parent')
    nested = parent / prefix
    for folder in [top, parent, nested]:
        assert not folder.exists()
        folder.mkdir()
        created.append(folder)
        header = {'title': prefix, 'published': True, 'visible': False,
                  'taxonomy': {'tag': [prefix + 'topic']}}
        (folder / 'default.md').write_text('---\n' + json.dumps(header) + '\n---\n' + prefix)
    for suffix, flags in [('hidden', {'published': False}), ('noindex', {'metadata': {'robots': 'noindex'}}),
                          ('protected', {'access': {'site.login': True}})]:
        folder = parent / suffix
        folder.mkdir()
        created.append(folder)
        header = {'title': prefix, **flags}
        (folder / 'default.md').write_text('---\n' + json.dumps(header) + '\n---\n' + prefix)
    rebuild()
    expected = {'/' + prefix, '/' + prefix + 'parent', '/' + prefix + 'parent/' + prefix}
    for params in [{'q': prefix, 'limit': 50}, {'q': prefix, 'tag': prefix + 'topic', 'limit': 50}]:
        result = query(**params)
        assert {item['route'] for item in result['results']} == expected, 'Incomplete or unsafe search results'
    assert query(q=prefix, tag=prefix + 'absent')['total'] == 0
finally:
    for path in reversed(created):
        if path.exists():
            shutil.rmtree(path)
    rebuild()
assert query(q=prefix)['total'] == 0, 'Fixture records persisted after removal'
print('Lantern Search CLI/HTTP passed: repeated slugs, taxonomy filters, unpublished/noindex/ACL exclusions and cleanup.')

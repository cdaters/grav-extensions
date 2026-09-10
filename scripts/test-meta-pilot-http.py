#!/usr/bin/env python3
"""Exercise an installed Meta Pilot through a disposable local DDEV site.

Usage: python3 scripts/test-meta-pilot-http.py DDEV_PROJECT [GRAV_SUBDIRECTORY]
Creates temporary pages/accounts, temporarily changes site/plugin configuration,
and restores original bytes in finally. Never point this at a shared live fixture.
Only the Python standard library and a running DDEV project are required.
"""
import hashlib
import json
from pathlib import Path
import secrets
import shutil
import ssl
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET
from html.parser import HTMLParser


project = Path(sys.argv[1]).resolve()
relative = Path(sys.argv[2] if len(sys.argv) > 2 else '.')
assert not relative.is_absolute() and '..' not in relative.parts
root = project / relative
assert (project / '.ddev/config.yaml').is_file()
assert (root / 'user/plugins/meta-pilot/meta-pilot.php').is_file()
remote = '/var/www/html' + ('/' + relative.as_posix() if str(relative) != '.' else '')


def command(args, input=None):
    result = subprocess.run(args, cwd=project, input=input, text=True,
                            capture_output=True)
    # Do not echo argv/stdout: setup commands can carry test credentials.
    assert result.returncode == 0, 'Local fixture command failed'
    return result.stdout


description = json.loads(command(['ddev', 'describe', '-j']))['raw']
base = description['primary_url'].rstrip('/')
assert urllib.parse.urlparse(base).hostname.endswith('.ddev.site'), 'DDEV only'
context = ssl._create_unverified_context()  # Local DDEV CA only.
prefix = 'metapilotcheck' + secrets.token_hex(4)
created = []
backups = {}
tokens = []


def php(code, *args, input=None):
    # Pass PHP and input bytes directly: no intervening shell interpolation.
    container = description['services']['web']['full_name']
    return command(['docker', 'exec', '-i', '-w', remote, container,
                    'php', '-r', code, *args], input)


def sync():
    command(['ddev', 'mutagen', 'sync'])
    command(['ddev', 'exec', '--dir', remote, 'php', 'bin/grav',
             'clearcache', '--cache-only'])


def request(path, token=None, data=None):
    headers = {'Content-Type': 'application/json'}
    if token:
        headers['Authorization'] = 'Bearer ' + token
    req = urllib.request.Request(base + path, headers=headers,
                                 data=json.dumps(data).encode() if data is not None else None)
    try:
        response = urllib.request.urlopen(req, context=context, timeout=30)
    except urllib.error.HTTPError as error:
        response = error
    return response.status, response.read().decode()


def json_response(path, token=None, data=None):
    status, body = request(path, token, data)
    assert status == 200, 'Unexpected HTTP status: ' + str(status)
    result = json.loads(body)
    return result.get('data', result)


def config(path):
    backups[path] = path.read_bytes() if path.exists() else None
    if not path.exists():
        return {}
    return json.loads(php("require 'vendor/autoload.php'; echo json_encode(\\Symfony\\Component\\Yaml\\Yaml::parseFile($argv[1]));",
                          str(path.relative_to(root)))) or {}


class Head(HTMLParser):
    def __init__(self):
        super().__init__()
        self.meta = {}
        self.canonical = []

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'meta':
            key = attrs.get('name', attrs.get('property', ''))
            self.meta.setdefault(key, []).append(attrs.get('content', ''))
        if tag == 'link' and attrs.get('rel') == 'canonical':
            self.canonical.append(attrs.get('href'))


try:
    site_path = root / 'user/config/site.yaml'
    plugin_path = root / 'user/config/plugins/meta-pilot.yaml'
    site = config(site_path)
    plugin = config(plugin_path)
    plugin.update({'enabled': True, 'sitemap': {'enabled': True, 'route': '/sitemap.xml',
                    'exclude_noindex': True, 'ignore_protected': True}})
    plugin_path.write_text(json.dumps(plugin))
    summary = 'A plain theme summary with <angle brackets> & a useful description for visitors.'
    fixtures = {
        'empty': ({'process': {'twig': True}}, ''),
        'summary': ({'description': summary}, ''),
        'standard': ({'description': 'Lower priority', 'metadata': {'description': summary}}, 'Body fallback.'),
        'override': ({'description': 'Lower priority', 'metadata': {'description': 'Also lower'},
                      'meta_pilot': {'description': summary, 'robots': 'index, follow'}}, 'Body fallback.'),
        'protected': ({'access': {'site.login': True}}, 'Private fixture body.'),
    }
    for name, (header, body) in fixtures.items():
        folder = root / 'user/pages' / ('99.' + prefix + name)
        assert not folder.exists()
        folder.mkdir()
        created.append(folder)
        header = {'title': prefix + name, 'published': True, 'visible': False, **header}
        (folder / 'default.md').write_text('---\n' + json.dumps(header) + '\n---\n' + body)

    accounts = []
    for allowed in [True, False]:
        username = prefix + ('reader' if allowed else 'denied')
        password = secrets.token_urlsafe(30) + 'Aa1!'
        password_hash = php('echo password_hash(stream_get_contents(STDIN), PASSWORD_DEFAULT);', input=password)
        account = root / 'user/accounts' / (username + '.yaml')
        assert not account.exists()
        account.write_text(json.dumps({'state': 'enabled', 'email': username + '@example.invalid',
            'hashed_password': password_hash, 'access': {'admin': {'login': True},
                'api': {'access': True}, 'meta-pilot': {'read': allowed}}}))
        account.chmod(0o600)
        created.append(account)
        accounts.append((username, password))

    hashes = {p / 'default.md': hashlib.sha256((p / 'default.md').read_bytes()).hexdigest()
              for p in created if p.is_dir()}
    site.setdefault('metadata', {})['robots'] = 'noindex, nofollow'
    site_path.write_text(json.dumps(site))
    sync()
    for username, password in accounts:
        token = json_response('/api/v1/auth/token', data={'username': username, 'password': password})['access_token']
        tokens.append(token)
    for path in ['/api/v1/meta-pilot/status', '/api/v1/meta-pilot/report']:
        assert request(path)[0] in [401, 403]
        assert request(path, tokens[1])[0] == 403
        assert request(path, tokens[0])[0] == 200

    for policy in ['noindex, nofollow', 'index, follow']:
        site['metadata']['robots'] = policy
        site_path.write_text(json.dumps(site))
        sync()
        report = json_response('/api/v1/meta-pilot/report', tokens[0])
        rows = {row['route']: row for row in report['pages']}
        for name in ['empty', 'summary', 'standard', 'override']:
            route = '/' + prefix + name
            expected = 'index, follow' if name == 'override' else policy
            assert rows[route]['robots'] == expected
            if name != 'empty':
                assert rows[route]['description'] == summary
            status, html = request(route)
            assert status == 200
            head = Head()
            head.feed(html)
            assert len(head.canonical) == 1
            assert head.meta['robots'] == [expected]
            assert len(head.meta['description']) == 1
            if name != 'empty':
                assert head.meta['description'] == [summary]
        status, sitemap = request('/sitemap.xml')
        assert status == 200
        locations = {node.text for node in ET.fromstring(sitemap).iter()
                     if node.tag.endswith('}loc')}
        assert base + '/' + prefix + 'override' in locations
        assert (base + '/' + prefix + 'summary' in locations) == policy.startswith('index')
        assert base + '/' + prefix + 'protected' not in locations
    assert all(hashlib.sha256(path.read_bytes()).hexdigest() == digest for path, digest in hashes.items())
finally:
    for path, content in backups.items():
        if content is None:
            path.unlink(missing_ok=True)
        else:
            path.write_bytes(content)
    for path in reversed(created):
        if path.is_dir():
            shutil.rmtree(path)
        else:
            path.unlink(missing_ok=True)
    sync()

print('Meta Pilot HTTP regression passed: empty Twig-enabled pages, description precedence/escaping,')
print('authenticated reports, anonymous/permission denial, unique head output, robots policy,')
print('sitemap exclusions, cache refresh, unchanged page bytes, and fixture cleanup.')

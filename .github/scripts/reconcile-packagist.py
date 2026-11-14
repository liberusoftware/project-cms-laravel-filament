#!/usr/bin/env python3
"""Release current standalone package code and register its Composer distribution."""
import concurrent.futures
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import threading
import time
from urllib.error import HTTPError
from urllib.request import Request, urlopen

SEMVER = re.compile(r'^v?(\d+)\.(\d+)\.(\d+)$')
lock = threading.Lock()


def command(arguments, cwd=None):
    result = subprocess.run(arguments, cwd=cwd, capture_output=True, text=True, timeout=180)
    if result.returncode:
        raise RuntimeError(result.stderr or result.stdout)
    return result.stdout.strip()


def version(value):
    found = SEMVER.fullmatch(value or '')
    return tuple(map(int, found.groups())) if found else None


def release_version(tags, declared):
    base = version(declared)
    stable = [v for tag in tags if (v := version(tag)) is not None]
    if base:
        stable = [v for v in stable if v[0] == base[0] and (base[0] or v[1] == base[1])]
    if not stable:
        return '.'.join(map(str, base or (1, 0, 0)))
    latest = max(stable)
    if base and base > latest:
        return '.'.join(map(str, base))
    return f'{latest[0]}.{latest[1]}.{latest[2] + 1}'


def request(url, payload=None, method=None, missing_ok=False):
    headers = {'Accept': 'application/json', 'User-Agent': 'Liberu-Package-Reconciliation; mailto=curtis@liberusoftware.com'}
    if payload is not None:
        headers['Content-Type'] = 'application/json'
        headers['Authorization'] = 'Bearer ' + os.environ['PACKAGIST_USERNAME'] + ':' + os.environ['PACKAGIST_API_TOKEN']
    data = json.dumps(payload).encode() if payload is not None else None
    for attempt in range(4):
        try:
            with urlopen(Request(url, data=data, headers=headers, method=method), timeout=60) as response:
                return json.load(response)
        except HTTPError as error:
            if missing_ok and error.code == 404:
                return None
            if error.code in (429, 502, 503, 504) and attempt < 3:
                time.sleep(min(int(error.headers.get('Retry-After', '10')), 60))
                continue
            raise RuntimeError(f'{url}: HTTP {error.code}') from None


def register(package, repository):
    existing = request(f'https://packagist.org/packages/{package}.json', missing_ok=True)
    if existing:
        actual = existing['package']['repository'].removesuffix('.git').rstrip('/')
        if actual.lower() != repository.lower():
            raise RuntimeError(f'{package} is registered to {actual}, expected {repository}')
    endpoint = 'update-package' if existing else 'create-package'
    response = request(f'https://packagist.org/api/{endpoint}', {'repository': repository})
    if response.get('status') != 'success':
        raise RuntimeError(f'{package}: Packagist {endpoint} did not succeed')


def publish(item):
    name, repository = item['name'], item['repository']
    with tempfile.TemporaryDirectory() as temporary:
        target = Path(temporary) / 'package'
        command(['git', 'clone', '--quiet', repository + '.git', str(target)])
        command(['git', 'config', 'user.name', 'github-actions[bot]'], target)
        command(['git', 'config', 'user.email', '41898282+github-actions[bot]@users.noreply.github.com'], target)
        before = command(['git', 'rev-parse', 'HEAD'], target)
        branch = command(['git', 'branch', '--show-current'], target)
        path = target / 'composer.json'
        original = path.read_text()
        metadata = json.loads(original)
        current_name = metadata.get('name')
        if current_name != name:
            if not item.get('repair_identity'):
                raise RuntimeError(f'{repository} declares {current_name}, expected {name}')
            metadata['name'] = name
            metadata.setdefault('extra', {}).setdefault('liberu', {})['name'] = item['installer_name']
        for dependency in item.get('remove_requirements', []):
            metadata.setdefault('require', {}).pop(dependency, None)
        metadata.setdefault('require', {}).update(item.get('dependency_requirements', {}))
        if item.get('extra_core'):
            metadata.setdefault('extra', {}).setdefault('liberu', {})['core'] = item['extra_core']
        if item.get('description'):
            metadata['description'] = item['description']
        if metadata.get('type') != item['type']:
            raise RuntimeError(f'{name}: package type differs from the project module')
        tags = command(['git', 'tag', '--list'], target).splitlines()
        head_tags = command(['git', 'tag', '--points-at', 'HEAD'], target).splitlines()
        compatible_head = [tag for tag in head_tags if version(tag) and (not metadata.get('version') or version(tag) == version(metadata['version']))]
        if compatible_head and metadata == json.loads(original):
            chosen = max(compatible_head, key=version)
        else:
            chosen = 'v' + release_version(tags, metadata.get('version') or item.get('declared_version', ''))
            if 'version' in metadata:
                metadata['version'] = chosen[1:]
            if metadata != json.loads(original):
                path.write_text(json.dumps(metadata, indent=2) + '\n')
            module = target / 'module.json'
            if module.exists():
                manifest = json.loads(module.read_text())
                manifest['version'] = chosen[1:]
                if item.get('repair_identity'):
                    manifest['name'] = item['installer_name']
                    manifest['package'] = name
                module.write_text(json.dumps(manifest, indent=2) + '\n')
            command(['composer', 'validate', '--no-check-lock', '--no-check-all', '--no-interaction', '--no-plugins', '--no-scripts'], target)
            command(['git', 'add', '--', 'composer.json'], target)
            if module.exists():
                command(['git', 'add', '--', 'module.json'], target)
            if command(['git', 'diff', '--cached', '--name-only'], target):
                command(['git', 'commit', '-m', f'chore: prepare {chosen} for Packagist'], target)
            command(['git', 'tag', '-a', chosen, '-m', f'Release {chosen}'], target)
            command(['git', 'push', '--atomic', 'origin', f'HEAD:refs/heads/{branch}', f'refs/tags/{chosen}'], target)
        reference = command(['git', 'rev-parse', 'HEAD'], target)
        register(name, repository)
        return {'name': name, 'repository': repository, 'version': chosen[1:], 'reference': reference, 'previous_reference': before}


def main():
    for key in ('GH_TOKEN', 'PACKAGIST_USERNAME', 'PACKAGIST_API_TOKEN'):
        if not os.environ.get(key):
            raise RuntimeError(f'Missing required workflow secret: {key}')
    items = json.loads(Path('.github/package-reconciliation.json').read_text())
    failures = []
    def run(item):
        try:
            result = publish(item)
            with lock:
                with Path('package-publication-results.jsonl').open('a') as output:
                    output.write(json.dumps(result) + '\n')
                print(f'{item["name"]}: published {result["version"]}', flush=True)
        except Exception as error:
            with lock:
                failures.append(item['name'])
                print(f'::error::{item["name"]}: {error}', flush=True)
    with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:
        list(pool.map(run, items))
    if failures:
        raise RuntimeError('Failed package publications: ' + ', '.join(failures))


if __name__ == '__main__':
    main()

# Release governed Maintenance setup adapters and inspection operations.

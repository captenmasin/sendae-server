import os
from pathlib import Path
import sqlite3
import subprocess
import tempfile


source = (Path(__file__).parent.parent / '.ploi/deploy.sh').read_text()
with tempfile.TemporaryDirectory(prefix='sendae-deploy-test-') as temporary:
    root = Path(temporary)
    (root / 'database').mkdir()
    (root / 'storage').mkdir()
    with sqlite3.connect(root / 'database/database.sqlite') as database:
        database.execute('create table preserved (value text)')
        database.execute('insert into preserved values (?)', ('existing data',))
    (root / '.env').write_text('APP_KEY=existing-key\n')
    (root / 'storage/oauth-private.key').write_text('existing-private-key')
    (root / 'storage/oauth-public.key').write_text('existing-public-key')
    binary = root / 'bin'
    binary.mkdir()
    (binary / 'git').write_text('#!/bin/sh\nexit 0\n')
    (binary / 'git').chmod(0o755)
    script = source.replace('{SITE_DIRECTORY}', str(root)).replace('{BRANCH}', 'master')
    for command in ['{SITE_COMPOSER}', '{SITE_PHP}', '{RELOAD_PHP_FPM}']:
        script = script.replace(command, 'true')
    environment = os.environ | {'PATH': str(binary) + os.pathsep + os.environ['PATH']}
    for _ in range(2):
        subprocess.run(['bash', '-c', script], env=environment, check=True)
    backups = list((root / 'storage/app/private/deploy-backups').iterdir())
    assert len(backups) == 2
    for backup in backups:
        assert (backup / '.env').read_text() == 'APP_KEY=existing-key\n'
        assert (backup / 'oauth-private.key').read_text() == 'existing-private-key'
        with sqlite3.connect(backup / 'database.sqlite') as database:
            assert database.execute('select value from preserved').fetchone() == ('existing data',)
        assert (backup / '.env').stat().st_mode & 0o077 == 0
    assert (root / '.env').read_text() == 'APP_KEY=existing-key\n'
print('Deployment backup test passed; no real deployment commands executed.')

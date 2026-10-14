"""Build a closed test deployment draft without reading any production secrets."""
import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import zipfile

root = Path(__file__).resolve().parent.parent
parser = argparse.ArgumentParser()
parser.add_argument('--output', required=True)
args = parser.parse_args()
output = Path(args.output).resolve()
if output.exists():
    raise SystemExit('Refusing to overwrite an existing package')
templates = root / 'app/integrasjon/testserver'
files = {}

def include(source, destination):
    path = root / source
    if not path.is_file():
        raise SystemExit(f'Missing package input: {source}')
    files[destination] = path.read_bytes()

include('app/integrasjon/testserver/closed.htaccess', 'public/.htaccess')
include('app/integrasjon/testserver/index.html', 'public/index.html')
include('app/integrasjon/testserver/api-boot.php', 'public/api/_boot.php')
include('api/integrasjon/kobling.php', 'public/api/integrasjon/kobling.php')
include('api/logg-inn.php', 'public/api/logg-inn.php')
include('api/logg-ut.php', 'public/api/logg-ut.php')
include('app/integrasjon/testserver/private-bootstrap.php', 'private/bootstrap.php')
include('app/integrasjon/testserver/secrets.example.php', 'private/secrets.example.php')
include('app/config.php', 'private/app/config.php')
for library in ['db','http','session','ratelimit','logg','auth','chatgptkurser']:
    include(f'app/lib/{library}.php', f'private/app/lib/{library}.php')
for folder in ['src', 'vendor']:
    for path in sorted((root / 'app/integrasjon' / folder).rglob('*')):
        if path.is_file():
            relative = path.relative_to(root).as_posix()
            include(relative, 'private/' + relative)
include('app/integrasjon/composer.json', 'private/app/integrasjon/composer.json')
include('app/integrasjon/composer.lock', 'private/app/integrasjon/composer.lock')
include('app/integrasjon/testserver/README.md', 'README.md')
include('app/integrasjon/testserver/restricted.htaccess.example', 'restricted.htaccess.example')
files['private/schema.sql'] = (templates / 'schema.sql').read_bytes() + b'\n' + (root / 'db/migrations/278_chatgpt_integrasjon.sql').read_bytes()
manifest = {'host':'test.lissom.no','database':'rbvapxvz_lissom_mcp_test','database_user':'rbvapxvz_mcp_test',
            'commit':subprocess.check_output(['git','rev-parse','HEAD'],cwd=root,text=True).strip(),
            'status':'closed draft; no credentials; no server activation'}
files['private/deployment.json'] = json.dumps(manifest,ensure_ascii=False,indent=2).encode('utf-8')
files['SHA256SUMS.json'] = json.dumps({name:hashlib.sha256(data).hexdigest() for name,data in sorted(files.items())},indent=2).encode('utf-8')
output.parent.mkdir(parents=True,exist_ok=True)
with zipfile.ZipFile(output,'x',compression=zipfile.ZIP_DEFLATED) as archive:
    for name,data in files.items():
        archive.writestr(name,data)
with zipfile.ZipFile(output) as archive:
    if archive.testzip() is not None or set(archive.namelist()) != set(files):
        raise SystemExit('Package verification failed')
    if any(name.endswith('/secrets.php') or name.endswith('/.env') for name in archive.namelist()):
        raise SystemExit('Unexpected secret file in package')
print(f'Closed draft package verified: {len(files)} files. No server changes.')

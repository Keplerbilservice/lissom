"""Verify the test package and exercise isolation guards before any DB connection."""
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile
import zipfile

root = Path(__file__).resolve().parent.parent
checks = 0

def check(condition, label):
    global checks
    if not condition:
        raise AssertionError(label)
    checks += 1

with tempfile.TemporaryDirectory(prefix='lissom-mcp-package-') as temporary:
    base = Path(temporary).resolve()
    check(base.parent == Path(tempfile.gettempdir()).resolve() and base.name.startswith('lissom-mcp-package-'), 'dedicated temp directory')
    package = base / 'closed-test.zip'
    subprocess.run(['python',str(root/'bin/pakk-mcp-test.py'),'--output',str(package)],check=True,capture_output=True,text=True)
    with zipfile.ZipFile(package) as archive:
        names = archive.namelist()
        sums = json.loads(archive.read('SHA256SUMS.json'))
        check(all(hashlib.sha256(archive.read(name)).hexdigest()==digest for name,digest in sums.items()), 'all package hashes verified')
        check('Require all denied' in archive.read('public/.htaccess').decode(), 'test root closed before activation')
        check('X-Robots-Tag' in archive.read('public/.htaccess').decode(), 'test host noindex configured')
        check(set(name for name in names if name.startswith('public/')) == {
            'public/.htaccess','public/index.html','public/api/_boot.php','public/api/integrasjon/kobling.php',
            'public/api/logg-inn.php','public/api/logg-ut.php'}, 'only login and integration public; no customer site or payment API')
        check(not any(name.endswith('/secrets.php') or name.endswith('/.env') for name in names), 'no actual secret files')
        check(not any('/lib/'+name+'.php' in file for file in names for name in ['tikk','vipps','varsler','booking']), 'no outgoing/payment modules')
        manifest = json.loads(archive.read('private/deployment.json'))
        check(manifest['host']=='test.lissom.no' and manifest['database']=='rbvapxvz_lissom_mcp_test', 'explicit host and dedicated database')
        check(b'CREATE TABLE IF NOT EXISTS mcp_oauth_state' in archive.read('private/schema.sql'), 'actual migration included')
        check(b'USE `rbvapxvz_lissom_mcp_test`;' in archive.read('private/schema.sql'), 'schema selects only dedicated test database')
        restricted = archive.read('restricted.htaccess.example').decode()
        check('Require valid-user' in restricted and 'THE_REQUEST' in restricted and 'Header always set X-Robots-Tag' in restricted,'restricted draft combines access control and noindex')
        check('test.lissom.no' in restricted and 'lissom-mcp-test-private/.htpasswd' in restricted,'test host and private authentication file bound')
        for name in names:
            target = (base / name).resolve()
            check(target.is_relative_to(base), 'archive path confined') if '..' in Path(name).parts else None
            if name.startswith('private/'):
                target.parent.mkdir(parents=True,exist_ok=True)
                target.write_bytes(archive.read(name))
    private = base / 'private'
    harness = base / 'guard.php'
    harness.write_text("<?php $_SERVER['HTTP_HOST']=$argv[1];$_SERVER['HTTPS']='on';register_shutdown_function(function(){echo '\\nSTATUS='.(http_response_code()?:200).' TICK='.(class_exists('Tikk',false)?'loaded':'absent');});require __DIR__.'/private/bootstrap.php';",encoding='utf-8')
    def run(host='test.lissom.no'):
        result = subprocess.run(['php',str(harness),host],capture_output=True,text=True,encoding='utf-8')
        check(result.returncode==0,'guard exits without exception')
        return result.stdout
    check('STATUS=403' in run('lissom.no'), 'production host denied before loading secrets')
    check('STATUS=503' in run(), 'missing secrets fails closed, no production fallback')
    config = {'miljo':'produksjon','nettsted':'https://test.lissom.no','tillatte_opphav':['https://test.lissom.no'],
              'db_navn':manifest['database'],'db_bruker':manifest['database_user'],'db_vert':'localhost',
              'db_passord':'unused-fixture-only','mcp_issuer':'https://test.lissom.no','mcp_test_mode':True,'mcp_enabled':False}
    def write_config(value):
        encoded = json.dumps(value,separators=(',',':'))
        # JSON here contains only synthetic, non-secret fixture configuration.
        (private/'secrets.php').write_text("<?php return json_decode('"+encoded+"',true);",encoding='utf-8')
    for key,value in [('db_navn','rbvapxvz_lissom'),('db_bruker','production-user'),('mcp_issuer','https://lissom.no'),
                      ('mcp_test_mode',False),('miljo','test'),('tillatte_opphav',['https://lissom.no'])]:
        write_config({**config,key:value})
        check('STATUS=503' in run(), 'unsafe '+key+' denied before DB connection')
    write_config(config)
    output = run()
    check('STATUS=200' in output and 'TICK=absent' in output,'isolated bootstrap loads existing session code without tick or DB access')

print(f'{checks} package/isolation checks OK; no real hosting, SQL login or Apache validation.')

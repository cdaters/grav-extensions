#!/usr/bin/env python3
"""Run real create/stage/restore CLI operations in a disposable DDEV container copy.
Usage: python3 scripts/test-site-safeguard-restore.py CONTAINER GRAV_ROOT
Never mutates the supplied Grav root; fixture, accounts and packages are temporary.
"""
import json,re,subprocess,sys,uuid
from pathlib import Path
container,source=sys.argv[1:3]
base='/tmp/safeguard-restore-contract-'+uuid.uuid4().hex
root=base+'/site'
def run(*args,check=True,input=None):
 p=subprocess.run(['docker','exec','-i','-w',root,container,*args],input=input,text=True,capture_output=True)
 if check and p.returncode:raise RuntimeError(p.stdout+p.stderr)
 return p
# Create the working directory before using it as docker's cwd.
subprocess.run(['docker','exec',container,'mkdir','-p',root],check=True,capture_output=True)
def put(rel,data):run('php','-r','file_put_contents($argv[1],stream_get_contents(STDIN));',root+'/'+rel,input=data)
def get(rel):return run('cat',root+'/'+rel).stdout
def cli(*args,check=True):return run('php','bin/plugin','site-safeguard',*args,check=check)
checks={}
try:
 run('cp','-a',source+'/.',root)
 run('rm','-rf',root+'/cache',root+'/logs',root+'/tmp',root+'/backup',root+'/user/accounts',root+'/images',root+'/assets')
 run('mkdir','-p',root+'/cache',root+'/logs',root+'/tmp',root+'/backup',root+'/user/accounts')
 run('rm','-f',root+'/.upgrading')
 # Use tested repository source even if the fixture has an older installed release.
 repo=Path(__file__).resolve().parents[1]
 put('user/plugins/site-safeguard/classes/Service/SafeguardService.php',(repo/'plugins/site-safeguard/classes/Service/SafeguardService.php').read_text())
 put('user/accounts/contract.yaml','state: enabled\naccess:\n  admin:\n    login: false\n')
 put('user/config/plugins/email.yaml','enabled: false\nmailer:\n  engine: none\n')
 put('user/config/plugins/site-safeguard.yaml','''enabled: true
package_path: ../packages
stage_path: ../stages
restore_enabled: true
restore_boot_check: true
exclude_paths:
  - cache
  - logs
  - tmp
  - backup
  - user/accounts
  - user/config/security-private.php
  - local-only
profiles:
  portable_site:
    label: Portable test site
    deployable: true
    include_paths: ["."]
    exclude_paths:
      - profile-local
''')
 run('mkdir','-p',root+'/local-only',root+'/profile-local')
 put('local-only/keep.txt','destination-only data')
 put('profile-local/keep.txt','profile-excluded data')
 put('restore-marker.txt','source version')
 made=cli('create').stdout;package=re.search(r'Package created:\s*(\S+)',made)[1]
 staged=cli('stage',package).stdout;stage=re.search(r'Verified stage created:\s*(\S+)',staged)[1]
 # Failed rollback boot must abort before touching any current files.
 original=get('index.php');poison='<?php echo "<title>Grav Problems</title>"; exit;'
 put('index.php',poison);put('restore-marker.txt','destination version')
 p=cli('restore',stage,'--confirm=RESTORE THIS SITE',check=False)
 checks['failed_preflight_returns_failure']=p.returncode!=0 and 'before site replacement' in p.stdout
 checks['failed_preflight_does_not_replace_site']=get('index.php')==poison and get('restore-marker.txt')=='destination version'
 checks['failed_preflight_keeps_account']=get('user/accounts/contract.yaml').startswith('state: enabled')
 checks['failed_preflight_leaves_no_maintenance']=run('test','!','-e',root+'/.upgrading',check=False).returncode==0
 journals=json.loads(run('php','-r','echo json_encode(array_map(fn($f)=>json_decode(file_get_contents($f),true),glob($argv[1])));',base+'/packages/.restore-*.json').stdout)
 checks['preflight_journal_retains_rollback_ids']=any(j.get('state')=='preflight-failed' and j.get('rollback_package') and j.get('rollback_stage') for j in journals)
 put('index.php',original)
 p=cli('restore',stage,'--confirm=WRONG',check=False)
 checks['confirmation_required']=p.returncode!=0 and get('restore-marker.txt')=='destination version'
 p=cli('restore',stage,'--confirm=RESTORE THIS SITE')
 checks['restore_without_packaged_accounts_succeeds']='completed and verified' in p.stdout and get('restore-marker.txt')=='source version'
 checks['excluded_account_preserved']=get('user/accounts/contract.yaml').startswith('state: enabled')
 checks['excluded_paths_preserved']=get('local-only/keep.txt')=='destination-only data' and get('profile-local/keep.txt')=='profile-excluded data'
 checks['maintenance_cleared']=run('test','!','-e',root+'/.upgrading',check=False).returncode==0
 checks['public_runtime_directories_traversable']=run('php','-r',"echo ((fileperms('images') & 0777) === 0755 && (fileperms('assets') & 0777) === 0755) ? 'yes' : 'no';").stdout=='yes'
 # A candidate that fails only after promotion must restore the verified rollback.
 put('index.php', original.replace('namespace Grav;', 'namespace Grav; if (basename(__DIR__) === "site") { echo "<title>Grav Problems</title>"; exit; }', 1))
 put('restore-marker.txt','bad candidate')
 made=cli('create').stdout;bad_package=re.search(r'Package created:\s*(\S+)',made)[1]
 staged=cli('stage',bad_package).stdout;bad_stage=re.search(r'Verified stage created:\s*(\S+)',staged)[1]
 put('index.php',original);put('restore-marker.txt','verified rollback data')
 p=cli('restore',bad_stage,'--confirm=RESTORE THIS SITE',check=False)
 checks['failed_promotion_rolls_back']=p.returncode!=0 and 'restored automatically' in p.stdout and get('index.php')==original and get('restore-marker.txt')=='verified rollback data'
 checks['rollback_keeps_excluded_account']=get('user/accounts/contract.yaml').startswith('state: enabled')
 checks['rollback_clears_maintenance']=run('test','!','-e',root+'/.upgrading',check=False).returncode==0
 print(json.dumps(checks,indent=2));assert all(checks.values())
finally:
 subprocess.run(['docker','exec',container,'rm','-rf',base],check=True,capture_output=True)

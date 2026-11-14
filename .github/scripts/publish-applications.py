"""Register public Composer applications using workflow-only credentials."""
import json,os,time,subprocess
from urllib.request import Request,urlopen
from urllib.error import HTTPError
from pathlib import Path

def request(url,payload=None,method=None):
 headers={'Accept':'application/json','User-Agent':'Liberu-application-publisher'}
 if payload is not None:
  headers.update({'Content-Type':'application/json','Authorization':'Bearer '+os.environ['PACKAGIST_USERNAME']+':'+os.environ['PACKAGIST_API_TOKEN']})
 for attempt in range(4):
  try:
   with urlopen(Request(url,data=json.dumps(payload).encode() if payload is not None else None,headers=headers,method=method),timeout=60) as r:return json.load(r)
  except HTTPError as e:
   if e.code==404 and payload is None:return None
   if e.code in (429,502,503,504) and attempt<3:time.sleep(10);continue
   # Packagist API error descriptions contain no submitted credentials.
   raise RuntimeError('HTTP '+str(e.code)+': '+e.read().decode()[:1500]) from None

def main():
 failures=[]
 for item in json.loads(Path('.github/application-publication.json').read_text()):
  repo=item['repository'];expected=item['name'];result={'repository':repo,'name':expected}
  try:
   remote=json.loads(subprocess.run(['gh','api','repos/'+repo],capture_output=True,text=True,check=True).stdout)
   if remote['private']:raise RuntimeError('Refusing to publish a private repository')
   manifest=json.loads(subprocess.run(['gh','api','repos/'+repo+'/contents/composer.json?ref=main','-H','Accept: application/vnd.github.raw+json'],capture_output=True,text=True,check=True).stdout)
   if manifest.get('name')!=expected or manifest.get('type')!='project':raise RuntimeError('Composer identity/type differs from approved application manifest')
   url='https://github.com/'+repo
   existing=request('https://packagist.org/packages/'+expected+'.json')
   if existing:
    actual=existing['package']['repository'].removesuffix('.git').rstrip('/')
    # Follow GitHub repository renames before editing a registered canonical URL.
    slug=actual.removeprefix('https://github.com/')
    canonical=json.loads(subprocess.run(['gh','api','repos/'+slug],capture_output=True,text=True,check=True).stdout)['full_name']
    if canonical.lower()!=repo.lower():raise RuntimeError('Package is registered to another repository')
    if actual.lower()!=url.lower():request('https://packagist.org/api/packages/'+expected,{'repository':url},'PUT')
   response=request('https://packagist.org/api/'+('update-package' if existing else 'create-package'),{'repository':url})
   if response.get('status')!='success':raise RuntimeError(str(response))
   result.update({'success':True,'response':response})
  except Exception as e:result.update({'success':False,'error':str(e)});failures.append(expected)
  with Path('application-publication-results.jsonl').open('a') as f:f.write(json.dumps(result)+'\n')
  print(json.dumps(result),flush=True);time.sleep(1.3)
 if failures:raise RuntimeError('Unpublished applications: '+', '.join(failures))
if __name__=='__main__':main()

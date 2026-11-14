"""Refresh Packagist metadata for verified public component repositories."""
import concurrent.futures,json,os,time,threading,subprocess
from pathlib import Path
from urllib.request import Request,urlopen
from urllib.error import HTTPError
lock=threading.Lock()
def request(url,payload=None):
 headers={'Accept':'application/json','User-Agent':'Liberu-Package-Index-Reconciliation'}
 if payload is not None:headers.update({'Content-Type':'application/json','Authorization':'Bearer '+os.environ['PACKAGIST_USERNAME']+':'+os.environ['PACKAGIST_API_TOKEN']})
 for attempt in range(5):
  try:
   with urlopen(Request(url,data=json.dumps(payload).encode() if payload is not None else None,headers=headers),timeout=60) as response:return json.load(response)
  except HTTPError as e:
   if e.code==404 and payload is None:return None
   if e.code in (429,502,503,504) and attempt<4:time.sleep(10*(attempt+1));continue
   raise RuntimeError('HTTP '+str(e.code)) from None

def run(item):
 result={'name':item['name'],'repository':item['repository']}
 try:
  if item.get('public') is not True or not item['repository'].startswith('https://github.com/liberusoftware/') or not item['name'].startswith('liberusoftware/'):raise RuntimeError('Component was not verified public under the organization')
  old=request('https://packagist.org/packages/'+item['name']+'.json')
  if old:
   actual=old['package']['repository'].removesuffix('.git').rstrip('/')
   if actual.lower()!=item['repository'].lower():
    if not actual.startswith('https://github.com/'):raise RuntimeError('Registered repository is outside GitHub')
    with urlopen(Request(actual,headers={'User-Agent':'Liberu-Package-Index-Reconciliation'}),timeout=60) as response:canonical=response.geturl().removesuffix('.git').rstrip('/')
    if canonical.lower()!=item['repository'].lower():raise RuntimeError('Registered repository identity differs')
    # Existing renamed URLs are still valid; update by the registered package URL.
   response=request('https://packagist.org/api/update-package',{'repository':'https://packagist.org/packages/'+item['name']})
  else:response=request('https://packagist.org/api/create-package',{'repository':item['repository']})
  if response.get('status')!='success':raise RuntimeError('Publication not accepted')
  result.update({'success':True,'jobs':response.get('jobs',[])})
 except Exception as e:result.update({'success':False,'error':str(e)})
 with lock:
  with Path('package-index-results.jsonl').open('a') as f:f.write(json.dumps(result)+'\n')
  print(json.dumps(result),flush=True)
 time.sleep(1.2)
 return result
if __name__=='__main__':
 items=json.loads(Path('.github/component-index-manifest.json').read_text())
 with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:results=list(pool.map(run,items))
 failures=[r['name'] for r in results if not r['success']]
 if failures:raise RuntimeError('Index refresh failed for: '+', '.join(failures))

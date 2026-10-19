import json, urllib.request, urllib.error
import os
BASE=os.environ.get('E2E_API_URL','http://127.0.0.1:8000/api')
class Api:
    def __init__(s, login, pw='Demo-pass-2026'):
        r=s.call('POST','/login',{'login':login,'password':pw,'force':True},auth=False)
        s.token=r['token']; s.user=r['user']
    def call(s, m, path, body=None, auth=True, ok=True):
        h={'Content-Type':'application/json','Accept':'application/json','Origin':os.environ.get('E2E_ORIGIN','http://localhost:5173')}
        if auth: h['Authorization']='Bearer '+s.token
        req=urllib.request.Request(BASE+path, data=None if body is None else json.dumps(body).encode(), method=m, headers=h)
        try:
            with urllib.request.urlopen(req) as resp:
                t=resp.read(); return json.loads(t) if t else {}
        except urllib.error.HTTPError as e:
            t=e.read().decode()
            if ok: raise RuntimeError(f'{m} {path} -> {e.code}: {t[:1500]}')
            return {'_status':e.code, **(json.loads(t) if t.startswith('{') else {})}

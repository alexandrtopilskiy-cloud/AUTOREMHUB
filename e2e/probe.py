import sys, json; sys.path.insert(0,'.'); from api import Api
R=[]
def check(n, name, cond, detail=''):
    R.append((n, name, bool(cond), detail)); print(('OK  ' if cond else 'FAIL'), n, name, detail)
def walk_keys(o, acc):
    if isinstance(o, dict):
        for k,v in o.items(): acc.add(k); walk_keys(v, acc)
    elif isinstance(o, list):
        for v in o: walk_keys(v, acc)
    return acc

uk=Api('uk.ivanov'); cu=Api('alfa.petrova'); st=Api('kub.master'); ov=Api('oval.master')
recs=uk.call('GET','/catalogs')['records']
kub=next(r for r in recs if r['kind']=='stations' and 'КУБ' in r['name'])
ovl=next(r for r in recs if r['kind']=='stations' and 'Овал' in r['name'])
veh=uk.call('GET','/vehicles')
veh=(veh.get('vehicles') or veh.get('records') or veh.get('data'))[0]
reason=next(r for r in recs if r['kind']=='reasons' and r['name']=='ТО')

# 33: customer JSON of closed RP-000001 has no purchase / supplier data
doc=cu.call('GET','/requests/RP-000001')
keys=walk_keys(doc,set())
bad=[k for k in keys if any(x in k.lower() for x in ('purchase','supplier'))]
check(33,'Заказчику не приходят supplier/purchase в JSON заявки', not bad, str(bad))
ukdoc=uk.call('GET','/requests/RP-000001')
check(33,'УК видит закупочную цену в той же заявке', any('purchase' in k.lower() for k in walk_keys(ukdoc,set())))

# 3: other station cannot read RP-000001; customer cannot read unknown uuid
r=ov.call('GET','/requests/RP-000001', ok=False)
check(3,'Чужая СТО не открывает заявку по номеру', r.get('_status') in (403,404), str(r.get('_status')))

# 26: norm resolve 1.25 x 2400 x 2 = 6000
wt=next(r for r in recs if r['kind']=='workTypes' and 'масла' in r['name'])
res=uk.call('GET',f"/time-norms/resolve?workTypeId={wt['id']}&vehicleId={veh['id']}&quantity=2")['result']
check(26,'Подбор нормы: 1,25 × 2400 × 2 = 6000', float(res['amount'])==6000 and res['status']=='selected', json.dumps({k:res[k] for k in ('status','scope','laborHourRate','unitPrice','amount')},ensure_ascii=False))
res=uk.call('GET',f"/time-norms/resolve?workTypeId={wt['id']}&vehicleId={veh['id']}&quantity=2&laborRateType=official")['result']
check(27,'Официальный тариф 3200: 1,25 × 3200 × 2 = 8000', float(res['amount'])==8000, str(res['amount']))


people=cu.call('GET',f"/vehicles/{veh['id']}/contacts")['contacts']
def new_draft():
    d=cu.call('POST','/requests')['request']
    return d
def patch(api, d, p, ok=True):
    r=api.call('PATCH',f"/requests/{d['id']}",{'revision':d['revision'],'patch':p}, ok=ok)
    return r.get('request', r)
def act(api, d, path, body, ok=True):
    r=api.call('POST',f"/requests/{d['id']}/{path}",{'revision':d['revision'],**body}, ok=ok)
    return r.get('request', r)

# 62: own fresh draft is deletable
d=new_draft(); r=cu.call('DELETE',f"/requests/{d['id']}", ok=False)
check(62,'Собственный новый черновик удаляется', r.get('_status') is None, str(r.get('_status')))

# 34 + 9 + 14
d=new_draft()
r=patch(cu,d,{'vehicleId':veh['id'],'mileage':'52400','reasonId':reason['id'],'contactId':people[0]['id'],'_clientId':'x1'},ok=False)
check(14,'Служебное поле _clientId в patch отклоняется (422), а не пишется в БД', r.get('_status')==422, json.dumps(r.get('error',{}),ensure_ascii=False)[:160])
d=patch(cu,d,{'vehicleId':veh['id'],'mileage':'52400','reasonId':reason['id'],'contactId':people[0]['id']})
stale=dict(d); stale['revision']=d['revision']-1
r=patch(cu,stale,{'description':'устаревшая правка'},ok=False)
check(9,'Устаревшая revision → 409, без перезаписи', r.get('_status')==409, r.get('error',{}).get('code',''))
r=patch(cu,d,{'mileage':'40000'},ok=False)
check(18,'Пробег меньше предыдущего достоверного отклоняется (422)', r.get('_status')==422, r.get('error',{}).get('message','')[:120])
d=act(cu,d,'next',{'comment':''})
check(34,'Отправка в УК с 4 обязательными полями, без времени/фото/комментария', d['stage']==1, f"stage={d['stage']}")
# 62b: after route and return to draft — not deletable
d=act(uk,d,'return',{'comment':'Уточните описание'})
r=cu.call('DELETE',f"/requests/{d['id']}", ok=False)
check(62,'Черновик, побывавший в маршруте, не удаляется', r.get('_status') in (403,409,422), f"{r.get('_status')} {r.get('error',{}).get('message','')[:100]}")
d=act(cu,d,'next',{'comment':''})
# 24: UK cannot send without priced work
d=patch(uk,d,{'stationId':kub['id'],'slot':'2026-10-22T12:00'})
r=act(uk,d,'next',{'comment':''},ok=False)
check(35,'УК → СТО без оценённой работы запрещено', r.get('_status')==422, r.get('error',{}).get('message','')[:120])
d=patch(uk,d,{'works':[{'workTypeId':wt['id'],'name':wt['name'],'qty':2}]})
check(26,'Строка сметы на сервере: 1,25 × 2400 × 2 = 6000', float(d['works'][0]['amount'])==6000, str(d['works'][0].get('amount')))
d=act(uk,d,'next',{'comment':''})
check(35,'УК → СТО без диалога комментария', d['stage']==2)
# 37: decline needs comment
r=st.call('POST',f"/requests/{d['id']}/station-decision",{'revision':d['revision'],'decision':'declined','comment':''},ok=False)
check(37,'Отказ СТО без комментария → 422', r.get('_status')==422)
r=st.call('POST',f"/requests/{d['id']}/station-decision",{'revision':d['revision'],'decision':'declined','comment':'Нет свободного поста'})
d=r.get('request',r)
check(37,'Отказ СТО → stage 1 «Маршрутизация УК (отказ СТО)»', d['stage']==1 and 'отказ' in d.get('statusLabel','').lower(), d.get('statusLabel'))
# 38: same station again
r=act(uk,d,'next',{'comment':''},ok=False)
check(38,'Повторное назначение отказавшей СТО → 409 STATION_PREVIOUSLY_DECLINED', r.get('error',{}).get('code')=='STATION_PREVIOUSLY_DECLINED', r.get('error',{}).get('code'))
d=act(uk,d,'next',{'comment':'','confirmDeclinedStation':True})
check(38,'С confirmDeclinedStation=true заявка уходит в СТО', d['stage']==2)
# 7: document lock — second user of same org sees owner
stb=Api('kub.second')
l1=st.call('POST','/document-locks',{'resourceType':'request','resourceKey':d['id'],'label':d['number']})
l2=stb.call('POST','/document-locks',{'resourceType':'request','resourceKey':d['id'],'label':d['number']},ok=False)
check(7,'Блокировка: второй сотрудник СТО получает 423 с владельцем', l2.get('_status') in (409,423) and 'Кузнецов' in json.dumps(l2,ensure_ascii=False), f"HTTP {l2.get('_status')} "+l2.get('error',{}).get('message',''))
r=cu.call('GET',f"/requests/{d['id']}")
check(8,'Read-only открытие Заказчиком не мешает: СТО держит блокировку', l1.get('lock') is not None or 'expiresAt' in json.dumps(l1), json.dumps(l1,ensure_ascii=False)[:160])
st.call('DELETE',f"/document-locks/{(l1.get('lock') or l1).get('id')}",ok=False)
# 5/6: sessions
r=Api.__new__(Api); r.token=''
res=r.call('POST','/login',{'login':'kub.master','password':'Demo-pass-2026'},auth=False,ok=False)
check(5,'Второй вход без force → 409 SESSION_ACTIVE', res.get('error',{}).get('code')=='SESSION_ACTIVE')
st2=Api('kub.master')
res=st.call('GET','/session',ok=False)
check(5,'После force-входа старый токен получает 401', res.get('_status')==401, str(res.get('_status')))
# 15: customer-created vehicle is pending, not in working list
before=len(cu.call('GET','/vehicles')['vehicles'])
car=cu.call('POST','/catalogs/vehicles',{'record':{'make':'Toyota','model':'Camry','vehicleModelId':'2d50b92e-e76a-46bf-91cf-e9d6b8371039','vin':'XW7BF4FK10S654321','plate':'В001ВВ178','year':2020,'contactPersonIds':[],'driverPersonIds':[]}})
after=len(cu.call('GET','/vehicles')['vehicles'])
check(15,'Новое ТС Заказчика ждёт акцепта УК и не появляется в перечне', car.get('pendingApproval') is True and before==after, f"pending={car.get('pendingApproval')} {before}->{after}")
notes=uk.call('GET','/notifications')
check(15,'УК получает уведомление о запросе изменения ТС', any(n.get('kind')=='vehicle_catalog_approval' for n in (notes.get('notifications') or notes.get('items') or [])))
# 18: VIN format
r=uk.call('POST','/catalogs/vehicles',{'record':{'make':'Toyota','model':'Camry','vehicleModelId':'2d50b92e-e76a-46bf-91cf-e9d6b8371039','vin':'XW7BF4FK10S65432O','organizationId':veh.get('organizationId'),'contactPersonIds':[],'driverPersonIds':[]}},ok=False)
check(18,'Неверный VIN → 422 с подсказкой формата', r.get('_status')==422 and '17' in json.dumps(r,ensure_ascii=False), json.dumps(r.get('error',{}).get('fields',{}),ensure_ascii=False)[:160])
# 19: duplicate technical variant
dup=uk.call('POST','/vehicle-reference/catalog',{'record':{'make':'Toyota','model':'Camry','modelYearFrom':2017,'modelYearTo':2024,'bodyCode':'ASV70','engineIndex':'2AR-FE','horsepower':181,'engineVolumeLiters':2.5,'transmission':'АКПП','driveType':'передний','engineType':'Бензин'}},ok=False)
check(19,'Полный дубль технического варианта → 409 VEHICLE_VARIANT_DUPLICATE', dup.get('error',{}).get('code')=='VEHICLE_VARIANT_DUPLICATE', dup.get('error',{}).get('code',str(dup)[:120]))
new=uk.call('POST','/vehicle-reference/catalog',{'record':{'make':'Toyota','model':'Camry','modelYearFrom':2017,'modelYearTo':2024,'bodyCode':'AXV70A','engineIndex':'A25A-FKS','horsepower':200,'engineVolumeLiters':2.5,'transmission':'АКПП','driveType':'передний','engineType':'Бензин'}},ok=False)
check(19,'Новый кузов AXV70A сохраняется как есть', 'AXV70A' in json.dumps(new,ensure_ascii=False), json.dumps(new,ensure_ascii=False)[:160])
print(json.dumps(R,ensure_ascii=False), file=open('probe-results.json','w'))
print('TOTAL', sum(1 for r in R if r[2]), '/', len(R))

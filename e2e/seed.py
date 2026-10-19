import sys, json; sys.path.insert(0,'.'); from api import Api
uk=Api('uk.ivanov')
recs=uk.call('GET','/catalogs')['records']
org=lambda kind,name: next(r for r in recs if r['kind']==kind and r['name']==name)
alfa=org('customers','ООО "Альфа"'); kub=org('stations','ООО "КУБ"')
general=next(r for r in recs if r['kind']=='normApplicabilities' and r['code']=='general')
P=lambda kind,ln,fn,mn,ph: uk.call('POST','/catalogs/people',{'record':{'kind':kind,'organizationId':alfa['id'],'lastName':ln,'firstName':fn,'middleName':mn,'phone':ph}})['record']
c1=P('contact','Смирнова','Елена','Викторовна','+7 (921) 555 10 20')
d1=P('driver','Козлов','Артём','Сергеевич','+7 (921) 555 30 40')
veh=uk.call('POST','/catalogs/vehicles',{'record':{'make':'Toyota','model':'Camry','vehicleModelId':'2b7e0a50-4812-4bc9-ad3e-bc22f895725b',
  'vin':'XW7BF4FK10S123456','plate':'А777МР178','year':2021,'organizationId':alfa['id'],'baselineServiceMileage':41200,'baselineServiceAt':'2026-04-10',
  'laborHourRate':2400,'laborHourRateOfficial':3200,'laborHourRateOther':1900,'laborRateCoefficient':1,
  'contactPersonIds':[c1['id']],'driverPersonIds':[d1['id']]}})
print('vehicle', json.dumps(veh,ensure_ascii=False)[:300])
works=[('Двигатель - замена масла и масляного фильтра','Техническое обслуживание',True,1.25),
       ('Тормозные колодки передние - замена','Тормозная система',False,0.8),
       ('Диагностика ходовой части','Подвеска',False,0.5)]
for n,g,to,h in works:
    r=uk.call('POST','/catalogs/workTypes',{'record':{'name':n,'group':g,'isMaintenance':to,'active':True,
       'timeNorms':[{'applicabilityId':general['id'],'hours':h,'source':'Демо-данные для проверки'}]}})
    print('work', r['record'].get('code'), r['record'].get('name'))
bosch=next(r for r in recs if r['kind']=='partManufacturers' and r['name'].upper()=='BOSCH')
sup=uk.call('POST','/catalogs/suppliers',{'record':{"legalForm":"ooo","name":"ООО «Деталь-Снаб»","shortName":"Деталь-Снаб","taxId":"7707083893","kpp":"773601001","ogrn":"1027700132195","registrationDate":"2020-01-15","directorName":"Морозов Павел Андреевич","legalAddress":"Санкт-Петербург, ул. Примерная, 1","actualAddress":"Санкт-Петербург, ул. Примерная, 1","postalAddress":"Санкт-Петербург, ул. Примерная, 1","bankName":"Банк","bik":"044525225","settlementAccount":"40702810900000002851","correspondentAccount":"30101810400000000225","taxRegime":"ОСНО","vatRate":"НДС 20%","contacts":[{"lastName":"Морозов","firstName":"Павел","phone":"+7 (999) 123 45 67","email":"supply@example.ru","position":"Менеджер"}],"active":True}})['record']
for n,a,u,pp,sp in [('Фильтр масляный','0 451 103 316','шт.',540,890),('Колодки тормозные передние','0 986 494 864','компл.',3100,4650)]:
    r=uk.call('POST','/catalogs/parts',{'record':{'name':n,'manufacturerId':bosch['id'],'article':a,'unit':u,'supplierId':sup['id'],'purchasePrice':pp,'salePrice':sp}})
    print('part', r['record'].get('name'), r['record'].get('createdByOrganization'))
uk.call('POST','/logout')

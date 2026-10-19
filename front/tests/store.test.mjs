import test from 'node:test'
import assert from 'node:assert/strict'
import {createRequestStore,stripClientMetadata} from '../src/stores/requestStore.js'
import {createApiClient,ApiError} from '../src/api/client.js'
import {readRequest} from '../src/api/marketplace.js'
import {requestFields} from '../src/data/permissions.js'
import {workflow,statusClass} from '../src/data/workflow.js'
const record=()=>({id:'dc917bc8-66aa-45f8-82ec-4c20fcb8da8f',number:'RP-000001',revision:0,stage:0,...Object.fromEntries(requestFields.map(k=>[k,k==='works'?[]:''])),parts:[],permissions:{editableFields:requestFields,canAdvance:true,canDeleteDraft:true}})
const fixture=()=>{let rows=[];let notifications=[];return {listRequests:async()=>structuredClone(rows),listCatalogs:async()=>[],references:async()=>({reasons:[]}),listNotifications:async()=>structuredClone(notifications),chatSummary:async()=>({chats:[],unreadTotal:0}),readNotification:async id=>{const index=notifications.findIndex(item=>item.id===id);const [item]=notifications.splice(index,1);return {...structuredClone(item),deleted:true}},createRequest:async()=>{const r=record();rows.push(r);return structuredClone(r)},deleteDrafts:async ids=>{rows=rows.filter(row=>!ids.includes(row.id));return structuredClone(ids)},updateRequest:async(id,revision,patch)=>{const r=rows.find(r=>r.id===id);assert.equal(r.revision,revision);Object.assign(r,patch);r.revision++;return structuredClone(r)},transition:async(id,revision)=>{const r=rows.find(r=>r.id===id);r.stage=1;r.revision++;return structuredClone(r)},stationDecision:async(id,revision,decision)=>{const r=rows.find(r=>r.id===id);r.stage=decision==='declined'?1:3;r.revision++;return structuredClone(r)},addNotification:item=>notifications.push(item)}}
test('empty initial store and creation: no fabricated data',async()=>{const s=createRequestStore(fixture());await s.refresh();assert.deepEqual(s.requests,[]);const r=await s.create();assert.equal(r.number,'RP-000001');assert.equal(r.vehicleId,'');assert.deepEqual(r.works,[]);s.reset()})
test('new API requires RP number and supports payment stages',()=>{assert.equal(readRequest({...record(),stage:11}).stage,11);assert.throws(()=>readRequest({...record(),number:undefined}));assert.throws(()=>readRequest({...record(),stage:12}))})
test('workflow exposes the agreed operational statuses',()=>{assert.deepEqual(workflow.slice(0,7).map(step=>step.status),['Черновик','Маршрутизация УК','Согласование СТО','Согласование Заказчика','Ожидание ТС на СТО','В работе','Готово'])})
test('changes are saved only after an explicit save',async()=>{const api=fixture();const s=createRequestStore(api);const r=await s.create();let calls=0;const original=api.updateRequest;api.updateRequest=async(...args)=>{calls++;return original(...args)};r.description='Текст';s.touch(r.id);await new Promise(resolve=>setTimeout(resolve,800));assert.equal(calls,0);assert.equal(s.dirty,true);await s.save(r.id);assert.equal(calls,1);assert.equal(s.dirty,false);s.reset()})
test('edits made during save remain pending until the next click',async()=>{const api=fixture();const s=createRequestStore(api);const r=await s.create();let finish;const original=api.updateRequest;let first=true;api.updateRequest=async(...args)=>{if(first){first=false;await new Promise(resolve=>finish=resolve)}return original(...args)};r.description='Первый текст';s.touch(r.id);const save=s.save(r.id);await new Promise(resolve=>setTimeout(resolve,0));r.description='Уточнённый текст';s.touch(r.id);finish();await save;assert.equal(s.get(r.id).description,'Уточнённый текст');assert.equal(s.get(r.id).revision,1);assert.equal(s.dirty,true);await s.save(r.id);assert.equal(s.get(r.id).revision,2);assert.equal(s.dirty,false);s.reset()})
test('transition is blocked until pending edits are explicitly saved',async()=>{const api=fixture(),s=createRequestStore(api);const r=await s.create();r.contactId='contact-uuid';s.touch(r.id);await assert.rejects(s.act(r.id,'transition','next',{comment:'Согласовано',attachmentIds:['photo']}),/Сначала сохраните/);await s.save(r.id);let payload;const original=api.transition;api.transition=async(...args)=>{payload=args;return original(...args)};await s.act(r.id,'transition','next',{comment:'Согласовано',attachmentIds:['photo']});assert.equal(payload[1],1);assert.deepEqual(payload[3],{comment:'Согласовано',attachmentIds:['photo']});assert.equal(s.get(r.id).stage,1);s.reset()})
test('discard restores server baseline',async()=>{const s=createRequestStore(fixture());const r=await s.create();r.description='Не сохранять';s.touch(r.id);s.discard(r.id);assert.equal(r.description,'');assert.equal(s.dirty,false);s.reset()})
test('conflict retains edits',async()=>{const api=fixture(),s=createRequestStore(api);const r=await s.create();r.description='Не потерять';s.pending[r.id]=true;api.updateRequest=async()=>{throw new ApiError('Conflict',409)};await assert.rejects(s.save(r.id));assert.equal(s.get(r.id).description,'Не потерять');assert.equal(s.conflict.id,r.id);s.reset()})
test('derived vehicle data is not submitted in PATCH',async()=>{const s=createRequestStore(fixture());const r=await s.create();r.vehicle='Автомобиль';r.vin='VIN';r.previousServiceMileage=12000;r.vehicleId='uuid';assert.deepEqual(s.changed(r.id),{vehicleId:'uuid'});s.reset()})
test('client-only row identifiers are never submitted to API',()=>{assert.deepEqual(stripClientMetadata([{_clientId:'row-1',name:'Работа',nested:{_ui:true,value:1}}]),[{name:'Работа',nested:{value:1}}])})
test('JSON transport includes bearer authentication',async()=>{let call;const c=createApiClient({baseUrl:'/api',timeoutMs:1000},async(url,init)=>{call={url,...init};return new Response('{}')});c.setToken('token');await c.request('/requests',{method:'POST',body:{}});assert.equal(call.headers.Authorization,'Bearer token');assert.equal(call.body,'{}')})
test('multipart upload preserves FormData and lets browser set boundary',async()=>{let call;const c=createApiClient({baseUrl:'/api'},async(url,init)=>{call=init;return new Response('{}')});const body=new FormData();body.append('revision','0');await c.request('/upload',{method:'POST',body,timeoutMs:1000});assert.equal(call.body,body);assert.equal(call.headers['Content-Type'],undefined)})
test('401 expires session and preserves error message',async()=>{let expired=false;const c=createApiClient({baseUrl:'/api'},async()=>new Response(JSON.stringify({error:{message:'Войдите'}}),{status:401}));c.setUnauthorizedHandler(()=>expired=true);await assert.rejects(c.request('/session'),e=>e.status===401&&e.message==='Войдите');assert.equal(expired,true)})
test('role permissions come solely from API',()=>{const r=readRequest({...record(),permissions:{editableFields:[],canAdvance:false,canEditParts:false}});assert.deepEqual(r.permissions.editableFields,[]);assert.equal(r.permissions.canEditParts,false)})
test('station decision uses the dedicated API action',async()=>{const api=fixture(),s=createRequestStore(api);const r=await s.create();r.permissions.canStationDecide=true;await s.act(r.id,'stationDecision','confirmed',{comment:''});assert.equal(s.get(r.id).stage,3);s.reset()})
test('read notification is removed for every role',async()=>{const api=fixture();api.addNotification({id:'notice-1',requestId:'request-1',readAt:null});const s=createRequestStore(api);await s.refresh();assert.equal(s.notifications.length,1);await s.readNotification('notice-1');assert.equal(s.notifications.length,0);s.reset()})
test('own draft is removed only by an explicit delete command',async()=>{const api=fixture();const s=createRequestStore(api);const r=await s.create();s.touch(r.id);const deleted=await s.deleteDrafts([r.id]);assert.deepEqual(deleted,[r.id]);assert.equal(s.get(r.id),undefined);assert.equal(s.dirty,false);s.reset()})
test('live refresh never reloads heavy catalogs or reference data',async()=>{const api=fixture();let catalogs=0,references=0;api.listCatalogs=async()=>{catalogs++;return[]};api.references=async()=>{references++;return{reasons:[]}};const s=createRequestStore(api);api.addNotification({id:'notice-live',readAt:null});await s.refreshLive();assert.equal(catalogs,0);assert.equal(references,0);assert.equal(s.notifications[0].id,'notice-live');s.reset()})
test('chat unread state stays until the API read cursor advances',async()=>{const api=fixture();let unread=2;api.chatSummary=async()=>({chats:[{requestId:record().id,requestNumber:'RP-000001',unreadCount:unread}],unreadTotal:unread});const s=createRequestStore(api);await s.refresh();assert.equal(s.chatUnreadTotal,2);await s.refreshLive();assert.equal(s.chatUnreadTotal,2);unread=0;await s.refreshChatSummaries();assert.equal(s.chatUnreadTotal,0);s.reset()})
test('catalog mutation is applied locally without a full reload',()=>{const s=createRequestStore(fixture());s.upsertRecord({id:'vehicle-1',kind:'vehicles',vehicle:'Jetour Dashing'});assert.equal(s.records[0].vehicle,'Jetour Dashing');s.upsertRecord({id:'vehicle-1',kind:'vehicles',vehicle:'Jetour Dashing 2025'});assert.equal(s.records.length,1);assert.equal(s.records[0].vehicle,'Jetour Dashing 2025');s.removeRecord('vehicle-1');assert.equal(s.records.length,0);s.reset()})

test('parts and work changes use a single explicit save without UI or approval metadata', async () => {
    const api = fixture();
    const store = createRequestStore(api);
    const request = await store.create();
    const calls = [];
    const original = api.updateRequest;
    api.updateRequest = async (...args) => { calls.push(args); return original(...args); };
    request.works = [{name: 'Work', qty: 1, normHours: 2, _clientId: 'w1'}];
    request.parts = [{_clientId: 'p1', catalogPartId: 'catalog-1', name: 'Filter', article: 'A1', unit: 'шт.', supplierId: 's1', qty: 2, price: 350, status: 'approved', manufacturer: 'Brand', approvedVersion: 9}];
    store.touch(request.id);
    assert.equal(calls.length, 0);
    await store.save(request.id);
    assert.equal(calls.length, 1);
    assert.equal(calls[0][2].parts[0].price, 350);
    assert.equal(calls[0][2].works[0].normHours, 2);
    assert.equal('_clientId' in calls[0][2].parts[0], false);
    assert.equal('approvedVersion' in calls[0][2].parts[0], false);
    assert.equal(store.dirty, false);
});

test('failed common save keeps parts locally and discard restores their baseline', async () => {
    const api = fixture();
    const store = createRequestStore(api);
    const request = await store.create();
    request.parts.push({catalogPartId: 'new', name: 'Part', qty: 1, price: 100});
    store.touch(request.id);
    api.updateRequest = async () => { throw new ApiError('Validation failed', 422); };
    await assert.rejects(store.save(request.id));
    assert.equal(store.dirty, true);
    assert.equal(request.parts[0].name, 'Part');
    store.discard(request.id);
    assert.deepEqual(request.parts, []);
});

test('request status depends on current role action and closure', () => {
    assert.equal(statusClass(1, {requiresAction: true}), 'red');
    assert.equal(statusClass(1, {requiresAction: false, canAdvance: true}), 'gray');
    assert.equal(statusClass(2, {canStationDecide: true}), 'red');
    assert.equal(statusClass(11, {requiresAction: false}), 'green');
});

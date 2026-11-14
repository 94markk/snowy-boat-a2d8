const fs=require('fs'),vm=require('vm'),assert=require('assert');
const source=fs.readFileSync(__dirname+'/../includes/class-dfr-catalog.php','utf8');
const start=source.indexOf('(function(){\n  function safeRows');
const script=source.slice(start,source.indexOf('</script>',start));
async function scenario(edit){
 const handlers={},token={value:'previous-token'},status={style:{},textContent:''},label={textContent:'Vérifier'};
 const field={value:'8593173765',required:true,getAttribute:()=> 'uid',matches:()=>true,closest:()=>wrap};
 const box={dataset:{ajax:'/ajax',nonce:'test',abortMs:'22000'},querySelector:s=>s==='.dfr-uid-status'?status:null};
 const btn={disabled:false,closest:s=>s==='.dfr-native-fields'?wrap:s==='.dfr-uid-verify'?box:null,querySelector:()=>label};
 const wrap={isConnected:true,dataset:{dfrProduct:'5'},querySelector:s=>s==='.dfr-uid-token'?token:s==='.dfr-uid-verify'?box:null,querySelectorAll:()=>[field]};
 let resolve;
 const document={querySelectorAll:()=>[],addEventListener:(e,f)=>(handlers[e]??=[]).push(f)};
 const context={document,window:{},URLSearchParams,fetch:()=>new Promise(r=>resolve=r),setTimeout,clearTimeout,localStorage:{getItem:()=>null}};
 vm.runInNewContext(script,context);
 const target={closest:s=>s==='.dfr-uid-verify-btn'?btn:null};
 handlers.click.forEach(f=>f({target}));assert.equal(token.value,'','Old token cleared before recheck');
 if(edit){field.value='999';handlers.input.forEach(f=>f({target:field}));field.value='8593173765';handlers.change.forEach(f=>f({target:field}));}
 resolve({json:()=>Promise.resolve({success:true,data:{valid:true,token:'fresh-token',player_name:'꧁☯ⓐⓒⓗⓘⓛⓛⓔ☯꧂'}})});
 await new Promise(r=>setImmediate(r));
 assert.equal(token.value,edit?'':'fresh-token');
 if(edit)assert.equal(status.style.display,'none');else assert(status.textContent.includes('꧁☯ⓐⓒⓗⓘⓛⓛⓔ☯꧂'));
 assert.equal(btn.disabled,false);
}
// 4.24: a page served from cache carries an expired nonce; renew it once, never loop.
async function expiredNonce(){
 const handlers={},token={value:''},status={style:{},textContent:''},label={textContent:'Vérifier'};
 const field={value:'123',required:true,getAttribute:()=> 'uid',matches:()=>true,closest:()=>wrap};
 const box={dataset:{ajax:'/ajax',nonce:'old',abortMs:'22000'},querySelector:s=>s==='.dfr-uid-status'?status:null};
 const btn={disabled:false,closest:s=>s==='.dfr-native-fields'?wrap:s==='.dfr-uid-verify'?box:null,querySelector:()=>label};
 const wrap={isConnected:true,dataset:{dfrProduct:'5'},querySelector:s=>s==='.dfr-uid-token'?token:s==='.dfr-uid-verify'?box:null,querySelectorAll:()=>[field]};
 const calls=[],answers=[{success:false,data:{code:'nonce',message:'expired'}},{success:true,data:{nonce:'fresh'}},{success:true,data:{valid:true,token:'tok'}}];
 const document={querySelectorAll:()=>[],addEventListener:(e,f)=>(handlers[e]??=[]).push(f)};
 const fetch=(url,opts)=>{calls.push(new URLSearchParams(opts.body));return Promise.resolve({json:()=>Promise.resolve(answers.shift())});};
 vm.runInNewContext(script,{document,window:{},URLSearchParams,fetch,setTimeout,clearTimeout,localStorage:{getItem:()=>null,setItem(){}}});
 const click=()=>handlers.click.forEach(f=>f({target:{closest:s=>s==='.dfr-uid-verify-btn'?btn:null}}));
 click();await new Promise(r=>setTimeout(r,20));
 assert.deepEqual(calls.map(c=>c.get('action')+':'+(c.get('nonce')||'')),['dfr_verify_player_uid:old','dfr_uid_nonce:','dfr_verify_player_uid:fresh']);
 assert.equal(token.value,'tok');assert.equal(box.dataset.nonce,'fresh');
 answers.push({success:false,data:{code:'nonce',message:'expired'}},{success:true,data:{nonce:'n3'}},{success:false,data:{code:'nonce',message:'still expired'}});
 const before=calls.length;click();await new Promise(r=>setTimeout(r,20));
 assert.equal(calls.length-before,3);assert.equal(status.textContent,'still expired');
}
(async()=>{await scenario(false);await scenario(true);await expiredNonce();console.log('PASS current Unicode name; stale response after editing away and back rejected; old tokens cleared; an expired cached-page nonce is renewed once');})().catch(e=>{console.error(e);process.exit(1);});

(function(){'use strict';
 const root=document.querySelector('.dxf-workspace');if(!root)return;
 const platform=root.querySelector('[name="platform"]'),format=root.querySelector('[name="format"]');
 function formats(){const allowed={google:['xml','tsv'],meta:['xml','csv','tsv'],pinterest:['xml','csv','tsv'],microsoft:['xml','tsv'],generic:['xml','csv','tsv','json']}[platform.value];Array.from(format.options).forEach(o=>{o.disabled=!allowed.includes(o.value);});if(!allowed.includes(format.value))format.value='xml';}
 platform.addEventListener('change',formats);formats();
 const mode=root.querySelector('[name="shipping_mode"]');function shipping(){root.querySelector('[data-shipping-fields]').hidden=mode.value!=='manual';}mode.addEventListener('change',shipping);shipping();
 root.addEventListener('click',e=>{const policy=e.target.closest('[data-policy-target]');if(policy){const input=root.querySelector('[name="'+policy.dataset.policyTarget+'"]');input.value=policy.dataset.policyUrl;input.focus();}
  if(e.target.closest('[data-dxf-presets]'))root.querySelectorAll('[data-category-name]').forEach(input=>{if(input.value.trim())return;const name=input.dataset.categoryName.toLowerCase();if(/perik|\bwig/.test(name))input.value='181';else if(/eksten|extension|ponytail|repov|topper|šišk|sisk/.test(name))input.value='4057';});
  const rate=e.target.closest('[data-shipping-import]');if(rate){const value=JSON.parse(rate.dataset.shippingImport),row=Array.from(root.querySelectorAll('[data-shipping-row]')).find(r=>!r.querySelector('select').value&&!r.querySelector('[name$="[price]"]').value);if(!row){window.alert('Save to add another shipping row, or clear an existing row.');return;}Object.keys(value).forEach(k=>{const el=row.querySelector('[name$="['+k+']"]');if(el)el.value=value[k];});mode.value='manual';shipping();row.scrollIntoView({block:'center',behavior:'smooth'});}
 });
 const rows=Array.from(document.querySelectorAll('tr[data-running="1"]'));let active=0;
 async function advance(row){active++;try{const data=new URLSearchParams({action:'dxps_feed_progress',nonce:dxpsFeedAdmin.nonce,id:row.dataset.dxpsFeed});const r=await fetch(dxpsFeedAdmin.ajax,{method:'POST',credentials:'same-origin',body:data});const json=await r.json();if(!r.ok||!json.success)throw new Error('Progress unavailable');const s=json.data;row.cells[1].textContent=s.state+' · '+(s.processed||0)+' scanned / '+(s.rows||0)+' valid / '+(s.errors||0)+' errors / '+(s.warnings||0)+' warnings';row.cells[1].setAttribute('aria-live','polite');if(s.state==='running')setTimeout(()=>advance(row),3000);else{row.dataset.running='0';row.cells[1].append(' — refresh this page for the published URL and report.');}}catch(e){row.cells[1].append(' — automatic progress paused; use Continue or refresh.');}finally{active--;}}
 // Sequential start avoids overwhelming stores with multiple catalogues.
 rows.forEach((r,i)=>setTimeout(()=>advance(r),i*1500));
})();

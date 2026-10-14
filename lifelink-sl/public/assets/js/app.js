document.querySelector('.nav-toggle')?.addEventListener('click',()=>document.querySelector('.main-nav')?.classList.toggle('open'));
setTimeout(()=>document.querySelectorAll('.flash').forEach(el=>el.style.opacity='.92'),3500);

const live = document.querySelector('[data-emergency-live]');
if(live){
  const endpoint = live.dataset.endpoint;
  async function refresh(){
    try{
      const r=await fetch(endpoint,{headers:{'Accept':'application/json'}}); if(!r.ok)return;
      const d=await r.json();
      for(const [k,v] of Object.entries(d.stats||{})){ const el=document.querySelector(`[data-stat="${k}"]`); if(el)el.textContent=v; }
      const status=document.querySelector('[data-emergency-status]'); if(status&&d.emergency?.status) status.textContent=d.emergency.status.toUpperCase();
    }catch(e){}
  }
  refresh(); setInterval(refresh,5000);
}

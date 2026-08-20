const TAG = window.__GRAV_PAGE_TAG || 'lantern-search-page';

class LanternSearchPage extends HTMLElement {
  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
    this.state = { status: null, query: '', result: null, busy: false, error: '', theme: 'dark' };
  }
  connectedCallback() { this.observeTheme(); this.render(); this.load(); }
  disconnectedCallback() { this.observer?.disconnect(); this.media?.removeEventListener?.('change', this.themeListener); }
  observeTheme() {
    const update = () => { const theme = this.detectTheme(); if (theme !== this.state.theme) { this.state.theme = theme; this.render(); } };
    this.media = matchMedia('(prefers-color-scheme: dark)'); this.themeListener = update; this.media.addEventListener?.('change', update);
    this.observer = new MutationObserver(update); this.observer.observe(document.documentElement, { attributes:true, attributeFilter:['class','data-theme','data-mode','style'] });
    if (document.body) this.observer.observe(document.body, { attributes:true, attributeFilter:['class','data-theme','data-mode','style'] }); update();
  }
  detectTheme() {
    const source = `${document.documentElement.dataset.theme || ''} ${document.body?.dataset?.theme || ''} ${document.documentElement.className} ${document.body?.className}`.toLowerCase();
    if (source.includes('dark')) return 'dark'; if (source.includes('light')) return 'light';
    const rgb = getComputedStyle(document.body || document.documentElement).backgroundColor.match(/\d+/g)?.map(Number);
    return rgb && ((rgb[0]*299+rgb[1]*587+rgb[2]*114)/1000) > 150 ? 'light' : (this.media.matches ? 'dark' : 'light');
  }
  headers() {
    let token = '', environment = '';
    try { const auth = JSON.parse(localStorage.getItem('grav_admin_auth') || '{}'); token = window.__GRAV_API_TOKEN || auth.accessToken || ''; environment = auth.environment || ''; } catch (_) {}
    const headers = { 'Content-Type':'application/json' }; if (token) { headers.Authorization=`Bearer ${token}`; headers['X-API-Token']=token; } if (environment) headers['X-Grav-Environment']=environment; return headers;
  }
  url(path) { return `${window.__GRAV_API_SERVER_URL || ''}${window.__GRAV_API_PREFIX || '/api/v1'}${path}`; }
  async api(path, options={}) {
    const response = await fetch(this.url(path), { ...options, headers:{ ...this.headers(), ...(options.headers || {}) } }); const text = await response.text();
    let payload={}; try { payload=text ? JSON.parse(text) : {}; } catch (_) { payload={message:text}; }
    if (!response.ok) throw new Error(payload.detail || payload.message || payload.title || `HTTP ${response.status}`); return payload.data ?? payload;
  }
  async load() { this.state.busy=true; this.state.error=''; this.render(); try { this.state.status=await this.api('/lantern-search/status'); } catch(error) { this.state.error=error.message; } finally { this.state.busy=false; this.render(); } }
  async rebuild() { this.state.busy=true; this.state.error=''; this.render(); try { await this.api('/lantern-search/rebuild',{method:'POST',body:'{}'}); await this.load(); } catch(error) { this.state.error=error.message; this.state.busy=false; this.render(); } }
  async search() { if (this.state.query.trim().length<2) return; this.state.busy=true; this.state.error=''; this.render(); try { this.state.result=await this.api(`/lantern-search/query?q=${encodeURIComponent(this.state.query)}`); } catch(error) { this.state.error=error.message; } finally { this.state.busy=false; this.render(); } }
  adminBase() { const path=location.pathname; const marker='/plugin/lantern-search'; const index=path.indexOf(marker); return index>=0 ? (path.slice(0,index)||'/admin') : '/admin'; }
  settings() { location.href=`${this.adminBase()}/plugins/lantern-search`; }
  bind() {
    this.shadowRoot.querySelector('[data-settings]')?.addEventListener('click',()=>this.settings());
    this.shadowRoot.querySelector('[data-rebuild]')?.addEventListener('click',()=>this.rebuild());
    const input=this.shadowRoot.querySelector('input'); input?.addEventListener('input',e=>this.state.query=e.target.value); input?.addEventListener('keydown',e=>{if(e.key==='Enter')this.search();});
    this.shadowRoot.querySelector('[data-search]')?.addEventListener('click',()=>this.search());
  }
  render() {
    const s=this.state.status||{}; const r=this.state.result||{}; const dark=this.state.theme==='dark';
    this.shadowRoot.innerHTML=`<style>
      :host{display:block;--bg:${dark?'#0d1724':'#f7f9fc'};--panel:${dark?'#121f2f':'#fff'};--panel2:${dark?'#17263a':'#f2f5fa'};--text:${dark?'#edf2fa':'#172033'};--muted:${dark?'#98a7ba':'#657187'};--line:${dark?'#2b3b50':'#dbe1ea'};--accent:#a855f7;--green:#35d6a0;color:var(--text);font:14px/1.5 system-ui,sans-serif}*{box-sizing:border-box}.shell{overflow:hidden;background:var(--bg);border:1px solid var(--line);border-radius:15px}.hero{display:flex;justify-content:space-between;gap:2rem;align-items:center;padding:2rem;background:linear-gradient(110deg,var(--panel),#252044)}.eyebrow{color:#c47aff;font-size:.72rem;font-weight:800;letter-spacing:.18em;text-transform:uppercase}h1,h2,p{margin:0}h1{font-size:2rem}h2{font-size:1.15rem}.hero p,.muted{color:var(--muted)}button{min-height:42px;padding:.65rem 1rem;color:var(--text);background:var(--panel2);border:1px solid var(--line);border-radius:9px;font-weight:700;cursor:pointer}.primary{color:#fff;background:var(--accent);border-color:transparent}.actions{display:flex;gap:.6rem}.metrics{display:grid;grid-template-columns:repeat(4,1fr);background:var(--panel)}.metric{padding:1.1rem 1.4rem;border-right:1px solid var(--line)}.metric:last-child{border:0}.metric span{display:block;color:var(--muted);font-size:.68rem;font-weight:800;letter-spacing:.13em;text-transform:uppercase}.metric strong{font-size:1.4rem}.body{display:grid;gap:1rem;padding:1rem}.card{background:var(--panel);border:1px solid var(--line);border-radius:12px}.card-head{display:flex;justify-content:space-between;gap:1rem;align-items:end;padding:1.2rem;border-bottom:1px solid var(--line)}.search{display:grid;grid-template-columns:1fr auto;gap:.6rem}.search input{width:min(34rem,60vw);height:42px;padding:0 .8rem;color:var(--text);background:var(--panel2);border:1px solid var(--line);border-radius:8px}.results{list-style:none;margin:0;padding:0}.results li{display:grid;grid-template-columns:1fr auto;gap:.2rem 1rem;padding:1rem 1.2rem;border-bottom:1px solid var(--line)}.results li:last-child{border:0}.results a{color:var(--text);font-weight:750;text-decoration:none}.route{color:var(--accent);font-size:.78rem}.excerpt{grid-column:1/-1;color:var(--muted)}.score{color:var(--green);font-weight:800}.empty{padding:2rem;color:var(--muted);text-align:center}.alert{padding:.8rem 1rem;color:#ff8ba0;background:#3a1b28;border:1px solid #7a3046;border-radius:10px}@media(max-width:760px){.hero,.card-head{align-items:stretch;flex-direction:column}.metrics{grid-template-columns:1fr 1fr}.metric:nth-child(2){border-right:0}.search{grid-template-columns:1fr}.search input{width:100%}}
    </style><div class="shell"><header class="hero"><div><div class="eyebrow">Public search control center</div><h1>Lantern Search</h1><p>Fast, relevance-ranked discovery without exposing protected content.</p></div><div class="actions"><button data-settings>Plugin settings</button><button class="primary" data-rebuild>${this.state.busy?'Working…':'Rebuild index'}</button></div></header>
    <section class="metrics"><div class="metric"><span>Indexed pages</span><strong>${Number(s.indexed_pages||0)}</strong></div><div class="metric"><span>Index size</span><strong>${this.bytes(s.index_size||0)}</strong></div><div class="metric"><span>Index state</span><strong>${s.dirty?'Stale':'Current'}</strong></div><div class="metric"><span>Built</span><strong>${s.built_at?new Date(s.built_at).toLocaleDateString():'—'}</strong></div></section>
    <div class="body">${this.state.error?`<div class="alert">${this.escape(this.state.error)}</div>`:''}<section class="card"><div class="card-head"><div><div class="eyebrow">Test the index</div><h2>Search preview</h2></div><div class="search"><input value="${this.escape(this.state.query)}" placeholder="Search public pages…"><button data-search>Search</button></div></div>
    ${Array.isArray(r.results)&&r.results.length?`<ol class="results">${r.results.map(item=>`<li><div><a href="${this.escape(item.url||item.route)}" target="_blank" rel="noopener">${this.escape(item.title)}</a><div class="route">${this.escape(item.route)}</div></div><div class="score">${Number(item.score).toFixed(2)}</div><div class="excerpt">${this.escape(item.excerpt||'')}</div></li>`).join('')}</ol>`:`<div class="empty">${r.query?`No public pages matched “${this.escape(r.query)}”.`:'Build the index, then test the same search visitors will use.'}</div>`}</section></div></div>`;
    this.bind();
  }
  bytes(value){if(!value)return'0 B';const units=['B','KB','MB','GB'];const i=Math.min(Math.floor(Math.log(value)/Math.log(1024)),3);return`${(value/1024**i).toFixed(i?1:0)} ${units[i]}`;}
  escape(value){return String(value??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));}
}
if(!customElements.get(TAG))customElements.define(TAG,LanternSearchPage);

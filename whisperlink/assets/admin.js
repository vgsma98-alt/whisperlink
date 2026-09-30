/* global WhisperLink */
(() => {
  'use strict';
  const root = document.getElementById('whisperlink');
  if (!root) return;
  const translations = WhisperLink.i18n || {};
  const translationKeys = Object.keys(translations).sort((a, b) => b.length - a.length);
  const tr = value => translationKeys.reduce((text, key) => text.split(key).join(translations[key]), String(value ?? ''));
  const trExact = value => {
    const text = String(value ?? '');
    if (Object.prototype.hasOwnProperty.call(translations, text)) return translations[text];
    const match = text.match(/^(\s*)([\s\S]*?)(\s*)$/);
    const core = match ? match[2] : text;
    return core && Object.prototype.hasOwnProperty.call(translations, core)
      ? `${match[1]}${translations[core]}${match[3]}`
      : text;
  };
  function translateNode(node) {
    if (node.nodeType === Node.TEXT_NODE) {
      const translated = trExact(node.nodeValue);
      if (translated !== node.nodeValue) node.nodeValue = translated;
      return;
    }
    if (node.nodeType !== Node.ELEMENT_NODE) return;
    ['placeholder', 'aria-label', 'title'].forEach(name => {
      if (!node.hasAttribute(name)) return;
      const value = node.getAttribute(name), translated = trExact(value);
      if (translated !== value) node.setAttribute(name, translated);
    });
    node.childNodes.forEach(translateNode);
  }
  root.lang = WhisperLink.lang || 'en';
  root.dir = WhisperLink.dir || (root.lang === 'ar' ? 'rtl' : 'ltr');
  translateNode(root);
  new MutationObserver(mutations => mutations.forEach(mutation => {
    mutation.addedNodes.forEach(translateNode);
    if (mutation.type === 'characterData') translateNode(mutation.target);
  })).observe(root, {subtree:true, childList:true, characterData:true});
  const main = document.getElementById('wl-main');
  const message = document.getElementById('wl-message');
  let active = 'report', page = 1, filter = '', search = '', sequence = 0;
  const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const field = id => document.getElementById(id);
  const operationLabel = value => trExact(({suggestion:'اقتراح رابط', rules:'قواعد الربط', 'url-change':'تغيير عنوان'}[value]) || value);
  function notice(text, error = false) { message.textContent = tr(text); message.className = error ? 'wl-error' : 'wl-notice'; }
  async function api(op, data = {}) {
    const body = new URLSearchParams({action:'whisperlink', nonce:WhisperLink.nonce, op, data:JSON.stringify(data)});
    const response = await fetch(WhisperLink.ajax, {method:'POST', credentials:'same-origin', body});
    let result;
    try { result = await response.json(); } catch { throw new Error('تعذر قراءة استجابة الخادم. أعد تحميل الصفحة وتحقق من جلسة الدخول.'); }
    if (!result.success) throw new Error(result.data?.message || 'تعذر تنفيذ الطلب.');
    return result.data;
  }
  function action(label, handler, cls = '') {
    const b = document.createElement('button'); b.textContent = tr(label); b.className = cls;
    b.addEventListener('click', async () => { b.disabled = true; try { await handler(); } catch(e) { notice(e.message, true); } finally { b.disabled = false; } });
    return b;
  }
  function table(headers, rows) {
    const wrap = document.createElement('div'); wrap.className = 'wl-table-wrap';
    const t = document.createElement('table');
    t.innerHTML = '<thead><tr>' + headers.map(h => `<th scope="col">${escape(h)}</th>`).join('') + '</tr></thead>';
    const body = document.createElement('tbody');
    if (!rows.length) body.innerHTML = `<tr><td colspan="${headers.length}" class="wl-empty">لا توجد نتائج في هذه الصفحة.</td></tr>`;
    rows.forEach(row => { const tr = document.createElement('tr'); row.forEach(cell => { const td = document.createElement('td'); if (cell instanceof Node) td.append(cell); else td.textContent = String(cell ?? ''); tr.append(td); }); body.append(tr); });
    t.append(body); wrap.append(t); return wrap;
  }
  function pager(count) {
    const div = document.createElement('div'); div.className = 'wl-toolbar';
    const prev = action('السابق', async () => { page--; await render(); }); prev.disabled = page <= 1;
    const next = action('التالي', async () => { page++; await render(); }); next.disabled = count < 50;
    div.append(prev, document.createTextNode(`${tr(' الصفحة ')}${page} `), next); main.append(div);
  }
  function csvButton(headers, rows) {
    return action('تصدير النتائج المعروضة CSV', () => {
      const quote = v => { let s = String(v ?? ''); if (/^[=+@\-\t\r]/.test(s)) s = "'" + s; return '"' + s.replaceAll('"','""') + '"'; };
      const data = '\uFEFF' + [headers.map(tr), ...rows].map(r => r.map(quote).join(',')).join('\r\n');
      const url = URL.createObjectURL(new Blob([data], {type:'text/csv;charset=utf-8'}));
      const a = document.createElement('a'); a.href = url; a.download = `whisperlink-${active}-${page}.csv`; a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
    });
  }
  const tabs = {report:'نظرة عامة', suggestions:'اقتراحات الربط', edges:'فحص الروابط', domains:'النطاقات', rules:'الربط التلقائي', urls:'تغيير العناوين', history:'سجل التعديلات', settings:'الإعدادات'};
  Object.entries(tabs).forEach(([key,label]) => {
    const b = action(label, async () => { active = key; page = 1; filter = ''; await render(); }); b.dataset.tab = key; document.getElementById('wl-nav').append(b);
  });
  async function render() {
    const run = ++sequence;
    document.querySelectorAll('#wl-nav button').forEach(b => { b.classList.toggle('active', b.dataset.tab === active); b.setAttribute('aria-current', b.dataset.tab === active ? 'page' : 'false'); });
    main.innerHTML = '<p class="wl-empty">جارٍ تحميل البيانات…</p>';
    if (active === 'report') {
      const d = await api('report', {page, filter, search}); if (run !== sequence) return;
      main.innerHTML = `<div class="wl-stats">${[['صفحات مفهرسة',d.total],['روابط في المحتوى',d.links],['صفحات يتيمة',d.orphans],['تغطية بروابط واردة',d.coverage+'%'],['أخطاء 404 / 410',d.broken]].map(([l,v]) => `<div><span>${l}</span><strong>${escape(v)}</strong></div>`).join('')}</div><section class="wl-card"><h2>خريطة الروابط في المحتوى</h2><p>الروابط الواردة محسوبة من صفحات أخرى مفهرسة. لا يشمل الفحص القوائم والقوالب والمحتوى المخزن في منشئات الصفحات.</p><div class="wl-toolbar"><input id="wl-search" aria-label="بحث بالعنوان" placeholder="ابحث عن مقال…" value="${escape(search)}"><select id="wl-filter" aria-label="تصفية"><option value="">جميع الصفحات</option><option value="orphans">الصفحات اليتيمة</option><option value="deadends">بلا روابط داخلية صادرة</option></select><span id="wl-search-action"></span></div><div id="wl-report-table"></div></section>`;
      field('wl-filter').value = filter;
      field('wl-search-action').append(action('بحث', async () => { search = field('wl-search').value; filter = field('wl-filter').value; page = 1; await render(); }));
      const rows = d.rows.map(r => {
        const controls = document.createElement('div'); controls.className = 'wl-actions';
        controls.append(action('اقتراحات', async () => { WhisperLink.post = r.post_id; active = 'suggestions'; await render(); }));
        return [r.post_id, r.title, r.inbound, r.outgoing, r.external_count, controls];
      });
      field('wl-report-table').append(table(['الرقم','الصفحة','مصادر واردة','داخلية صادرة','خارجية','الإجراء'], rows));
      main.append(csvButton(['الرقم','الصفحة','مصادر واردة','داخلية صادرة','خارجية'], d.rows.map(r => [r.post_id,r.title,r.inbound,r.outgoing,r.external_count]))); pager(d.rows.length);
      if (d.scan.active) notice(`الفحص مستمر: تمت مراجعة ${d.scan.processed} سجلًا. اضغط فحص المحتوى لإكماله في هذه الجلسة.`);
    } else if (active === 'suggestions') {
      main.innerHTML = `<section class="wl-card"><h2>فرص ربط قابلة للمراجعة</h2><p>بحث محلي في أحدث 500 صفحة مفهرسة؛ يعرض حتى 40 اقتراحًا. الدرجة تقيس تشابه الكلمات وليست تقييم ذكاء اصطناعي.</p><div class="wl-toolbar"><label>رقم المقال <input id="wl-post" type="number" min="1" value="${escape(WhisperLink.post || '')}"></label><select id="wl-direction" aria-label="اتجاه الروابط"><option value="out">من المقال إلى صفحات أخرى</option><option value="in">من صفحات أخرى إلى المقال</option></select><span id="wl-generate"></span></div><details><summary>الكلمات المفتاحية المستهدفة للمقال</summary><p>عبارات مفصولة بفواصل. تُقرأ أيضًا كلمات Yoast وRank Math تلقائيًا. الحفظ يستبدل الكلمات اليدوية السابقة.</p><textarea id="wl-keywords" aria-label="كلمات مفتاحية"></textarea><div id="wl-save-keywords"></div></details><div id="wl-suggestions"></div></section>`;
      field('wl-save-keywords').append(action('حفظ الكلمات', async () => { await api('keywords', {id:field('wl-post').value, keywords:field('wl-keywords').value}); notice('تم حفظ الكلمات.'); }));
      field('wl-generate').append(action('توليد الاقتراحات', async () => {
        const results = await api('suggestions', {id:field('wl-post').value, direction:field('wl-direction').value});
        const container = field('wl-suggestions'); if (!container) return; container.replaceChildren();
        if (!results.length) container.textContent = 'لا توجد عبارات مطابقة قابلة للربط. أضف كلمات مستهدفة للصفحات وتأكد من اكتمال الفهرسة.';
        results.forEach(r => {
          const card = document.createElement('article'); card.className = 'wl-suggestion';
          card.innerHTML = `<div class="wl-eyebrow">تشابه نصي ${escape(r.score)}%</div><h3>${escape(r.source_title)} ← ${escape(r.target_title)}</h3><p>${escape(r.context)}</p><label>نص الرابط <input value="${escape(r.phrase)}"></label><p class="wl-url">${escape(r.url)}</p>`;
          card.append(action('إدراج الرابط', async () => { const result = await api('insert', {id:r.source,target:r.target,phrase:card.querySelector('input').value,hash:r.hash}); if (result.changed) { card.remove(); notice('أُدرج الرابط. أعد توليد الاقتراحات قبل تعديل المقال نفسه مجددًا.'); } else notice('لم يُعثر على نص قابل للربط بهذه العبارة.', true); }, 'wl-primary')); container.append(card);
        });
      }, 'wl-primary'));
    } else if (active === 'edges') {
      const rows = await api('edges',{page,filter}); if (run !== sequence) return;
      main.innerHTML = '<section class="wl-card"><h2>فحص صحة الروابط</h2><p>الفحص يطلب 3 عناوين في كل دفعة؛ النتائج تُخزّن 7 أيام. أخطاء الشبكة و403 و429 لا تعني بالضرورة أن الرابط مكسور. الفحص الخارجي اختياري في الإعدادات.</p><div id="wl-check-actions" class="wl-toolbar"></div></section>';
      field('wl-check-actions').append(action('فحص الدفعة التالية', async () => { const d = await api('check'); notice(`تم فحص ${d.checked} عناوين.`); await render(); }), action(filter ? 'عرض الكل' : 'عرض الأخطاء', async () => { filter = filter ? '' : 'errors'; page = 1; await render(); }));
      const headers = ['المقال','العنوان','النوع','HTTP','آخر فحص'];
      const values = rows.map(r => [r.post_title || r.source,r.url,Number(r.internal)?'داخلي':'خارجي',Number(r.status)===0?'لم يُفحص':Number(r.status)===-1?'خطأ اتصال':r.status,r.checked_at || '—']);
      main.append(table(headers,values), csvButton(headers,values)); pager(rows.length);
    } else if (active === 'domains') {
      const rows = await api('domains'); if (run !== sequence) return; main.innerHTML = '<h2>توزيع الروابط حسب النطاق — حتى 200 نطاق</h2>';
      const headers = ['النطاق','الروابط','الصفحات المصدر']; const values = rows.map(r => [r.host,r.links,r.pages]); main.append(table(headers,values),csvButton(headers,values));
    } else if (active === 'rules') {
      const rules = await api('rules_get'); if (run !== sequence) return;
      main.innerHTML = '<section class="wl-card"><h2>قواعد الربط بالكلمات المفتاحية</h2><p>عبارة واحدة ووجهة لكل سطر. مثال: تحسين محركات البحث | 42. يُضاف رابط واحد لكل وجهة، مع حد الروابط المحدد في الإعدادات.</p><textarea id="wl-rules" rows="9" aria-label="قواعد الربط" dir="auto"></textarea><div id="wl-rule-save"></div><hr><h3>تطبيق على مقال موجود</h3><label>رقم المقال <input id="wl-rule-post" type="number" min="1"></label><div id="wl-rule-preview"></div><div id="wl-rule-result"></div></section>';
      field('wl-rules').value = rules.map(r => `${r.phrase} | ${r.target}`).join('\n');
      field('wl-rule-save').append(action('حفظ القواعد', async () => { const parsed = field('wl-rules').value.split('\n').filter(s => s.trim()).map(s => { const at = s.lastIndexOf('|'); if (at < 1) throw new Error('صيغة القاعدة: العبارة | رقم الصفحة'); return {phrase:s.slice(0,at).trim(),target:Number(s.slice(at+1).trim())}; }); await api('rules_save', {rules:parsed}); notice('حُفظت القواعد.'); }));
      field('wl-rule-preview').append(action('معاينة القواعد', async () => { const id = field('wl-rule-post').value; const d = await api('rules_preview',{id}); const box = field('wl-rule-result'); box.replaceChildren(table(['العبارة','رقم الوجهة'],d.matches.map(r => [r.phrase,r.target]))); if (d.matches.length) box.append(action('تطبيق الروابط المعروضة', async () => { await api('rules_apply',{id,hash:d.hash,rules_hash:d.rules_hash}); box.replaceChildren(); notice('تم تطبيق القواعد وتسجيل نسخة للتراجع.'); },'wl-primary')); }));
    } else if (active === 'urls') {
      main.innerHTML = '<section class="wl-card"><h2>تغيير عنوان داخل الروابط</h2><p>مطابقة العنوان كاملًا مع الاحتفاظ بجزء #fragment. تعرض المعاينة أول 100 مقال متأثر؛ أعد المعاينة بعد تنفيذ الدفعة.</p><label>العنوان القديم<input id="wl-old" type="url" dir="ltr" placeholder="https://example.com/old/"></label><label>العنوان الجديد<input id="wl-new" type="url" dir="ltr" placeholder="https://example.com/new/"></label><div id="wl-url-preview"></div><div id="wl-url-results"></div></section>';
      field('wl-url-preview').append(action('معاينة التغييرات', async () => { const old = field('wl-old').value, next = field('wl-new').value; const rows = await api('urls_preview',{old,new:next}); const box = field('wl-url-results'); box.replaceChildren(table(['المقال','العنوان','الإجراء'],rows.map(r => [r.id,r.title,action('تطبيق على هذا المقال', async () => { const result = await api('urls_apply',{...r,old,new:next}); notice(result.changed?'تم تغيير الرابط.':'لا توجد تغييرات.'); })]))); }));
    } else if (active === 'history') {
      const rows = await api('history',{page}); if (run !== sequence) return;
      main.innerHTML = '<h2>سجل التعديلات والتراجع</h2><p>يُرفض التراجع إذا تغير المحتوى بعد العملية. تبقى الروابط وسجلات التراجع بعد تعطيل الإضافة.</p>';
      main.append(table(['السجل','المقال','المستخدم','العملية','التاريخ UTC','الإجراء'],rows.map(r => [r.id,r.post_id,r.actor,operationLabel(r.action),r.created_at,Number(r.undone)?'تم التراجع':action('تراجع',async () => { await api('undo',{id:r.id}); notice('تم استرجاع المحتوى.'); await render(); })]))); pager(rows.length);
    } else if (active === 'settings') {
      const d = await api('settings_get'); if (run !== sequence) return; const s = d.settings;
      main.innerHTML = `<section class="wl-card"><h2>نطاق الفحص والربط</h2><fieldset><legend>أنواع المحتوى المنشور</legend>${Object.entries(d.types).filter(([slug]) => slug !== 'attachment').map(([slug,label]) => `<label class="wl-checkbox"><input name="wl-types" type="checkbox" value="${escape(slug)}" ${s.types.includes(slug)?'checked':''}>${escape(label)}</label>`).join('')}</fieldset><label>أرقام الصفحات المستثناة<input id="wl-exclude" value="${escape(s.exclude.join(','))}"></label><label>أقصى روابط من القواعد لكل تطبيق<input id="wl-limit" type="number" min="1" max="10" value="${escape(s.limit)}"></label><label class="wl-checkbox"><input id="wl-automatic" type="checkbox" ${s.automatic?'checked':''}>تطبيق القواعد عند حفظ مدير الموقع لمقال منشور</label><label class="wl-checkbox"><input id="wl-external" type="checkbox" ${s.external?'checked':''}>فحص الروابط الخارجية: يرسل طلبات HTTP للنطاقات المشار إليها</label><p>لا تُرسل النصوص إلى خدمات ذكاء اصطناعي. الفحص الدوري يعتمد على WP-Cron وزيارات الموقع. بعد تغيير النطاق، شغّل فحص المحتوى من جديد.</p><div id="wl-settings-save"></div></section>`;
      field('wl-settings-save').append(action('حفظ الإعدادات',async () => { await api('settings_save',{types:[...document.querySelectorAll('[name="wl-types"]:checked')].map(i => i.value),exclude:field('wl-exclude').value,limit:field('wl-limit').value,automatic:field('wl-automatic').checked,external:field('wl-external').checked}); notice('تم حفظ الإعدادات.'); },'wl-primary'));
    }
  }
  field('wl-scan').addEventListener('click', async event => {
    event.target.disabled = true;
    try { let d = await api('scan_start'); while (d.active) { notice(`جارٍ فحص المحتوى: ${d.processed} سجلًا…`); d = await api('scan'); } notice(`اكتمل الفحص: ${d.processed} سجلًا تمت مراجعته.`); await render(); }
    catch(e) { notice(e.message,true); } finally { event.target.disabled = false; }
  });
  if (WhisperLink.post) active = 'suggestions';
  render().catch(e => notice(e.message,true));
})();

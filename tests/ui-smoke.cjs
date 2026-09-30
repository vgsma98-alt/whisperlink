// UI-only smoke checks with fixture API responses; not a WordPress integration test.
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
(async () => {
 const browser = await chromium.launch({headless:true, channel:'msedge'});
 const page = await browser.newPage({viewport:{width:1440,height:1000}});
 const errors = []; page.on('pageerror', e => errors.push(e.message));
 let lastRequest;
 const responses = {
  report:{rows:[{post_id:42,title:'Guide des liens internes',inbound:0,outgoing:3,external_count:2}],page:1,total:1,orphans:1,coverage:0,links:5,broken:0,scan:{active:false}},
  suggestions:[{source:42,target:43,source_title:'Guide des liens internes',target_title:'Référencement naturel',phrase:'référencement naturel',score:20,context:'Apprenez le référencement naturel grâce à un contenu bien relié.',url:'https://example.com/seo',hash:'fixture'}],
  insert:{changed:true},keywords:{saved:true},
  edges:[{source:42,post_title:'Guide des liens internes',url:'https://example.com/seo',internal:1,status:200,checked_at:'2026-09-29'}],
  domains:[{host:'example.com',links:5,pages:1}],rules_get:[{phrase:'référencement naturel',target:43}],rules_save:{saved:1},
  rules_preview:{matches:[{phrase:'référencement naturel',target:43}],hash:'post-hash',rules_hash:'rules-hash'},rules_apply:[],
  urls_preview:[{id:42,title:'Guide des liens internes',hash:'fixture'}],urls_apply:{changed:true},
  history:[{id:1,post_id:42,actor:1,action:'suggestion',created_at:'2026-09-29',undone:0}],undo:{undone:true},
  settings_get:{settings:{types:['post','page'],exclude:[],limit:3,automatic:false,external:false},types:{post:'Articles',page:'Pages'}},settings_save:{saved:true},
  scan_start:{active:false,processed:2},check:{checked:1}
 };
 await page.route('https://fixture.example/**', async route => {
  if (route.request().method() === 'POST') {
   const data = new URLSearchParams(route.request().postData()); lastRequest = {op:data.get('op'), data:JSON.parse(data.get('data'))};
   await route.fulfill({contentType:'application/json',body:JSON.stringify({success:true,data:responses[data.get('op')]})});
  } else await route.fulfill({contentType:'text/html',body:'<!doctype html><html lang="fr"><meta charset="utf-8"><body><div id="whisperlink" dir="ltr"><header><div><span class="wl-eyebrow">WHISPERLINK · UI TEST FIXTURE</span><h1>De meilleurs liens, un contenu bien connecté.</h1><p>Examinez les opportunités de liens internes et suivez la couverture des pages.</p></div><button id="wl-scan" class="wl-primary">Analyser le contenu</button></header><div id="wl-message" role="status"></div><nav id="wl-nav"></nav><main id="wl-main"></main><footer>Données de test de l’interface</footer></div></body></html>'});
 });
 await page.goto('https://fixture.example/');
 await page.addStyleTag({path:path.resolve('whisperlink/assets/admin.css')});
 const locale = JSON.parse(execFileSync(path.resolve('.tools/php83/php.exe'), [path.resolve('tests/i18n-export.php'),'fr_FR'], {encoding:'utf8'}));
 await page.evaluate(locale => {window.WhisperLink={ajax:'https://fixture.example/ajax',nonce:'test',post:0,...locale};}, locale);
 await page.addScriptTag({path:path.resolve('whisperlink/assets/admin.js')});
 await page.getByText('Guide des liens internes',{exact:true}).waitFor();
 fs.mkdirSync('.test-runtime/screenshots',{recursive:true});
 await page.screenshot({path:'.test-runtime/screenshots/dashboard.png',fullPage:true});
 await page.getByRole('button',{name:'Suggestions',exact:true}).click();
 if (/[\u0600-\u06ff]/.test(await page.locator('#whisperlink').innerText())) throw Error('French Suggestions tab contains visible Arabic text');
 await page.getByRole('button',{name:'Générer les suggestions',exact:true}).click();
 await page.getByRole('button',{name:'Insérer le lien',exact:true}).click();
 await page.getByText('Lien inséré.',{exact:false}).waitFor();
 for (const label of ['Vérification des liens','Domaines','Liens automatiques','Remplacement d’URL','Historique','Réglages']) {
  await page.getByRole('button',{name:label,exact:true}).click();
  await page.waitForFunction(() => !document.querySelector('#wl-main').textContent.includes('جارٍ تحميل'));
  if (/[\u0600-\u06ff]/.test(await page.locator('#whisperlink').innerText())) throw Error(`French ${label} tab contains visible Arabic text`);
 }
 await page.getByRole('button',{name:'Enregistrer les réglages',exact:true}).click();
 await page.getByText('Réglages enregistrés.',{exact:true}).waitFor();
 if (lastRequest.op !== 'settings_save' || lastRequest.data.types.length !== 2) throw Error('Settings payload failed');
 await page.getByRole('button',{name:'Liens automatiques',exact:true}).click();
 await page.locator('#wl-rule-post').fill('42');
 await page.getByRole('button',{name:'Prévisualiser les règles',exact:true}).click();
 await page.getByRole('button',{name:'Appliquer les liens affichés',exact:true}).click();
 await page.getByText('Règles appliquées et copie d’annulation enregistrée.',{exact:true}).waitFor();
 if (lastRequest.data.rules_hash !== 'rules-hash') throw Error('Rules preview fingerprint missing');
 await page.setViewportSize({width:390,height:844});
 await page.getByRole('button',{name:'Vue d’ensemble',exact:true}).click();
 await page.getByText('Guide des liens internes',{exact:true}).waitFor();
 await page.screenshot({path:'.test-runtime/screenshots/mobile.png',fullPage:true});
 const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
  if (overflow) throw Error('Page overflows mobile viewport');
 const arabicVisible = await page.locator('body').innerText().then(text => /[\u0600-\u06ff]/.test(text));
 if (arabicVisible) throw Error('French UI still contains visible Arabic text');
 if (errors.length) throw Error(errors.join('\n'));
 console.log('PASS: 8 tabs, suggestion insertion, settings payload, guarded rules payload, mobile overflow, no browser errors. API responses are fixtures.');
 await browser.close();
})().catch(error => {console.error(error);process.exit(1);});

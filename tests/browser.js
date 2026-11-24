/* Loads the page rendered by tests/run.php in headless Chromium and checks what the tracking scripts do.
   Run: php tests/run.php && node tests/browser.js   (needs Playwright) */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const jq = `window.jQuery=function(a){if(typeof a==='function'){if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',a);else setTimeout(a,0);return;}
return{on:function(){var e=arguments[0],f=arguments[arguments.length-1];(a.addEventListener?a:document).addEventListener(e,function(ev){f.apply(a,[ev].concat(ev.detail||[]))});return this}}};`;
let fail = 0;
const ok = (c, l) => { console.log((c ? '  ok   ' : '  FAIL ') + l); if (!c) fail++; };

(async () => {
	const browser = await chromium.launch();
	const page = await browser.newPage();
	const relays = [], relayCookies = []; let ip6Calls = 0;
	await page.route('**/*', route => {
		const u = route.request().url();
		if (u.includes('api64.ipify.org')) { ip6Calls++; return route.fulfill({ contentType: 'text/plain', body: '2a02:810d:4b00:1::77' }); }
		if (u.includes('/wp-json/dxps/v1/e')) { relays.push(JSON.parse(route.request().postData())); relayCookies.push(route.request().headers()['cookie'] || ''); return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, am: { em: 'e'.repeat(64) } }) }); }
		if (u.includes('/wp-json/')) return route.fulfill({ contentType: 'application/json', body: '{"ok":true}' });
		if (u.endsWith('/jquery-stub.js')) return route.fulfill({ contentType: 'text/javascript', body: jq });
		if (u.endsWith('/dxps-front.js')) return route.fulfill({ contentType: 'text/javascript', body: fs.readFileSync(path.join(__dirname, '../assets/js/dxps-front.js'), 'utf8') });
		if (u.startsWith('http://shop.test/product/')) return route.fulfill({ contentType: 'text/html', body: fs.readFileSync(path.join(__dirname, '.page.html'), 'utf8') });
		if (u.startsWith('http://shop.test/kontakt/')) return route.fulfill({ contentType: 'text/html', body: fs.readFileSync(path.join(__dirname, '.page-guest.html'), 'utf8') });
		return route.abort(); // Meta / Google libraries: only the calls made to them matter here
	});
	const errors = []; page.on('pageerror', e => errors.push(e.message));
	await page.goto('http://shop.test/product/1/?utm_source=newsletter&gclid=abc123&email=jane%40example.com&fbclid=IwAR0abcdef');
	await page.evaluate(() => {
		window.dxpsFire({ name: 'view_item', params: { value: 120, currency: 'BAM', items: [{ id: '101', price: 120, quantity: 1, variant: 'Smeđa' }] } });
		window.dxpsFire({ name: 'add_to_cart', params: { value: 120, items: [{ id: '101', price: 120, quantity: 1 }], _private: 'x' } });
		window.dxpsFire({ name: 'purchase', params: { transaction_id: '55', value: 240, currency: 'BAM', discount: 10, new_customer: true, items: [{ id: '101', price: 120, quantity: 2 }], _gads_send_to: 'AW-1/lbl', _scope: 'gads_only' } });
	});
	await page.waitForTimeout(1500);
	const s = await page.evaluate(() => ({
		dl: JSON.parse(JSON.stringify(Array.from(window.dataLayer).map(a => (a && typeof a.length === 'number' && !Array.isArray(a)) ? Array.from(a) : a))),
		fbq: JSON.parse(JSON.stringify((window.fbq && window.fbq.queue || []).map(a => Array.from(a)))),
		cookie: document.cookie
	}));
	const calls = s.dl.filter(Array.isArray);
	const loc = calls.find(c => c[0] === 'set' && c[1] === 'page_location');
	ok(errors.length === 0, 'no script errors ' + errors.join(' | '));
	ok(loc && loc[2] === 'http://shop.test/product/1/?utm_source=newsletter&gclid=abc123', 'GA4 page_location keeps campaign parameters and drops the rest: ' + (loc && loc[2]));
	const pv = calls.find(c => c[0] === 'event' && c[1] === 'page_view');
	ok(pv && pv[2].page_location === loc[2] && pv[2].send_to === 'G-TEST', 'page_view uses the same address');
	ok(calls.some(c => c[0] === 'set' && c[1] && c[1].user_id === 'u7'), 'GA4 user_id is set for the logged-in customer');
	const init = s.fbq.find(c => c[0] === 'init');
	ok(init && init[2] && /^[a-f0-9]{32}$/.test(init[2].external_id) && s.cookie.includes('pbid=' + init[2].external_id), 'first pixel init carries the browser ID');
	ok(s.fbq.findIndex(c => c[0] === 'init') < s.fbq.findIndex(c => c[2] === 'PageView'), 'browser ID is set before the PageView');
	ok(/_fbp=fb\.1\.\d{13}\.\d{10}/.test(s.cookie) && /_fbp=fb\.1\./.test(relayCookies[0] || ''), 'new visitor: _fbp exists before Meta loads, so the first server copy carries it');
	ok(ip6Calls === 0, 'IPv6 lookup off: no outside request');
	ok(/_dxps_lt=/.test(s.cookie) && /_dxps_src=/.test(s.cookie) && /_fbc=fb\.1\.\d+\.IwAR0abcdef/.test(s.cookie), 'first-touch, last-touch and click-ID cookies');
	ok(relays.length === 1 && relays[0].events.map(e => e.name).join() === 'page_view,view_item,add_to_cart', 'one relay request for the burst, purchase excluded: ' + relays.map(r => r.events.map(e => e.name).join()).join(' / '));
	ok(relays[0] && !Object.keys(relays[0].events[2].params).some(k => k[0] === '_') && relays[0].events[1].url === 'http://shop.test/product/1/', 'internal fields and query strings stay out of the relay');
	const sha = v => require('crypto').createHash('sha256').update(v).digest('hex');
	ok(init && init[2].em === sha('jane@example.com'), 'signed-in visitor: the first init carries the hashed account email');
	ok(s.fbq.filter(c => c[0] === 'init').length === 1, 'the pixel is initialised exactly once (Meta ignores later inits)');
	ok(/_dxps_ud=em\.e{64}/.test(s.cookie), 'matching fields from the server are kept for the next page: ' + s.cookie);
	const vc = s.fbq.find(c => c[2] === 'ViewContent');
	ok(vc && vc[3].num_items === 1 && vc[3].content_ids[0] === '101' && vc[4].eventID === relays[0].events[1].event_id, 'browser and server copies share the event ID');
	const conv = calls.find(c => c[0] === 'event' && c[1] === 'conversion');
	ok(conv && conv[2].aw_merchant_id === 5551234 && conv[2].aw_feed_country === 'BA' && conv[2].items[0].id === '101' && conv[2].items[0].quantity === 2 && conv[2].discount === 10 && conv[2].transaction_id === '55', 'Google Ads conversion carries cart data');
	const rmk = calls.filter(c => c[0] === 'event' && c[2] && c[2].send_to === 'AW-1').map(c => c[1]);
	ok(rmk.join() === 'view_item,add_to_cart,purchase' && calls.find(c => c[1] === 'view_item' && c[2].send_to === 'AW-1')[2].items[0].google_business_vertical === 'retail', 'dynamic remarketing events: ' + rmk.join());
	ok(!calls.some(c => c[0] === 'event' && c[1] === 'purchase' && c[2].send_to === 'G-TEST'), 'status-mode purchase is not duplicated to GA4 from the browser');
	const gi = calls.find(c => c[0] === 'event' && c[1] === 'view_item' && c[2].send_to === 'G-TEST');
	ok(gi && gi[2].items[0].item_variant === 'Smeđa', 'GA4 items carry the variant');

	/* ---- 2.3.0: guest on a cached page: form fill -> cookie -> next page's first init ---- */
	const ctx2 = await browser.newContext();
	const g = await ctx2.newPage(); let gIp6 = 0; const gCalls = [];
	await g.route('**/*', route => {
		const u = route.request().url();
		if (u.includes('/wp-json/dxps/v1/id')) { gCalls.push('id:' + JSON.parse(route.request().postData()).token); return route.fulfill({ contentType: 'application/json', body: '{"ok":true}' }); }
		if (u.includes('/wp-json/dxps/v1/e')) gCalls.push('e');
		if (u.includes('api64.ipify.org')) { gIp6++; return route.fulfill({ contentType: 'text/plain', headers: { 'access-control-allow-origin': '*' }, body: '2a02:810d:4b00:1::77' }); }
		if (u.includes('/wp-json/')) return route.fulfill({ contentType: 'application/json', body: '{"ok":true}' });
		if (u.endsWith('/jquery-stub.js')) return route.fulfill({ contentType: 'text/javascript', body: jq });
		if (u.endsWith('/dxps-front.js')) return route.fulfill({ contentType: 'text/javascript', body: fs.readFileSync(path.join(__dirname, '../assets/js/dxps-front.js'), 'utf8') });
		if (u.startsWith('https://shop.test/kontakt/')) return route.fulfill({ contentType: 'text/html', body: fs.readFileSync(path.join(__dirname, '.page-guest.html'), 'utf8') });
		return route.abort();
	});
	const gErrors = []; g.on('pageerror', e => gErrors.push(e.message));
	await g.goto('https://shop.test/kontakt/'); // SHA-256 in the browser (crypto.subtle) needs HTTPS, as on the live site
	let gs = await g.evaluate(() => ({ init: (window.fbq.queue || []).map(a => Array.from(a)).find(c => c[0] === 'init') }));
	ok(gs.init && Object.keys(gs.init[2]).join() === 'external_id', 'new guest: first init has the browser ID only');
	await g.fill('#f-name', 'Amra Hadžić Kovač');
	await g.fill('[name=your-email]', 'Amra.H@Example.com');
	await g.fill('#f-tel', '061 123 456');
	await g.fill('[name=your-subject]', 'Termin');
	await g.fill('[name=password]', 'secret');
	await g.evaluate(() => { document.querySelectorAll('input').forEach(i => i.dispatchEvent(new Event('change', { bubbles: true }))); });
	await g.waitForTimeout(1500);
	const ud = await g.evaluate(() => (document.cookie.match(/_dxps_ud=([^;]*)/) || [])[1] || '');
	const got = Object.fromEntries(ud.split('~').filter(Boolean).map(p => p.split('.')));
	ok(got.em === sha('amra.h@example.com') && got.ph === sha('38761123456') && got.fn === sha('amra') && got.ln === sha('hadžićkovač'), 'form fill stores hashed email, phone (store country added), first and last name from a full-name field: ' + Object.keys(got).join());
	ok(!/secret|termin|amra/i.test(ud), 'nothing readable in the cookie; password and subject ignored');
	await g.goto('https://shop.test/kontakt/'); // SHA-256 in the browser (crypto.subtle) needs HTTPS, as on the live site
	gs = await g.evaluate(() => ({ inits: (window.fbq.queue || []).map(a => Array.from(a)).filter(c => c[0] === 'init') }));
	ok(got.em && gs.inits.length === 1 && gs.inits[0][2].em === got.em && gs.inits[0][2].ph === got.ph && gs.inits[0][2].fn === got.fn && gs.inits[0][2].external_id, 'returning guest on a cached page: the first init carries the hashed form data');
	ok(gIp6 === 1 && decodeURIComponent((await g.evaluate(() => (document.cookie.match(/_dxps_ip=([^;]*)/) || [])[1] || ''))) === '2a02:810d:4b00:1::77', 'IPv6 lookup: one request, address kept for server events (' + gIp6 + ' calls)');
	await g.goto('https://shop.test/kontakt/', { referer: 'https://shop.test/kontakt/?fbclid=RefClick12345' });
	ok(/_fbc=fb\.1\.\d{13}\.RefClick12345/.test(await g.evaluate(() => document.cookie)), 'click ID recovered from the previous page when the landing page lost it');
	// 2.4.0: order-email link
	const tok = '55.1999999999.' + 'a1'.repeat(16);
	gCalls.length = 0;
	await g.goto('https://shop.test/kontakt/?x=1&dxr=' + tok);
	await g.waitForTimeout(1200);
	const gLoc = await g.evaluate(() => location.search);
	ok(gLoc === '?x=1', 'order-email token removed from the address bar: ' + gLoc);
	const idCalls = gCalls.filter(c => c.indexOf('id:') === 0);
	ok(idCalls.length === 1 && idCalls[0] === 'id:' + tok, 'token handed to the server once: ' + gCalls.join(','));
	ok(gCalls.indexOf('e') > -1 && gCalls.findIndex(c => c.indexOf('id:') === 0) < gCalls.indexOf('e'), 'recognition happens before the page events are relayed: ' + gCalls.join(','));
	await g.evaluate(() => window.dxpsConsent('denied'));
	const afterDeny = await g.evaluate(() => document.cookie);
	ok(!afterDeny.includes('_dxps_ud=') && !afterDeny.includes('_dxps_ip='), 'consent withdrawal deletes the matching and IP cookies');
	ok(gErrors.length === 0, 'no script errors on the guest page ' + gErrors.join(' | '));
	await browser.close();
	console.log(fail ? `\n${fail} FAILED` : '\nAll browser checks passed');
	process.exit(fail ? 1 : 0);
})();

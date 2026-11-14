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
	const relays = [];
	await page.route('**/*', route => {
		const u = route.request().url();
		if (u.includes('/wp-json/dxps/v1/e')) { relays.push(JSON.parse(route.request().postData())); return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, am: { em: 'e'.repeat(64) } }) }); }
		if (u.includes('/wp-json/')) return route.fulfill({ contentType: 'application/json', body: '{"ok":true}' });
		if (u.endsWith('/jquery-stub.js')) return route.fulfill({ contentType: 'text/javascript', body: jq });
		if (u.endsWith('/dxps-front.js')) return route.fulfill({ contentType: 'text/javascript', body: fs.readFileSync(path.join(__dirname, '../assets/js/dxps-front.js'), 'utf8') });
		if (u.startsWith('http://shop.test/product/')) return route.fulfill({ contentType: 'text/html', body: fs.readFileSync(path.join(__dirname, '.page.html'), 'utf8') });
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
	ok(/_dxps_lt=/.test(s.cookie) && /_dxps_src=/.test(s.cookie) && /_fbc=fb\.1\.\d+\.IwAR0abcdef/.test(s.cookie), 'first-touch, last-touch and click-ID cookies');
	ok(relays.length === 1 && relays[0].events.map(e => e.name).join() === 'page_view,view_item,add_to_cart', 'one relay request for the burst, purchase excluded: ' + relays.map(r => r.events.map(e => e.name).join()).join(' / '));
	ok(relays[0] && !Object.keys(relays[0].events[2].params).some(k => k[0] === '_') && relays[0].events[1].url === 'http://shop.test/product/1/', 'internal fields and query strings stay out of the relay');
	ok(s.fbq.some(c => c[0] === 'init' && c[2] && c[2].em === 'e'.repeat(64)), 'matching fields from the server reach the pixel');
	const vc = s.fbq.find(c => c[2] === 'ViewContent');
	ok(vc && vc[3].num_items === 1 && vc[3].content_ids[0] === '101' && vc[4].eventID === relays[0].events[1].event_id, 'browser and server copies share the event ID');
	const conv = calls.find(c => c[0] === 'event' && c[1] === 'conversion');
	ok(conv && conv[2].aw_merchant_id === 5551234 && conv[2].aw_feed_country === 'BA' && conv[2].items[0].id === '101' && conv[2].items[0].quantity === 2 && conv[2].discount === 10 && conv[2].transaction_id === '55', 'Google Ads conversion carries cart data');
	const rmk = calls.filter(c => c[0] === 'event' && c[2] && c[2].send_to === 'AW-1').map(c => c[1]);
	ok(rmk.join() === 'view_item,add_to_cart,purchase' && calls.find(c => c[1] === 'view_item' && c[2].send_to === 'AW-1')[2].items[0].google_business_vertical === 'retail', 'dynamic remarketing events: ' + rmk.join());
	ok(!calls.some(c => c[0] === 'event' && c[1] === 'purchase' && c[2].send_to === 'G-TEST'), 'status-mode purchase is not duplicated to GA4 from the browser');
	const gi = calls.find(c => c[0] === 'event' && c[1] === 'view_item' && c[2].send_to === 'G-TEST');
	ok(gi && gi[2].items[0].item_variant === 'Smeđa', 'GA4 items carry the variant');
	await browser.close();
	console.log(fail ? `\n${fail} FAILED` : '\nAll browser checks passed');
	process.exit(fail ? 1 : 0);
})();

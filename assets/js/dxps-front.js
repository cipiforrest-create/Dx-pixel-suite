/* DX Pixel Suite — front-end dispatcher */
(function ($) {
	'use strict';
	var cfg = window.dxpsCfg || {};
	var META_PIXEL = cfg.metaPixel || '';
	var dispatchedIDs=Object.create(null),dispatchOrder=[],cachedIDs=Object.create(null);
	function eventID(name){return name+'.'+(crypto.randomUUID?crypto.randomUUID():Date.now()+'.'+Math.random().toString(36).slice(2));}
	var sensitiveRx = /alopeci|kemoterap|chemotherap|onkolog|leukem|cancer|psorij|dermatit|\bdiagnos|\bdiagnostic|\bmedical|\bpatient|\brak\b/i;
	function blocked(p){var u=location.href;try{u=decodeURIComponent(u)}catch(e){}return !!cfg.blockedContext||sensitiveRx.test(u+' '+JSON.stringify(p||{}));}
	function safeUrl(v){try{var u=new URL(v,location.href);return u.origin===location.origin?u.origin+u.pathname.replace(/\/(order-received|order-pay)\/\d+\/?$/, '/') : location.origin+'/';}catch(e){return location.origin+'/';}}
	function waitMatching(p){return Promise.race([Promise.resolve(p),new Promise(function(resolve){setTimeout(resolve,750)})]);}
	function relay(body,attempt){if(!cfg.relay||blocked(body.params)||(window.dxpsMarketingAllowed&&!window.dxpsMarketingAllowed()))return Promise.resolve();return fetch(cfg.relay,{method:'POST',credentials:'same-origin',keepalive:true,headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}).then(function(r){if((r.status===429||r.status>=500)&&!attempt)return new Promise(function(resolve){setTimeout(resolve,1000)}).then(function(){return relay(body,1)});return r;}).catch(function(){if(!attempt)return relay(body,1);});}

	/* ---------- identity cookies ---------- */
	function getCookie(n) {
		var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
		return m ? m.pop() : '';
	}
	function setCookie(n, v, days) {
		document.cookie = n + '=' + v + ';path=/;max-age=' + (days * 86400) + ';SameSite=Lax' + (location.protocol === 'https:' ? ';Secure' : '');
	}
	window.dxpsInitIdentity = function(){
	if (blocked() || (window.dxpsMarketingAllowed && !window.dxpsMarketingAllowed())) return;
	// External ID (first-party cross-session identifier) — cookie name: pbid.
	if (getCookie('_dxps_eid') && !getCookie('pbid')) setCookie('pbid', getCookie('_dxps_eid'), 365); // migrate
	if (!getCookie('pbid')) {
		var eid = ([1e7] + -1e3 + -4e3 + -8e3 + -1e11).replace(/[018]/g, function (c) {
			return (c ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> c / 4).toString(16);
		}).replace(/-/g, '');
		setCookie('pbid', eid, 365);
	}
	// GA client-id fallback.
	if (!getCookie('_ga') && !getCookie('_dxps_cid')) {
		setCookie('_dxps_cid', Math.floor(Math.random() * 9e8 + 1e8) + '.' + Math.floor(Date.now() / 1000), 365);
	}
	// UTM / click-id capture into first-party cookie (90 days, first-touch kept).
	(function () {
		var q = new URLSearchParams(location.search), keep = {}, keys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid'];
		keys.forEach(function (k) { if (q.get(k)) keep[k] = q.get(k); });
		if (Object.keys(keep).length && !getCookie('_dxps_src')) {
			setCookie('_dxps_src', encodeURIComponent(JSON.stringify(keep)), 90);
		}
		if (q.get('fbclid') && !getCookie('_fbc')) {
			setCookie('_fbc', 'fb.1.' + Date.now() + '.' + q.get('fbclid'), 90);
		}
	})();

	if(META_PIXEL&&window.dxpsMetaReady&&typeof fbq==='function'&&getCookie('pbid')){try{fbq('init',META_PIXEL,{external_id:getCookie('pbid')});}catch(e){}}
	if (!getCookie('_dxps_lp')) setCookie('_dxps_lp', encodeURIComponent(safeUrl(location.href).slice(0, 300)), 90);
	};
	window.dxpsInitIdentity();

	/* ---------- dispatcher ---------- */
	var metaMap = {
		view_item: 'ViewContent', view_item_list: 'ViewCategory', add_to_cart: 'AddToCart',
		remove_from_cart: 'RemoveFromCart', view_cart: 'ViewCart', begin_checkout: 'InitiateCheckout', add_to_wishlist:'AddToWishlist', add_payment_info:'AddPaymentInfo', add_shipping_info:'AddShippingInfo',
		search: 'Search', generate_lead: 'Lead', purchase: 'Purchase',
		// These must mirror class-dxps-server.php's ga4_name_to_meta() exactly — a browser/server
		// name mismatch on the same event_id breaks Meta's deduplication (it requires an EXACT
		// event_name match, not just a matching event_id), which was silently splitting these into
		// two separate, uncoordinated event buckets in Events Manager (e.g. "scroll" vs "PageScroll").
		scroll: 'PageScroll', time_on_page: 'TimeOnPage', internal_click: 'InternalClick',
		form_submit: 'Form', file_download: 'Download', login: 'Login', sign_up: 'CompleteRegistration', comment: 'Comment'
	};
	var metaStandard = { ViewContent: 1, AddToCart: 1, InitiateCheckout: 1, AddToWishlist:1, AddPaymentInfo:1, Search: 1, Lead: 1, Purchase: 1, CompleteRegistration: 1 };

	function metaParams(name, p) {
		var out = {};if(typeof p.value!=='undefined'||(p.items&&p.items.length))out.currency=p.currency||cfg.currency;
		if (typeof p.value === 'number' && Number.isFinite(p.value) && p.value>=0) out.value = p.value;
		if (p.items && p.items.length) {
			out.content_type = 'product';
			out.content_ids = p.items.map(function (i) { return i.id; });
			out.contents = p.items.map(function (i) { return { id: i.id, quantity: i.quantity || 1, item_price: i.price || 0 }; });
			// Catalog IDs and prices suffice; do not send descriptive product or condition text.
		}
		// Free-text search terms are not sent to Meta.
		if (p.transaction_id) out.order_id = p.transaction_id;
		['percent_scrolled','time_on_page','lead_type'].forEach(function(k){if(typeof p[k]!=='undefined')out[k]=p[k];});
		return out;
	}

	// Landing page (first-touch) cookie.

	var dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
	var monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
	function globalCtx() {
		var d = new Date();
		return Object.assign({}, cfg.ctx || {}, {
			event_url: safeUrl(location.href),
			landing_page: getCookie('_dxps_lp') ? safeUrl(decodeURIComponent(getCookie('_dxps_lp'))) : '',
			event_time: ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2),
			event_day: dayNames[d.getDay()],
			event_month: monthNames[d.getMonth()]
		});
	}

	window.dxpsFire = function (e) {
		if (blocked(e.params) || sensitiveRx.test(e.name) || (window.dxpsMarketingAllowed && !window.dxpsMarketingAllowed())) return;
		var name=e.name,supplied=e.event_id||'',id;
		// Cached PHP view payloads need a fresh visitor/page-load ID, shared by both channels.
		if(e._dxps_cacheable&&name!=='purchase')id=cachedIDs[supplied||name]||(cachedIDs[supplied||name]=eventID(name));
		else id=supplied||eventID(name);
		// PHP footer and deferred frontend initialization may deliver the same queue twice.
		if(dispatchedIDs[id])return;dispatchedIDs[id]=true;dispatchOrder.push(id);if(dispatchOrder.length>512)delete dispatchedIDs[dispatchOrder.shift()];
		var p = Object.assign(globalCtx(), e.params || {});
		if(p.items)p.items=p.items.filter(function(i){return i&&i.id;}).slice(0,100).map(function(i){var x=Object.assign({},i);x.id=String(i.id).slice(0,100);x.quantity=Math.max(1,Math.min(9999,parseInt(i.quantity,10)||1));if(typeof i.price!=='number'||!Number.isFinite(i.price)||i.price<0)delete x.price;return x;});
		if(typeof p.value!=='undefined'&&(typeof p.value!=='number'||!Number.isFinite(p.value)||p.value<0))delete p.value;
		var scope = p._scope || 'all';
		['page_title','user_role','tags','post_category','category_name','link_text','search_term','utm_term','utm_content','transactions_count','average_order','predicted_ltv'].forEach(function(k){delete p[k]});
		['event_url','landing_page','link_url','form_action'].forEach(function(k){if(p[k])p[k]=safeUrl(p[k])});
		var amDelivery=window.dxpsAmFlush?window.dxpsAmFlush():null;

		/* Google Ads conversion (purchase / lead), with Enhanced Conversions */
		if (cfg.googleDirect && p._gads_send_to && typeof gtag === 'function') {
			if (p._ec) gtag('set', 'user_data', p._ec);
			gtag('event', 'conversion', {
				send_to: p._gads_send_to,
				value: p.value || 0,
				currency: p.currency || cfg.currency,
				transaction_id: p.transaction_id || '',
				new_customer: p.new_customer
			});
		}
		// Status-mode purchases still reach Google via GTM; only the Meta browser/relay leg is suppressed.

		/* GA4 / GTM dataLayer */
		var glp = {};
		// GA4 hard limit: 25 event parameters per event (items[] excluded). Anything beyond
		// that is silently DROPPED by GA4 — we were sending ~40, so which ones survived was
		// arbitrary. Ecommerce params come first and are never sacrificed; the descriptive
		// ones fill whatever budget is left, in priority order.
		var GA4_PARAM_LIMIT = 25;
		var coreKeys = ['items', 'value', 'currency', 'transaction_id', 'coupon', 'search_term', 'item_list_name',
			'lead_type', 'new_customer', 'num_items', 'total', 'subtotal', 'tax', 'shipping', 'payment_method'];
		var extraKeys = ['page_title', 'post_type', 'post_id', 'post_category', 'category_name', 'user_role',
			'traffic_source', 'landing_page', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
			'percent_scrolled', 'time_on_page', 'link_url', 'link_text', 'form_id', 'form_action', 'file_name',
			'file_extension', 'brand', 'coupon_used', 'coupon_name', 'shipping_cost', 'fees', 'transactions_count',
			'average_order', 'predicted_ltv', 'tags', 'plugin', 'event_url', 'event_time', 'event_day', 'event_month'];
		coreKeys.forEach(function (k) {
			if (typeof p[k] !== 'undefined' && p[k] !== '') glp[k] = p[k];
		});
		extraKeys.forEach(function (k) {
			if (Object.keys(glp).length >= GA4_PARAM_LIMIT) return;
			if (typeof p[k] !== 'undefined' && p[k] !== '') glp[k] = p[k];
		});
		if (!glp.currency && (name === 'purchase' || typeof glp.value !== 'undefined')) glp.currency = cfg.currency;
		if (glp.items) glp.items = glp.items.map(function (i) {
			return { item_id: i.id, item_name: i.name, price: i.price, quantity: i.quantity || 1, item_category: i.category || '', item_brand: i.brand || undefined, index: i.index };
		});
		if (cfg.engineDirect) {
			// Direct Google mode: the enriched engine (dxps-google.js) owns GA4 / Google Ads events;
			// DX only hands over the events the engine cannot see (server-rendered purchase, tel/WhatsApp leads, forms ...).
			if (window.dxpsGoogleRoute) window.dxpsGoogleRoute(name, glp, id);
		} else {
			window.dataLayer = window.dataLayer || [];
			// Google requires clearing the previous ecommerce object first, otherwise fields from
			// an earlier event (e.g. a prior view_item's items[]) persist and merge into this one.
			dataLayer.push({ ecommerce: null });
			dataLayer.push({ event: name, ecommerce: glp, event_id: id, dxps_event_id: id });
			if (cfg.googleDirect && typeof gtag === 'function') gtag('event', name, glp);
		}

		if(scope==='gads_only'||scope==='google_only')return;

		/* Meta — trackSingle: events go ONLY to this plugin's pixel ID,
		   never to other pixels initialised on the page (e.g. PixelYourSite). */
		var mName = metaMap[name] || name;
		var mArgs = [metaStandard[mName] ? 'trackSingle' : 'trackSingleCustom', META_PIXEL, mName, metaParams(mName, p), { eventID: id }];
		if (META_PIXEL) {
			if (window.dxpsMetaReady && typeof fbq === 'function') {
				fbq.apply(null, mArgs);
			} else if (window.dxpsQ) {
				window.dxpsQ.push(mArgs); // fires after consent grants + pixel init.
			}
		}

		/* Flush completed matching fields first so the first conversion can use them. */
		if (cfg.relay) waitMatching(amDelivery).catch(function(){}).then(function(){return relay({name:name,event_id:id,params:p,url:safeUrl(location.href)});});
	};

	// Mirror DX's own Meta PageView only; do not emit another GA4/dataLayer page_view.
	window.dxpsMetaPageView = function(id){
		if (!META_PIXEL || blocked() || (window.dxpsMarketingAllowed && !window.dxpsMarketingAllowed())) return;
		waitMatching(window.dxpsAmFlush ? window.dxpsAmFlush() : null).catch(function(){}).then(function(){return relay({name:'page_view',event_id:id,params:{},url:safeUrl(location.href)});});
	};
	// Initial events run after the consented matching scan is installed.


	/* ---------- AJAX add to cart ---------- */
	$(document.body).on('added_to_cart', function (evt, fragments, hash, $button) {
		if (!$button || !$button.length) return;
		var item = {id:String($button.data('product_id')||''),quantity:parseInt($button.data('quantity'),10)||1};
		var price=$button.data('dxps-price'),params={items:[item]};
		if(typeof price!=='undefined'&&Number.isFinite(Number(price))&&Number(price)>=0){item.price=Number(price);params.value=item.price*item.quantity;}
		window.dxpsFire({name:'add_to_cart',params:params});
	});

	/* Actual user checkout selections, not guessed payment/shipping conversions. */
	var checkoutSeen={};
	document.addEventListener('change',function(ev){var el=ev.target;if(!el||!el.closest('form.checkout,.wc-block-checkout'))return;var n=el.name||'',event=n==='payment_method'?'add_payment_info':/^shipping_method/.test(n)?'add_shipping_info':'';if(!event||checkoutSeen[event]===el.value)return;checkoutSeen[event]=el.value;var e=(window.dxpsEvents||[]).find(function(e){return e.name==='begin_checkout'});window.dxpsFire({name:event,params:e?e.params:{}});},true);
	$(document.body).on('added_to_wishlist',function(ev,button){var el=button&&button.jquery?button[0]:button;if(!el||!el.dataset||!el.dataset.productId)return;window.dxpsFire({name:'add_to_wishlist',params:{items:[{id:String(el.dataset.productId),quantity:1}]}});});

	/* ---------- PageScroll: 25 / 50 / 75 / 90 ---------- */
	if (cfg.scroll) {
		var scrollFired = {};
		window.addEventListener('scroll', function () {
			var h = document.documentElement, max = h.scrollHeight - h.clientHeight;
			if (max <= 0) return;
			var pct = Math.round(100 * (window.pageYOffset || h.scrollTop) / max);
			[25, 50, 75, 90].forEach(function (t) {
				if (pct >= t && !scrollFired[t]) {
					scrollFired[t] = 1;
					window.dxpsFire({ name: 'scroll', params: { percent_scrolled: t } });
				}
			});
		}, { passive: true });
	}

	/* ---------- TimeOnPage ---------- */
	if (cfg.timeOnPage > 0) {
		setTimeout(function () {
			window.dxpsFire({ name: 'time_on_page', params: { time_on_page: cfg.timeOnPage } });
		}, cfg.timeOnPage * 1000);
	}

	/* ---------- Internal link clicks ---------- */
	if (cfg.clicks) {
		var clickCount = 0;
		document.addEventListener('click', function (ev) {
			var a = ev.target.closest && ev.target.closest('a');
			if (!a || !a.href || clickCount >= 15) return;
			var isHttp = a.protocol === 'http:' || a.protocol === 'https:';
			if (!isHttp || a.host !== location.host) return; // internal links only
			if (a.href === location.href || (a.hash && a.pathname === location.pathname)) return; // skip anchors
			clickCount++;
			window.dxpsFire({
				name: 'internal_click',
				params: {
					link_url: a.href.slice(0, 300),
					link_text: (a.textContent || a.getAttribute('aria-label') || '').trim().slice(0, 100)
				}
			});
		}, true);
	}

	/* ---------- form submissions ---------- */
	if (cfg.forms) {
		document.addEventListener('submit', function (ev) {
			var f = ev.target;
			if (!f || f.nodeName !== 'FORM') return;
			if (f.className && /cart|checkout|login/i.test(f.className)) return; // covered by dedicated events
			window.dxpsFire({ name: 'form_submit', params: {
				form_id: (f.id || f.getAttribute('name') || 'form').slice(0, 80),
				form_action: (f.getAttribute('action') || location.pathname).slice(0, 200)
			}});
		}, true);
	}

	/* ---------- file downloads ---------- */
	if (cfg.downloads) {
		document.addEventListener('click', function (ev) {
			var a = ev.target.closest && ev.target.closest('a');
			if (!a || !a.href) return;
			var m = a.pathname && a.pathname.match(/\.(pdf|zip|rar|doc|docx|xls|xlsx|csv|mp3|mp4|jpg|png|txt)$/i);
			if (!m) return;
			window.dxpsFire({ name: 'file_download', params: {
				file_name: a.pathname.split('/').pop().slice(0, 120),
				file_extension: m[1].toLowerCase(),
				link_url: a.href.slice(0, 300)
			}});
		}, true);
	}

	/* ---------- custom events (settings: selector | event_name) ---------- */
	(cfg.custom || []).forEach(function (ce) {
		try {
			document.addEventListener('click', function (ev) {
				if (ev.target.closest && ev.target.closest(ce.sel)) {
					window.dxpsFire({ name: ce.name, params: { trigger_selector: ce.sel } });
				}
			}, true);
		} catch (e) {}
	});

	/* ---------- Automatic Advanced Matching: detect customer fields in forms ----------
	   Scans inputs the visitor fills in (checkout, contact, account, newsletter) and feeds
	   what it finds into both channels: the Meta pixel re-init (browser Advanced Matching,
	   plain values which the pixel hashes locally) and the /u endpoint (which hashes them
	   server-side and stores them for CAPI). This is what raises Meta's "percent of events
	   sending" for phone / first name / last name / city / zip.

	   NEVER captured: passwords, payment card numbers, CVV/CVC, IBAN, or any field marked
	   with data-dxps-ignore. Those exclusions are matched first and are absolute. */
	if (cfg.autoAm) {
		var amFound = {}, amTimer = null, amPending = Promise.resolve(), amLast = '';
		function normalize(k,v,c){v=String(v||'').trim().toLowerCase();if(k==='ph'){var n=v.replace(/\D/g,''),cc={ba:'387',rs:'381',hr:'385',si:'386',de:'49',at:'43',ch:'41',me:'382',mk:'389',gb:'44',us:'1',ca:'1',fr:'33',it:'39',nl:'31',se:'46'}[c];if(/^00/.test(v))n=n.slice(2);else if(!/^\+/.test(v)){if(!cc)return '';if(n.indexOf(cc)!==0)n=cc+n.replace(/^0+/, '');}return /^[1-9]\d{7,14}$/.test(n)?n:'';}if(k==='em')return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)?v:'';if(k==='country')return /^[a-z]{2}$/.test(v)?v:'';if(k==='zp'){v=v.replace(/[^a-z0-9]/g,'');return c==='us'?v.slice(0,5):v;}return v.replace(/[^\p{L}]/gu,'');}
		function digest(v){return crypto.subtle.digest('SHA-256',new TextEncoder().encode(v)).then(function(buf){return Array.from(new Uint8Array(buf)).map(function(n){return n.toString(16).padStart(2,'0')}).join('')});}

		var AM_BLOCK = /pass|pwd|card|cc-|cvv|cvc|csc|iban|swift|account.*number|routing|secur|diagnos|health|medical|birth|gender/i;
		var AM_RULES = [
			{ k: 'em', re: /(^|[_\-\[.])(email|e-mail|mail|billing_email|user_email)/i, type: 'email' },
			{ k: 'ph', re: /(^|[_\-\[.])(phone|tel|mobile|billing_phone|telefon)/i, type: 'tel' },
			{ k: 'fn', re: /(^|[_\-\[.])(fname|firstname|first_name|given|billing_first_name|ime)/i },
			{ k: 'ln', re: /(^|[_\-\[.])(lname|lastname|last_name|surname|family|billing_last_name|prezime)/i },
			{ k: 'ct', re: /(^|[_\-\[.])(city|town|billing_city|grad)/i },
			{ k: 'zp', re: /(^|[_\-\[.])(zip|postcode|postal|billing_postcode|posta)/i },
			{ k: 'st', re: /(^|[_\-\[.])(state|province|region|billing_state)/i },
			{ k: 'country', re: /(^|[_\-\[.])(country|billing_country|drzava)/i }
		];

		function amClassify(el) {
			if(!el.closest('form.checkout,form.woocommerce-checkout,form.woocommerce-form-login,form.woocommerce-form-register,form.woocommerce-EditAccountForm,[data-dxps-match],.wc-block-checkout'))return null;
			if(el.disabled||el.type==='hidden'||!el.checkValidity()||el.closest('[data-dxps-ignore]'))return null;
			var hay = [el.name, el.id, el.getAttribute('autocomplete'), el.getAttribute('aria-label'), el.placeholder]
				.filter(Boolean).join(' ');
			if (!hay) return null;
			if (AM_BLOCK.test(hay) || el.type === 'password' || el.getAttribute('data-dxps-ignore') !== null) return null;
			if (el.type === 'email') return 'em';
			if (el.type === 'tel') return 'ph';
			for (var i = 0; i < AM_RULES.length; i++) {
				if (AM_RULES[i].re.test(hay)) return AM_RULES[i].k;
			}
			return null;
		}

		function amFlush() {
			if (blocked() || (window.dxpsMarketingAllowed && !window.dxpsMarketingAllowed())) {amFound={};return Promise.resolve();}
			if (!Object.keys(amFound).length) return amPending;
			var raw=amFound,payload={},country=normalize('country',raw.country||'');amFound={};
			Object.keys(raw).forEach(function(k){var v=normalize(k,raw[k],country);if(v)payload[k]=v;});
			var sig=JSON.stringify(payload);if(!Object.keys(payload).length||sig===amLast)return amPending;amLast=sig;
			if (META_PIXEL && window.dxpsMetaReady && typeof fbq==='function') {try {fbq('init',META_PIXEL,payload);}catch(e){}}
			if(cfg.userIngest && crypto.subtle) amPending=amPending.catch(function(){}).then(function(){
				if(window.dxpsMarketingAllowed&&!window.dxpsMarketingAllowed())return;
				return Promise.all(Object.keys(payload).map(function(k){return digest(payload[k]).then(function(v){return [k,v]})})).then(function(pairs){var hashed={};pairs.forEach(function(v){hashed[v[0]]=v[1]});return fetch(cfg.userIngest,{method:'POST',credentials:'same-origin',keepalive:true,headers:{'Content-Type':'application/json'},body:JSON.stringify({user:hashed,hashed:true,url:safeUrl(location.href)})});});
			});
			return amPending;
		}
		window.dxpsAmFlush=amFlush;

		function amCapture(el) {
			if (blocked() || (window.dxpsMarketingAllowed && !window.dxpsMarketingAllowed())) return;
			if (!el || !el.value || el.value.length < 2 || el.value.length > 200) return;
			var key = amClassify(el);
			if (!key) return;
			var v = el.value.trim().toLowerCase();
			if (key === 'em' && v.indexOf('@') === -1) return;
			if (key === 'ph' && v.replace(/[^0-9]/g, '').length < 6) return;
			if (amFound[key] === v) return;
			amFound[key] = v;
			var countryEl=el.closest('form,.wc-block-checkout,[data-dxps-match]')?.querySelector('[name=billing_country],select[autocomplete=country],input[autocomplete=country]');if(countryEl&&countryEl.value)amFound.country=countryEl.value.toLowerCase();
			clearTimeout(amTimer);
			amTimer = setTimeout(amFlush, 800); // debounce: one call per burst of typing
		}

		document.addEventListener('submit',function(){document.querySelectorAll('input, select').forEach(amCapture);amFlush();},true);
		document.addEventListener('dxps:consent',function(ev){if(ev.detail==='denied'){amFound={};amLast='';clearTimeout(amTimer)}else{document.querySelectorAll('input, select').forEach(amCapture);amFlush();}});
		document.addEventListener('change', function (ev) { amCapture(ev.target); }, true);
		document.addEventListener('blur', function (ev) { amCapture(ev.target); }, true);
		// Catch values already filled in (browser autofill, returning customers, multi-step checkout).
		$(function () {
			document.querySelectorAll('input, select').forEach(amCapture);
			amFlush();
		});
		$(document.body).on('updated_checkout', function () {
			document.querySelectorAll('input, select').forEach(amCapture);
		});
	}

	/* ---------- lead clicks: WhatsApp / tel / mailto ---------- */
	if (cfg.leads) {
		var leadFired = {};
		document.addEventListener('click', function (ev) {
			var a = ev.target.closest && ev.target.closest('a');
			if (!a || !a.href) return;
			var type = '';
			if (/wa\.me|api\.whatsapp\.com|whatsapp:\/\//.test(a.href)) type = 'whatsapp';
			else if (a.href.indexOf('tel:') === 0) type = 'phone';
			else if (a.href.indexOf('mailto:') === 0) type = 'email';
			if (!type || leadFired[type]) return;
			leadFired[type] = 1; // once per page per channel.
			var p = { lead_type: type, value: 0 };
			if (cfg.gadsLead) { p._gads_send_to = cfg.gadsLead; }
			window.dxpsFire({ name: 'generate_lead', params: p });
		}, true);
	}
	$(function(){if(window.dxpsPendingPageView){window.dxpsMetaPageView(window.dxpsPendingPageView);window.dxpsPendingPageView='';}(window.dxpsEvents||[]).forEach(window.dxpsFire);});
})(jQuery);

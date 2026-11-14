/* DX Pixel Suite — front-end dispatcher */
(function ($) {
	'use strict';
	var cfg = window.dxpsCfg || {};
	var META_PIXEL = cfg.metaPixel || '';
	var dispatchedIDs=Object.create(null),dispatchOrder=[],cachedIDs=Object.create(null);
	function eventID(name){return name+'.'+(window.crypto&&crypto.randomUUID?crypto.randomUUID():Date.now()+'.'+Math.random().toString(36).slice(2));}
	var sensitiveRx = /alopeci|kemoterap|chemotherap|onkolog|leukem|cancer|psorij|dermatit|\bdiagnos|\bdiagnostic|\bmedical|\bpatient|\brak\b/i;
	function blocked(p){var u=location.href;try{u=decodeURIComponent(u)}catch(e){}return !!cfg.blockedContext||sensitiveRx.test(u+' '+JSON.stringify(p||{}));}
	function safeUrl(v){try{var u=new URL(v,location.href);return u.origin===location.origin?u.origin+u.pathname.replace(/\/(order-received|order-pay)\/\d+\/?$/, '/') : location.origin+'/';}catch(e){return location.origin+'/';}}
	function waitMatching(p){return Promise.race([Promise.resolve(p),new Promise(function(resolve){setTimeout(resolve,750)})]);}
	function apiHeaders(){var h={'Content-Type':'application/json'};if(cfg.nonce)h['X-WP-Nonce']=cfg.nonce;return h;}
	function relay(body,attempt){if(!cfg.relay||blocked(body.params)||(window.dxpsMarketingAllowed&&!window.dxpsMarketingAllowed()))return Promise.resolve();return fetch(cfg.relay,{method:'POST',credentials:'same-origin',keepalive:true,headers:apiHeaders(),body:JSON.stringify(body)}).then(function(r){if((r.status===429||r.status>=500)&&!attempt)return new Promise(function(resolve){setTimeout(resolve,1000)}).then(function(){return relay(body,1)});return r;}).catch(function(){if(!attempt)return relay(body,1);});}

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
		// A new ad click (different fbclid) replaces the stored click ID; the same click keeps its timestamp.
		var fbclid = q.get('fbclid'), curFbc = getCookie('_fbc');
		if (fbclid && /^[A-Za-z0-9_-]{6,400}$/.test(fbclid) && (!curFbc || curFbc.slice(-(fbclid.length + 1)) !== '.' + fbclid)) {
			setCookie('_fbc', 'fb.1.' + Date.now() + '.' + fbclid, 90);
		}
	})();

	// 1.7.0 (opt-in): if Meta's script is blocked it never sets _fbp. After giving it time, create one in
	// Meta's own format (fb.1.<ms>.<random>) so server events still carry a browser ID.
	if (cfg.fbpFallback && !getCookie('_fbp')) setTimeout(function () {
		if (getCookie('_fbp') || (window.dxpsMarketingAllowed && !window.dxpsMarketingAllowed())) return;
		setCookie('_fbp', 'fb.1.' + Date.now() + '.' + Math.floor(Math.random() * 9e9 + 1e9), 90);
	}, 2500);

	if(META_PIXEL&&window.dxpsMetaReady&&typeof fbq==='function'&&getCookie('pbid')){try{fbq('init',META_PIXEL,{external_id:getCookie('pbid')});}catch(e){}}
	if (!getCookie('_dxps_lp')) setCookie('_dxps_lp', encodeURIComponent(safeUrl(location.href).slice(0, 300)), 90);
	};
	window.dxpsInitIdentity();
	var googlePageSent=false;
	function googlePageView(){if(googlePageSent||blocked()||!cfg.googleDirect||!cfg.ga4Id||(window.dxpsMarketingAllowed&&!window.dxpsMarketingAllowed()))return;googlePageSent=true;if(typeof gtag==='function')gtag('event','page_view',{send_to:cfg.ga4Id,page_location:safeUrl(location.href),page_title:(cfg.ctx&&cfg.ctx.post_type)||'page'});}
	googlePageView();

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
		form_submit: 'Form', file_download: 'Download', login: 'Login', sign_up: 'CompleteRegistration', comment: 'Comment',
		outbound_click: 'OutboundClick'
	};
	var metaStandard = { ViewContent: 1, AddToCart: 1, InitiateCheckout: 1, AddToWishlist:1, AddPaymentInfo:1, Search: 1, Lead: 1, Purchase: 1, CompleteRegistration: 1 };
	// 1.8.0 content events: names chosen in settings (ViewContent/ViewCategory or ContentView/ContentList).
	Object.keys(cfg.contentMeta || {}).forEach(function (k) { metaMap[k] = cfg.contentMeta[k]; });
	// Custom rules named after a Meta standard event (Lead, Schedule, Contact ...) are sent as that standard event.
	(cfg.custom || []).forEach(function (ce) { if (ce.meta) { metaMap[ce.name] = ce.meta; metaStandard[ce.meta] = 1; } });

	function metaParams(name, p) {
		var out = {};if(typeof p.value!=='undefined'||(p.items&&p.items.length))out.currency=p.currency||cfg.currency;
		if (typeof p.value === 'number' && Number.isFinite(p.value) && p.value>=0) out.value = p.value;
		if (p.items && p.items.length) {
			out.content_type = p.content_type === 'product_group' ? 'product_group' : 'product';
			out.content_ids = p.items.map(function (i) { return i.id; });
			out.contents = p.items.map(function (i) { return { id: i.id, quantity: i.quantity || 1, item_price: i.price || 0 }; });
			// Catalog IDs and prices suffice; do not send descriptive product or condition text.
		}
		else if (p.content_id) {
			// Blog post / page / service: post ID and a short type key only.
			out.content_ids = [String(p.content_id)];
			if (p.content_type) out.content_type = String(p.content_type);
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
		var landing='';try{landing=getCookie('_dxps_lp')?safeUrl(decodeURIComponent(getCookie('_dxps_lp'))):''}catch(ignore){}
		return Object.assign({}, cfg.ctx || {}, {
			event_url: safeUrl(location.href),
			landing_page: landing,
			event_time: ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2),
			event_day: dayNames[d.getDay()],
			event_month: monthNames[d.getMonth()]
		});
	}

	window.dxpsFire = function (e) {
		if (blocked(e.params) || sensitiveRx.test(e.name) || (window.dxpsMarketingAllowed && !window.dxpsMarketingAllowed())) return;
		if(!e||!e.name)return;
		var name=e.name,supplied=e.event_id||'',id;
		if(name==='purchase'&&e.params&&e.params.transaction_id){try{var purchaseKey='dxps_purchase_'+e.params.transaction_id;if(sessionStorage.getItem(purchaseKey))return;sessionStorage.setItem(purchaseKey,'1')}catch(ignore){}}
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

		var skipGoogle = scope === 'meta_only' || scope === 'gads_only' || (name==='purchase'&&cfg.ga4ServerPurchase); // the Google engine already reports this action itself

		/* Google Ads conversion (purchase / lead), with Enhanced Conversions */
		if (scope !== 'meta_only' && cfg.googleDirect && p._gads_send_to && typeof gtag === 'function') {
			if (p._ec) gtag('set', 'user_data', p._ec);
			else if(window.__ehHashedCustomerData&&window.__ehHashedCustomerData.google)gtag('set','user_data',window.__ehHashedCustomerData.google);
			gtag('event', 'conversion', {
				send_to: p._gads_send_to,
				value: p.value || 0,
				currency: p.currency || cfg.currency,
				transaction_id: p.transaction_id || '',
				new_customer: p.new_customer
			});
		}
		// Status-mode purchases still reach Google via GTM; only the Meta browser/relay leg is suppressed.

		if(cfg.gtmActive&&name==='purchase'&&skipGoogle&&scope!=='meta_only'){window.dataLayer=window.dataLayer||[];dataLayer.push({ecommerce:null});dataLayer.push({event:'dxps_ads_purchase',ecommerce:{transaction_id:p.transaction_id,value:p.value,currency:p.currency||cfg.currency},event_id:id});}

		/* GA4 / GTM dataLayer */
		var glp = {};
		// GA4 hard limit: 25 event parameters per event (items[] excluded). Anything beyond
		// that is silently DROPPED by GA4 — we were sending ~40, so which ones survived was
		// arbitrary. Ecommerce params come first and are never sacrificed; the descriptive
		// ones fill whatever budget is left, in priority order.
		var GA4_PARAM_LIMIT = 25;
		var coreKeys = ['items', 'value', 'currency', 'transaction_id', 'coupon', 'search_term', 'item_list_name',
			'lead_type', 'new_customer', 'num_items', 'total', 'subtotal', 'tax', 'shipping', 'payment_method',
			'content_type', 'content_id'];
		var extraKeys = ['content_group', 'page_title', 'post_type', 'post_id', 'post_category', 'category_name', 'user_role',
			'traffic_source', 'landing_page', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
			'percent_scrolled', 'time_on_page', 'link_url', 'link_text', 'form_id', 'form_action', 'file_name',
			'file_extension', 'link_domain', 'brand', 'coupon_used', 'coupon_name', 'shipping_cost', 'fees', 'transactions_count',
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
		if (skipGoogle) {
			// nothing to send to Google
		} else if (cfg.engineDirect) {
			// Direct Google mode: the enriched engine (dxps-google.js) owns GA4 / Google Ads events;
			// DX only hands over the events the engine cannot see (server-rendered purchase, tel/WhatsApp leads, forms ...).
			if (window.dxpsGoogleRoute) window.dxpsGoogleRoute(name, glp, id);
		} else {
			window.dataLayer = window.dataLayer || [];
			// Google requires clearing the previous ecommerce object first, otherwise fields from
			// an earlier event (e.g. a prior view_item's items[]) persist and merge into this one.
			dataLayer.push({ ecommerce: null });
			dataLayer.push({ event: name, ecommerce: glp, event_id: id, dxps_event_id: id });
			if (cfg.googleDirect && cfg.ga4Id && typeof gtag === 'function') gtag('event', name, Object.assign({}, glp, {send_to:cfg.ga4Id}));
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
		var item = {id:String($button.attr('data-dxps-id')||$button.data('product_id')||''),quantity:parseInt($button.data('quantity'),10)||1};
		var price=$button.data('dxps-price'),params={items:[item]};
		if(typeof price!=='undefined'&&Number.isFinite(Number(price))&&Number(price)>=0){item.price=Number(price);params.value=item.price*item.quantity;}
		window.dxpsFire({name:'add_to_cart',params:params});
	});

	$(document.body).on('found_variation', 'form.variations_form', function(ev,variation){if(!variation||!variation.dxps_item)return;window.dxpsFire({name:'view_item',params:{items:[variation.dxps_item],value:variation.dxps_item.price,currency:cfg.currency}});});

	var cartRead=Promise.resolve();
	['wc-blocks_added_to_cart','wc-blocks_removed_from_cart'].forEach(function(event){document.addEventListener(event,function(ev){if(ev.detail&&ev.detail.preserveCartData)return;if(!cfg.cartEventsUrl||(window.dxpsMarketingAllowed&&!window.dxpsMarketingAllowed()))return;cartRead=cartRead.catch(function(){}).then(function(){return fetch(cfg.cartEventsUrl,{method:'POST',credentials:'same-origin'}).then(function(r){return r.json()}).then(function(r){if(r.success&&Array.isArray(r.data))r.data.forEach(window.dxpsFire)});});});});

	/* Actual user checkout selections, not guessed payment/shipping conversions. */
	var checkoutSeen={};
	document.addEventListener('change',function(ev){var el=ev.target;if(!el||!el.closest('form.checkout,.wc-block-checkout'))return;var n=el.name||'',event=n==='payment_method'?'add_payment_info':/^shipping_method/.test(n)?'add_shipping_info':'';if(!event||checkoutSeen[event]===el.value)return;checkoutSeen[event]=el.value;var e=(window.dxpsEvents||[]).find(function(e){return e.name==='begin_checkout'});window.dxpsFire({name:event,params:e?e.params:{}});},true);
	$(document.body).on('added_to_wishlist',function(ev,button){var el=button&&button.jquery?button[0]:button;if(!el||!el.dataset||!el.dataset.productId)return;window.dxpsFire({name:'add_to_wishlist',params:{items:[{id:String(el.dataset.productId),quantity:1}]}});});

	/* ---------- shared engagement meters (1.8.0) ----------
	   Scroll depth: the window, or a large scrolling container (themes and page builders that scroll an inner
	   element never move the window). Also checked on load, for pages restored at a scroll position.
	   Active time: counts only while the tab is visible, so a background tab does not fire and a throttled
	   timer does not lose the event. */
	var scrollWatch = [], maxScrollPct = 0;
	function checkScroll(ev) {
		var root = document.scrollingElement || document.documentElement, el = root, t = ev && ev.target;
		if (t && t.nodeType === 1 && t !== root && t !== document.body) {
			if (t.scrollHeight - t.clientHeight < window.innerHeight * 0.5) return; // small widget, not the page
			el = t;
		}
		if(window.dxpsMarketingAllowed&&!window.dxpsMarketingAllowed())return;
		var max = el.scrollHeight - el.clientHeight;
		if (max <= 0) return;
		var top = el === root ? (window.pageYOffset || root.scrollTop) : el.scrollTop;
		var pct = Math.min(100, Math.round(100 * top / max));
		if (pct <= maxScrollPct) return;
		maxScrollPct = pct;
		scrollWatch.forEach(function (w) { if (!w.done && pct >= w.pct) { w.done = 1; w.cb(w.pct); } });
	}
	function onScrollDepth(pct, cb) {
		if (!scrollWatch.length) {
			window.addEventListener('scroll', checkScroll, { passive: true });
			document.addEventListener('scroll', checkScroll, { passive: true, capture: true });
			$(function () { setTimeout(checkScroll, 300); });
		}
		scrollWatch.push({ pct: pct, cb: cb, done: 0 });
	}
	var timeWatch = [], activeMs = 0, lastTick = 0;
	function onActiveTime(seconds, cb) {
		if (!timeWatch.length) {
			lastTick = Date.now();
			setInterval(function () {
				var now = Date.now();
				if (!document.hidden && (!window.dxpsMarketingAllowed||window.dxpsMarketingAllowed())) activeMs += Math.min(now - lastTick, 2000);
				lastTick = now;
				timeWatch.forEach(function (w) { if (!w.done && activeMs >= w.s * 1000) { w.done = 1; w.cb(w.s); } });
			}, 1000);
		}
		timeWatch.push({ s: seconds, cb: cb, done: 0 });
	}

	/* ---------- PageScroll: 25 / 50 / 75 / 90 ---------- */
	if (cfg.scroll) {
		[25, 50, 75, 90].forEach(function (t) {
			onScrollDepth(t, function () { window.dxpsFire({ name: 'scroll', params: { percent_scrolled: t } }); });
		});
	}

	/* ---------- TimeOnPage (visible time) ---------- */
	if (cfg.timeOnPage > 0) {
		onActiveTime(cfg.timeOnPage, function () {
			window.dxpsFire({ name: 'time_on_page', params: { time_on_page: cfg.timeOnPage } });
		});
	}

	/* ---------- Engaged read (1.8.0): scrolled far enough AND stayed long enough on a content page ---------- */
	if (cfg.content) {
		var readState = { s: 0, t: 0, done: 0 };
		var readCheck = function () {
			if (readState.done || !readState.s || !readState.t || (window.dxpsMarketingAllowed&&!window.dxpsMarketingAllowed())) return;
			readState.done = 1;
			window.dxpsFire({ name: 'content_engaged', params: {
				content_type: cfg.content.type, content_id: String(cfg.content.id),
				percent_scrolled: cfg.content.scroll, time_on_page: cfg.content.time
			} });
		};
		onScrollDepth(cfg.content.scroll, function () { readState.s = 1; readCheck(); });
		onActiveTime(cfg.content.time, function () { readState.t = 1; readCheck(); });
		// A page shorter than the screen cannot be scrolled: seeing all of it counts as the scroll part.
		$(function () { setTimeout(function () { var r = document.scrollingElement || document.documentElement; if (r.scrollHeight - r.clientHeight <= 0) { readState.s = 1; readCheck(); } }, 500); });
	}

	/* ---------- Internal link clicks ---------- */
	if (cfg.clicks) {
		var clickCount = 0;
		document.addEventListener('click', function (ev) {
			var a = ev.target.closest && ev.target.closest('a');
			if (!a || !a.href || clickCount >= 15) return;
			var isHttp = a.protocol === 'http:' || a.protocol === 'https:';
			var bare = function (h) { return String(h || '').replace(/^www\./, ''); };
			if (!isHttp || bare(a.host) !== bare(location.host)) return; // internal links only (www. and bare host are the same site)
			if (a.href === location.href || (a.hash && a.pathname === location.pathname)) return; // skip anchors
			if (/[?&]add-to-cart=/.test(a.search) || (a.classList && a.classList.contains('add_to_cart_button'))) return; // counted as AddToCart
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

	/* ---------- custom events (settings: trigger | event_name | options) ----------
	   Triggers: click (CSS selector), url, scroll, hover, submit, visits, copy.
	   Options : value, currency, value_param, block (minutes), need_fbc, device. */
	function lsGet(k) { try { return localStorage.getItem(k) || ''; } catch (e) { return ''; } }
	function lsSet(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
	function validSel(sel) { try { document.querySelector(sel); return true; } catch (e) { return false; } }
	function fireCustom(ce, extra) {
		var o = ce.opts || {}, p = Object.assign({ trigger_type: ce.type }, extra || {});
		if (window.dxpsMarketingAllowed && !window.dxpsMarketingAllowed()) return;
		if (o.device) { var mobile = window.matchMedia && window.matchMedia('(max-width: 767px)').matches; if ((o.device === 'mobile') !== !!mobile) return; }
		if (o.need_fbc && !getCookie('_fbc')) return;                       // blocking rule: only ad-click visitors
		if (o.block) {                                                      // blocking rule: not again within N minutes
			var key = 'dxps_blk_' + ce.name, last = parseInt(lsGet(key), 10) || 0;
			if (Date.now() - last < o.block * 60000) return;
			lsSet(key, String(Date.now()));
		}
		var v = typeof o.value === 'number' ? o.value : NaN;
		if (o.value_param) { var q = new URLSearchParams(location.search).get(o.value_param); if (q !== null && q !== '' && Number.isFinite(Number(q)) && Number(q) >= 0) v = Number(q); }
		if (Number.isFinite(v)) { p.value = v; p.currency = o.currency || cfg.currency; }
		window.dxpsFire({ name: ce.name, params: p });
	}
	var pageVisits = 0;
	if ((cfg.custom || []).some(function (ce) { return ce.type === 'visits'; }) && !(window.dxpsMarketingAllowed && !window.dxpsMarketingAllowed())) {
		pageVisits = (parseInt(lsGet('dxps_pv'), 10) || 0) + 1; lsSet('dxps_pv', String(pageVisits));
	}
	(cfg.custom || []).forEach(function (ce) {
		var type = ce.type || 'click', arg = ce.arg, done = false;
		function once(extra) { if (done) return; done = true; fireCustom(ce, extra); }
		if (type === 'click') {
			var sel = arg || ce.sel;
			// Validate once: an invalid selector would otherwise throw inside the listener on every click.
			if (!sel || !validSel(sel)) return;
			document.addEventListener('click', function (ev) {
				if (ev.target.closest && ev.target.closest(sel)) fireCustom(ce, { trigger_selector: sel });
			}, true);
		} else if (type === 'url') {
			// Exact path, or a prefix when the pattern ends with *. Trailing slashes are ignored.
			var pat = String(arg || ''), prefix = pat.slice(-1) === '*', want = (prefix ? pat.slice(0, -1) : pat).replace(/\/+$/, '') || '/';
			var path = location.pathname.replace(/\/+$/, '') || '/';
			if (prefix ? path.indexOf(want) === 0 : path === want) $(function () { once(); });
		} else if (type === 'scroll') {
			onScrollDepth(arg, function () { once({ percent_scrolled: arg }); });
		} else if (type === 'hover') {
			if (!validSel(arg)) return;
			document.addEventListener('mouseover', function (ev) { if (ev.target.closest && ev.target.closest(arg)) once(); }, true);
		} else if (type === 'submit') {
			if (!validSel(arg)) return;
			document.addEventListener('submit', function (ev) { if (ev.target.closest && ev.target.closest(arg)) fireCustom(ce); }, true);
		} else if (type === 'copy') {
			document.addEventListener('copy', function () { once(); }, true);
		} else if (type === 'visits') {
			if (pageVisits === arg) $(function () { once(); });
		}
	});

	/* ---------- form-plugin leads (opt-in): a successful submission becomes a Lead ---------- */
	if (cfg.formLeads) {
		var formLeadSeen = {};
		var formLead = function (plugin, id, engineOwns) {
			var key = plugin + ':' + (id || '');
			if (formLeadSeen[key]) return; formLeadSeen[key] = 1;
			var p = { lead_type: 'form', value: 0, form_id: String(id || plugin).slice(0, 80) };
			if (cfg.gadsLead) p._gads_send_to = cfg.gadsLead;
			// In Direct Google mode the engine already reports CF7 / WPForms leads to Google.
			// The shared dispatcher owns successful form events in every Google mode.
			window.dxpsFire({ name: 'generate_lead', params: p });
		};
		document.addEventListener('wpcf7mailsent', function (e) { formLead('cf7', e.detail && e.detail.contactFormId, true); });
		document.addEventListener('wpformsAjaxSubmitSuccess', function (e) { formLead('wpforms', e.detail && e.detail.formId, true); });
		document.addEventListener('forminator:form:submit:success', function (e) { formLead('forminator', e.target && e.target.id); });
		$(document).on('submit_success', function (e) { formLead('elementor', e.target && (e.target.id || e.target.getAttribute('name'))); });
		$(document).on('gform_confirmation_loaded', function (e, formId) { formLead('gravity', formId); });
		$(document).on('fluentform_submission_success', function (e, data) { formLead('fluent', data && data.form && data.form[0] && data.form[0].id); });
		$(document).on('nfFormSubmitResponse', function (e, data) { formLead('ninja', data && data.id); });
	}

	/* ---------- outbound link clicks (opt-in) ---------- */
	if (cfg.outbound) {
		var outboundCount = 0;
		document.addEventListener('click', function (ev) {
			var a = ev.target.closest && ev.target.closest('a');
			if (!a || !a.href || outboundCount >= 10) return;
			if ((a.protocol !== 'http:' && a.protocol !== 'https:') || a.host === location.host) return;
			if (/wa\.me|api\.whatsapp\.com/.test(a.href)) return; // counted as a lead instead
			outboundCount++;
			window.dxpsFire({ name: 'outbound_click', params: { link_domain: a.hostname.slice(0, 100) } });
		}, true);
	}

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
		function normalize(k,v,c){v=String(v||'').trim().toLowerCase();c=c||cfg.baseCountry||'';if(k==='ph'){var n=v.replace(/\D/g,''),cc={ba:'387',rs:'381',hr:'385',si:'386',de:'49',at:'43',ch:'41',me:'382',mk:'389',gb:'44',us:'1',ca:'1',fr:'33',it:'39',nl:'31',se:'46',pl:'48',es:'34',pt:'351',be:'32',cz:'420',sk:'421',hu:'36',ro:'40',bg:'359',gr:'30',ie:'353',dk:'45',no:'47',fi:'358',au:'61',nz:'64',tr:'90'}[c];if(/^00/.test(v))n=n.slice(2);else if(!/^\+/.test(v)){if(!cc)return '';if(n.indexOf(cc)!==0)n=cc+(c==='it'?n:n.replace(/^0+/, ''));}return /^[1-9]\d{7,14}$/.test(n)?n:'';}if(k==='em')return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)?v:'';if(k==='country')return /^[a-z]{2}$/.test(v)?v:'';if(k==='zp'){v=v.replace(/[^a-z0-9]/g,'');return c==='us'?v.slice(0,5):v;}return v.replace(/[^\p{L}]/gu,'');}
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
			if(!el.closest)return null;
			if(el.type==='search'||el.closest('form[role=search],.search-form,.woocommerce-product-search'))return null;
			// Default: checkout/account forms and forms opted in with data-dxps-match. Opt-in setting: any form.
			if(!(cfg.amAll&&el.closest('form'))&&!el.closest('form.checkout,form.woocommerce-checkout,form.woocommerce-form-login,form.woocommerce-form-register,form.woocommerce-EditAccountForm,[data-dxps-match],.wc-block-checkout'))return null;
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
			if(window.crypto && crypto.subtle) amPending=amPending.catch(function(){}).then(function(){
				if(window.dxpsMarketingAllowed&&!window.dxpsMarketingAllowed())return;
				return Promise.all(Object.keys(payload).map(function(k){return digest(payload[k]).then(function(v){return [k,v]})})).then(function(pairs){var hashed={};pairs.forEach(function(v){hashed[v[0]]=v[1]});var email=payload.em||'';if(/@(gmail|googlemail)\.com$/.test(email)){var ep=email.split('@');email=ep[0].replace(/\./g,'')+'@'+ep[1];}return Promise.all([email?digest(email):'',payload.ph?digest('+'+payload.ph):'']).then(function(g){if(window.dxpsMarketingAllowed&&!window.dxpsMarketingAllowed())return;var gd={};if(g[0])gd.sha256_email_address=g[0];if(g[1])gd.sha256_phone_number=g[1];window.__ehHashedCustomerData={google:gd};if(!cfg.userIngest)return;return fetch(cfg.userIngest,{method:'POST',credentials:'same-origin',keepalive:true,headers:apiHeaders(),body:JSON.stringify({user:hashed,hashed:true,url:safeUrl(location.href)})});});});
			});
			return amPending;
		}
		window.dxpsAmFlush=amFlush;

		// 1.7.0 (opt-in): identity from mapped URL parameters (email/SMS/booking links). Values go through the
		// same normalise -> hash -> ingest path as form fields and are never sent in an event URL.
		if ((cfg.urlIdentity || []).length && !blocked() && !(window.dxpsMarketingAllowed && !window.dxpsMarketingAllowed())) {
			var uq = new URLSearchParams(location.search);
			cfg.urlIdentity.forEach(function (m) {
				var v = (uq.get(m[0]) || '').trim().toLowerCase();
				if (v.length < 2 || v.length > 200) return;
				if (m[1] === 'name') { var sp = v.split(/\s+/); amFound.fn = sp[0]; if (sp.length > 1) amFound.ln = sp.slice(1).join(' '); }
				else amFound[m[1]] = v;
			});
			if (Object.keys(amFound).length) amFlush();
		}

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
	document.addEventListener('dxps:consent',function(ev){if(ev.detail==='granted'){googlePageView();(window.dxpsEvents||[]).filter(function(e){return /^view_|^begin_checkout$/.test(e.name)}).forEach(window.dxpsFire);checkScroll();}else{activeMs=0;maxScrollPct=0;timeWatch.forEach(function(w){w.done=0});scrollWatch.forEach(function(w){w.done=0});if(typeof readState!=='undefined'&&readState){readState.s=0;readState.t=0;readState.done=0}}});
	$(function(){if(window.dxpsPendingPageView){window.dxpsMetaPageView(window.dxpsPendingPageView);window.dxpsPendingPageView='';}(window.dxpsEvents||[]).forEach(window.dxpsFire);});
})(jQuery);

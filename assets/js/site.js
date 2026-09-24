/* =====================================================================
   SIPCONS · site.js — interacciones compartidas
   ===================================================================== */
(function () {
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---- Navbar: sólido al scroll + menú móvil ---- */
  const nav = document.getElementById('nav');
  if (nav) {
    const onScroll = () => nav.classList.toggle('scrolled', window.scrollY > 40);
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
    const toggle = document.getElementById('navToggle');
    if (toggle) {
      toggle.addEventListener('click', () => {
        const open = document.body.classList.toggle('nav-menu-open');
        toggle.setAttribute('aria-expanded', open);
      });
      document.querySelectorAll('.nav-links a').forEach(a =>
        a.addEventListener('click', () => document.body.classList.remove('nav-menu-open'))
      );
    }
  }

  /* ---- Reveal al scroll ---- */
  const revs = document.querySelectorAll('.reveal');
  if (revs.length) {
    const io = new IntersectionObserver((entries) => {
      entries.forEach(e => {
        if (e.isIntersecting) { e.target.classList.add('is-visible'); io.unobserve(e.target); }
      });
    }, { threshold: 0.15 });
    revs.forEach(el => io.observe(el));
  }

  /* ---- Contadores animados ---- */
  const fmt = n => n.toLocaleString('es-MX');
  const counters = document.querySelectorAll('.count');
  if (counters.length) {
    const animate = (el) => {
      const target = +el.dataset.target;
      if (reduce) { el.textContent = fmt(target); return; }
      const dur = 1400, start = performance.now();
      const step = (now) => {
        const p = Math.min((now - start) / dur, 1);
        const eased = 1 - Math.pow(1 - p, 3);
        el.textContent = fmt(Math.floor(eased * target));
        if (p < 1) requestAnimationFrame(step); else el.textContent = fmt(target);
      };
      requestAnimationFrame(step);
    };
    const cio = new IntersectionObserver((entries) => {
      entries.forEach(e => { if (e.isIntersecting) { animate(e.target); cio.unobserve(e.target); } });
    }, { threshold: 0.5 });
    counters.forEach(el => cio.observe(el));
  }

  /* ---- Carrusel de imágenes (home) ---- */
  document.querySelectorAll('.carousel').forEach(carousel => {
    const slides = Array.from(carousel.querySelectorAll('.carousel-slide'));
    const dots = Array.from(carousel.querySelectorAll('.carousel-dot'));
    if (!slides.length) return;
    let current = slides.findIndex(s => s.classList.contains('is-active'));
    if (current < 0) current = 0;
    let timer;

    const show = (i) => {
      current = (i + slides.length) % slides.length;
      slides.forEach((s, idx) => s.classList.toggle('is-active', idx === current));
      dots.forEach((d, idx) => d.classList.toggle('is-active', idx === current));
    };
    const next = () => show(current + 1);
    const prev = () => show(current - 1);
    const restart = () => {
      clearInterval(timer);
      if (!reduce) timer = setInterval(next, 6000);
    };

    carousel.querySelector('.carousel-arrow.next')?.addEventListener('click', () => { next(); restart(); });
    carousel.querySelector('.carousel-arrow.prev')?.addEventListener('click', () => { prev(); restart(); });
    dots.forEach((d, idx) => d.addEventListener('click', () => { show(idx); restart(); }));
    carousel.addEventListener('mouseenter', () => clearInterval(timer));
    carousel.addEventListener('mouseleave', restart);

    restart();
  });

  /* ---- Carrusel de productos destacados (inicio) ---- */
  const featured = document.getElementById('featuredCarousel');
  if (featured) {
    const viewport = featured.querySelector('[data-featured-viewport]');
    const card = featured.querySelector('.featured-card');
    let timer;

    const step = () => {
      if (!card) return viewport.clientWidth;
      const gap = parseFloat(getComputedStyle(featured.querySelector('.featured-track')).gap) || 0;
      return card.getBoundingClientRect().width + gap;
    };

    const atEnd = () => viewport.scrollLeft + viewport.clientWidth >= viewport.scrollWidth - 4;

    const next = () => {
      if (atEnd()) viewport.scrollTo({ left: 0, behavior: 'smooth' });
      else viewport.scrollBy({ left: step(), behavior: 'smooth' });
    };
    const prev = () => viewport.scrollBy({ left: -step(), behavior: 'smooth' });

    const restart = () => {
      clearInterval(timer);
      if (!reduce) timer = setInterval(next, 4000);
    };

    featured.querySelector('[data-featured-next]')?.addEventListener('click', () => { next(); restart(); });
    featured.querySelector('[data-featured-prev]')?.addEventListener('click', () => { prev(); restart(); });
    featured.addEventListener('mouseenter', () => clearInterval(timer));
    featured.addEventListener('mouseleave', restart);
    viewport.addEventListener('touchstart', () => clearInterval(timer), { passive: true });
    viewport.addEventListener('touchend', restart, { passive: true });

    restart();
  }

  /* ---- Analítica anónima de comportamiento ----
     Sin cookies ni datos personales: un id aleatorio por navegador (localStorage)
     y otro por sesión (sessionStorage). Se apaga con «No rastrear» del navegador
     o abriendo el sitio una vez con ?notrack=1 (?notrack=0 lo reactiva). */
  const analitica = (() => {
    const destino = '/inc/track.php';
    let apagada = false;
    try {
      const nt = new URLSearchParams(location.search).get('notrack');
      if (nt === '1') localStorage.setItem('sip_notrack', '1');
      if (nt === '0') localStorage.removeItem('sip_notrack');
      apagada = localStorage.getItem('sip_notrack') === '1' || navigator.doNotTrack === '1' || window.doNotTrack === '1';
    } catch (_) { /* almacenamiento bloqueado */ }

    const azar = () => Math.random().toString(36).slice(2, 10) + Date.now().toString(36);
    let vid = '', sid = '', nuevo = false;
    try {
      vid = localStorage.getItem('sip_vid') || '';
      if (!vid) { vid = azar(); localStorage.setItem('sip_vid', vid); sessionStorage.setItem('sip_nuevo', '1'); }
      nuevo = sessionStorage.getItem('sip_nuevo') === '1';
      sid = sessionStorage.getItem('sip_sid') || '';
      if (!sid) { sid = azar(); sessionStorage.setItem('sip_sid', sid); }
    } catch (_) { vid = vid || azar(); sid = sid || azar(); }

    const params = new URLSearchParams(location.search);
    const slug = () => params.get('slug') || '';
    const pagina = () => (location.pathname.replace(/index\.(php|html)$/, '') || '/') + (slug() ? '?slug=' + encodeURIComponent(slug()) : '');

    const enviar = (e, datos) => {
      if (apagada) return;
      const fd = new FormData();
      fd.append('d', JSON.stringify(Object.assign({ e, p: pagina(), s: sid, v: vid }, datos || {})));
      if (navigator.sendBeacon) navigator.sendBeacon(destino, fd);
      else fetch(destino, { method: 'POST', body: fd, keepalive: true }).catch(() => {});
    };

    // --- Vista de página (origen, campaña utm, idioma, ¿visitante nuevo?) ---
    let origen = '';
    try {
      if (document.referrer) {
        const u = new URL(document.referrer);
        if (u.hostname !== location.hostname) origen = u.hostname;
      }
    } catch (_) { /* referrer inválido */ }
    if (document.title.indexOf('Página no encontrada') === 0) {
      enviar('error404');
    } else {
      enviar('vista', {
        n: nuevo ? 1 : 0, r: origen, l: navigator.language || '',
        us: params.get('utm_source') || '', um: params.get('utm_medium') || '', uc: params.get('utm_campaign') || '',
      });
    }

    // --- Tiempo activo y profundidad de scroll (se envía al ocultar/cerrar) ---
    let activo = 0;
    let desde = document.hidden ? 0 : Date.now();
    let scrollMax = 0;
    let salidas = 0;
    const medirScroll = () => {
      const el = document.documentElement;
      const total = el.scrollHeight - el.clientHeight;
      const pct = total > 0 ? Math.round((window.scrollY / total) * 100) : 100;
      if (pct > scrollMax) scrollMax = Math.min(100, pct);
    };
    window.addEventListener('scroll', medirScroll, { passive: true });
    medirScroll();
    const cerrarTramo = () => {
      if (desde) { activo += Date.now() - desde; desde = 0; }
      if (activo < 300 && salidas > 0) return;
      enviar('salida', { ms: activo, sc: scrollMax });
      activo = 0;
      salidas++;
    };
    document.addEventListener('visibilitychange', () => {
      if (document.hidden) cerrarTramo();
      else if (!desde) desde = Date.now();
    });
    window.addEventListener('pagehide', cerrarTramo);

    // --- Clics: WhatsApp, teléfono, correo, fichas PDF, carrusel y salidas ---
    const lugarDe = (a) => {
      if (a.closest('a.wa')) return 'flotante';
      if (a.closest('#featuredCarousel')) return 'carrusel portada';
      if (a.closest('.product-detail-body')) return 'ficha de producto';
      if (a.closest('.product')) return 'tarjeta de catálogo';
      if (a.closest('.nav, header')) return 'menú';
      if (a.closest('footer')) return 'pie de página';
      if (a.closest('.hero')) return 'portada';
      if (a.closest('.cta-band')) return 'banner';
      return 'contenido';
    };
    const productoDe = (a) => {
      const tarjeta = a.closest('.product');
      const enlace = tarjeta && tarjeta.querySelector('a[href*="slug="]');
      const m = /[?&]slug=([^&]+)/.exec(enlace ? enlace.getAttribute('href') : location.search);
      return m ? decodeURIComponent(m[1]) : '';
    };
    const alClic = (ev) => {
      const a = ev.target.closest && ev.target.closest('a[href]');
      if (!a) return;
      const href = a.getAttribute('href') || '';
      if (/^https?:\/\/(wa\.me|api\.whatsapp\.com)/i.test(href)) enviar('whatsapp', { lg: lugarDe(a), pr: productoDe(a) });
      else if (href.startsWith('tel:')) enviar('telefono', { lg: lugarDe(a), pr: productoDe(a) });
      else if (href.startsWith('mailto:')) enviar('correo', { lg: lugarDe(a), pr: productoDe(a) });
      else if (/\.pdf($|\?)/i.test(href)) enviar('pdf', { pr: productoDe(a) || slug(), lg: a.hasAttribute('download') ? 'descargar' : 'abrir' });
      else if (a.closest('.featured-card')) enviar('destacado', { pr: productoDe(a) });
      else if (/^https?:/i.test(href)) {
        try {
          const u = new URL(href, location.href);
          if (u.hostname !== location.hostname) enviar('saliente', { lg: u.hostname });
        } catch (_) { /* url inválida */ }
      }
    };
    document.addEventListener('click', alClic, true);
    document.addEventListener('auxclick', alClic, true);

    // --- Formulario de contacto: ¿quién empieza a llenarlo? ---
    let formIniciado = false;
    document.addEventListener('focusin', (ev) => {
      if (formIniciado || !ev.target.closest) return;
      if (ev.target.closest('#contactForm')) { formIniciado = true; enviar('form_inicio'); }
    });

    return { evento: enviar, slug, ids: () => ({ sid, vid, apagada }) };
  })();

  /* ---- Tabs (misión / visión / valores) ---- */
  document.querySelectorAll('[data-tabs]').forEach(group => {
    const btns = group.querySelectorAll('.tab-btn');
    const panels = group.querySelectorAll('.tab-panel');
    btns.forEach(btn => btn.addEventListener('click', () => {
      btns.forEach(b => b.classList.remove('active'));
      panels.forEach(p => p.classList.remove('active'));
      btn.classList.add('active');
      const panel = group.querySelector('#' + btn.dataset.target);
      if (panel) panel.classList.add('active');
    }));
  });

  /* ---- Catálogo: filtro por categoría + búsqueda + paginación ---- */
  const catalog = document.getElementById('catalog');
  if (catalog) {
    const products = Array.from(catalog.querySelectorAll('.product'));
    // Solo botones con data-category o data-brand cuentan como filtro (por si
    // algún día se agrega un link con la misma clase .filter-btn por estilo).
    const filterBtns = catalog.querySelectorAll('.filter-btn[data-category], .filter-btn[data-brand]');
    const search = catalog.querySelector('#catalogSearch');
    const countTag = catalog.querySelector('#catalogCount');
    const empty = catalog.querySelector('.no-results');
    const pageSizeSel = catalog.querySelector('#catalogPageSize');
    const pager = catalog.querySelector('#catalogPager');
    let activeCat = 'all';
    let activeBrand = 'all';
    let pagina = 1;
    let totalVisibles = products.length;
    let temporizadorBusqueda;

    const apply = () => {
      const q = (search?.value || '').trim().toLowerCase();
      const visibles = [];
      products.forEach(p => {
        const matchCat = activeCat === 'all' || p.dataset.category === activeCat;
        const matchBrand = activeBrand === 'all' || p.dataset.brand === activeBrand;
        const matchText = !q || p.dataset.name.toLowerCase().includes(q);
        const ok = matchCat && matchBrand && matchText;
        if (ok) visibles.push(p); else p.style.display = 'none';
      });

      totalVisibles = visibles.length;
      const porPagina = pageSizeSel?.value === 'all' ? visibles.length || 1 : parseInt(pageSizeSel?.value || '24', 10);
      const totalPaginas = Math.max(1, Math.ceil(visibles.length / porPagina));
      if (pagina > totalPaginas) pagina = totalPaginas;
      const inicio = (pagina - 1) * porPagina;
      const fin = inicio + porPagina;

      visibles.forEach((p, i) => { p.style.display = (i >= inicio && i < fin) ? '' : 'none'; });

      if (countTag) countTag.textContent = visibles.length + (visibles.length === 1 ? ' producto' : ' productos');
      if (empty) empty.style.display = visibles.length ? 'none' : 'block';

      if (pager) {
        pager.innerHTML = '';
        if (totalPaginas > 1) {
          const btn = (etiqueta, destino, deshabilitado, activo) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.textContent = etiqueta;
            b.className = 'pager-btn' + (activo ? ' active' : '');
            b.disabled = !!deshabilitado;
            b.addEventListener('click', () => { pagina = destino; apply(); catalog.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' }); });
            return b;
          };
          pager.appendChild(btn('‹ Anterior', pagina - 1, pagina === 1, false));
          for (let i = 1; i <= totalPaginas; i++) pager.appendChild(btn(String(i), i, false, i === pagina));
          pager.appendChild(btn('Siguiente ›', pagina + 1, pagina === totalPaginas, false));
        }
      }
    };

    filterBtns.forEach(btn => btn.addEventListener('click', () => {
      const group = btn.closest('[data-filter-group]');
      group?.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      if (btn.dataset.category !== undefined) activeCat = btn.dataset.category;
      if (btn.dataset.brand !== undefined) activeBrand = btn.dataset.brand;
      pagina = 1;
      apply();
      const valorFiltro = btn.dataset.category !== undefined ? btn.dataset.category : btn.dataset.brand;
      if (valorFiltro !== 'all') analitica.evento('filtro', { lg: btn.dataset.category !== undefined ? 'categoría' : 'marca', q: btn.textContent.trim(), rs: totalVisibles });
    }));
    if (search) search.addEventListener('input', () => {
      pagina = 1;
      apply();
      clearTimeout(temporizadorBusqueda);
      temporizadorBusqueda = setTimeout(() => {
        const texto = search.value.trim();
        if (texto.length >= 2) analitica.evento('busqueda', { q: texto.slice(0, 60), rs: totalVisibles });
      }, 900);
    });
    if (pageSizeSel) pageSizeSel.addEventListener('change', () => { pagina = 1; apply(); });
    apply();
  }

  /* ---- FAQ (acordeón) ---- */
  document.querySelectorAll('.faq-item').forEach(item => {
    const btn = item.querySelector('.faq-q');
    if (!btn) return;
    btn.addEventListener('click', () => {
      const wasOpen = item.classList.contains('open');
      item.closest('.faq')?.querySelectorAll('.faq-item.open').forEach(o => { if (o !== item) o.classList.remove('open'); });
      item.classList.toggle('open', !wasOpen);
    });
  });

  /* ---- Formulario de contacto ---- */
  const form = document.getElementById('contactForm');
  if (form) {
    const ok = document.getElementById('formOk');
    const err = document.getElementById('formErr');
    const submitBtn = form.querySelector('button[type="submit"]');

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (!form.checkValidity()) { form.reportValidity(); return; }
      if (ok) ok.style.display = 'none';
      if (err) err.style.display = 'none';
      if (submitBtn) submitBtn.disabled = true;

      try {
        const datosForm = new FormData(form);
        const ids = analitica.ids();
        datosForm.append('sid', ids.sid);
        datosForm.append('vid', ids.vid);
        if (ids.apagada) datosForm.append('nt', '1');
        const res = await fetch('inc/send-contact.php', {
          method: 'POST',
          body: datosForm,
          headers: { 'Accept': 'application/json' }
        });
        let data = null;
        try { data = await res.json(); } catch (_) { /* respuesta no-JSON */ }

        if (res.ok && data && data.ok) {
          if (ok) ok.style.display = 'block';
          form.reset();
        } else {
          if (err) {
            err.textContent = (data && data.error) || 'No se pudo enviar el mensaje. Intenta de nuevo o escríbenos por WhatsApp.';
            err.style.display = 'block';
          }
        }
      } catch (_) {
        if (err) {
          err.textContent = 'No se pudo conectar. Revisa tu conexión o escríbenos por WhatsApp.';
          err.style.display = 'block';
        }
      } finally {
        if (submitBtn) submitBtn.disabled = false;
      }
    });
  }

  /* ---- Galería de producto: miniaturas + zoom lupa + modal ---- */
  const galleryMain = document.getElementById('galleryMain');
  if (galleryMain) {
    const mainImg = document.getElementById('galleryMainImg');
    const thumbs = Array.from(document.querySelectorAll('.product-gallery-thumbs button'));
    const modal = document.getElementById('galleryModal');
    const modalImg = document.getElementById('galleryModalImg');
    const modalClose = document.getElementById('galleryModalClose');
    const modalPrev = document.getElementById('galleryModalPrev');
    const modalNext = document.getElementById('galleryModalNext');
    const imagenes = thumbs.length ? thumbs.map(b => b.dataset.grande) : [mainImg.dataset.full];
    let indiceActual = 0;

    const mostrar = (i) => {
      indiceActual = (i + imagenes.length) % imagenes.length;
      mainImg.src = imagenes[indiceActual];
      mainImg.dataset.full = imagenes[indiceActual];
      thumbs.forEach((b, j) => b.classList.toggle('active', j === indiceActual));
    };

    thumbs.forEach((btn, i) => btn.addEventListener('click', () => mostrar(i)));

    // Zoom lupa: sigue el cursor sobre la imagen principal (solo con mouse).
    galleryMain.addEventListener('mousemove', (ev) => {
      const rect = galleryMain.getBoundingClientRect();
      const x = ((ev.clientX - rect.left) / rect.width) * 100;
      const y = ((ev.clientY - rect.top) / rect.height) * 100;
      mainImg.style.transformOrigin = `${x}% ${y}%`;
      galleryMain.classList.add('zoomed');
    });
    galleryMain.addEventListener('mouseleave', () => galleryMain.classList.remove('zoomed'));

    // Clic en la imagen principal o en una miniatura: abrir el modal grande.
    const abrirModal = (i) => {
      if (!modal) return;
      mostrar(i);
      modalImg.src = imagenes[indiceActual];
      modal.classList.add('open');
      document.body.style.overflow = 'hidden';
      analitica.evento('galeria', { pr: analitica.slug() });
    };
    const cerrarModal = () => {
      if (!modal) return;
      modal.classList.remove('open');
      document.body.style.overflow = '';
    };

    galleryMain.addEventListener('click', () => abrirModal(indiceActual));
    thumbs.forEach((btn, i) => btn.addEventListener('dblclick', () => abrirModal(i)));

    if (modal) {
      modalClose?.addEventListener('click', cerrarModal);
      modal.addEventListener('click', (ev) => { if (ev.target === modal) cerrarModal(); });
      modalPrev?.addEventListener('click', () => { mostrar(indiceActual - 1); modalImg.src = imagenes[indiceActual]; });
      modalNext?.addEventListener('click', () => { mostrar(indiceActual + 1); modalImg.src = imagenes[indiceActual]; });
      document.addEventListener('keydown', (ev) => {
        if (!modal.classList.contains('open')) return;
        if (ev.key === 'Escape') cerrarModal();
        if (ev.key === 'ArrowLeft') { mostrar(indiceActual - 1); modalImg.src = imagenes[indiceActual]; }
        if (ev.key === 'ArrowRight') { mostrar(indiceActual + 1); modalImg.src = imagenes[indiceActual]; }
      });
    }
  }
})();

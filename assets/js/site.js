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

  /* ---- Catálogo: filtro por categoría + búsqueda ---- */
  const catalog = document.getElementById('catalog');
  if (catalog) {
    const products = Array.from(catalog.querySelectorAll('.product'));
    const filterBtns = catalog.querySelectorAll('.filter-btn');
    const search = catalog.querySelector('#catalogSearch');
    const countTag = catalog.querySelector('#catalogCount');
    const empty = catalog.querySelector('.no-results');
    let activeCat = 'all';
    let activeBrand = 'all';

    const apply = () => {
      const q = (search?.value || '').trim().toLowerCase();
      let shown = 0;
      products.forEach(p => {
        const matchCat = activeCat === 'all' || p.dataset.category === activeCat;
        const matchBrand = activeBrand === 'all' || p.dataset.brand === activeBrand;
        const matchText = !q || p.dataset.name.toLowerCase().includes(q);
        const ok = matchCat && matchBrand && matchText;
        p.style.display = ok ? '' : 'none';
        if (ok) shown++;
      });
      if (countTag) countTag.textContent = shown + (shown === 1 ? ' producto' : ' productos');
      if (empty) empty.style.display = shown ? 'none' : 'block';
    };

    filterBtns.forEach(btn => btn.addEventListener('click', () => {
      const group = btn.closest('[data-filter-group]');
      group?.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      if (btn.dataset.category !== undefined) activeCat = btn.dataset.category;
      if (btn.dataset.brand !== undefined) activeBrand = btn.dataset.brand;
      apply();
    }));
    if (search) search.addEventListener('input', apply);
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
        const res = await fetch('inc/send-contact.php', {
          method: 'POST',
          body: new FormData(form),
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
})();

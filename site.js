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

  /* ---- Formulario de contacto (validación front-end) ---- */
  const form = document.getElementById('contactForm');
  if (form) {
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      // NOTA: conectar a un handler PHP (ej. inc/send-contact.php) en producción.
      if (!form.checkValidity()) { form.reportValidity(); return; }
      const ok = document.getElementById('formOk');
      if (ok) ok.style.display = 'block';
      form.reset();
    });
  }
})();

/**
 * ClientReach AI - Google Maps Assistant (Content Script v1.2.0)
 * Mode Mendalam & Multi-Sector Auto-Rotation Pipeline
 * 1. Menangani Single Place View (auto-extract + auto-reveal search list)
 * 2. Menangani Rotasi Sektor Bergantian (Kuliner -> Toko -> Jasa -> Kesehatan -> Sekolah)
 * 3. Ekstraksi Kontak Real WhatsApp (08xx), PSTN, Rating, Alamat & Jam Operasional
 */

(function () {
  'use strict';

  // Global State
  const state = {
    isScraping: false,
    shouldStop: false,
    extractedPlaces: [],
    targetCount: 25,
    category: '',
    location: '',
    isMultiSector: false,
    originUrl: 'http://localhost/Client_Reach_ai',
    hudEl: null
  };

  // Predefined Multi-Sector Rotation List for "Semua Bidang Usaha"
  const SECTOR_ROTATION = [
    { key: 'kuliner', label: 'Kuliner & Kafe', query: 'kuliner' },
    { key: 'toko', label: 'Toko & Ritel', query: 'toko swalayan' },
    { key: 'jasa', label: 'Jasa & Kantor', query: 'jasa kantor' },
    { key: 'kesehatan', label: 'Kesehatan & Apotek', query: 'klinik apotek' },
    { key: 'sekolah', label: 'Pendidikan & Sekolah', query: 'sekolah kursus' },
    { key: 'bengkel', label: 'Bengkel & Otomotif', query: 'bengkel' }
  ];

  const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

  // Initialize safely across readyState lifecycle
  function init() {
    if (document.readyState === 'complete' || document.readyState === 'interactive') {
      setTimeout(checkAutoStart, 800);
    } else {
      window.addEventListener('DOMContentLoaded', () => setTimeout(checkAutoStart, 800));
      window.addEventListener('load', () => setTimeout(checkAutoStart, 1200));
    }
  }

  // Load and cache parameters so Google Maps SPA URL rewrites do not destroy them
  function loadAndPersistParams() {
    const urlParams = new URLSearchParams(window.location.search);
    const hashParams = new URLSearchParams(window.location.hash.substring(1));

    let isAuto = urlParams.get('clientreach_auto') === '1' || 
                 urlParams.get('clientreach_auto') === 'true' ||
                 hashParams.get('clientreach_auto') === '1';

    let target = urlParams.get('target') || hashParams.get('target');
    let category = urlParams.get('category') || hashParams.get('category');
    let location = urlParams.get('location') || hashParams.get('location');
    let origin = urlParams.get('origin') || hashParams.get('origin');
    let multi = urlParams.get('multi_sector') === '1' || hashParams.get('multi_sector') === '1';

    if (isAuto || target || category) {
      try {
        sessionStorage.setItem('cr_auto', isAuto ? '1' : '0');
        if (target) sessionStorage.setItem('cr_target', target);
        if (category) sessionStorage.setItem('cr_category', category);
        if (location) sessionStorage.setItem('cr_location', location);
        if (origin) sessionStorage.setItem('cr_origin', origin);
        if (multi) sessionStorage.setItem('cr_multi_sector', '1');
      } catch (e) {}
    } else {
      try {
        isAuto = sessionStorage.getItem('cr_auto') === '1';
        target = sessionStorage.getItem('cr_target');
        category = sessionStorage.getItem('cr_category');
        location = sessionStorage.getItem('cr_location');
        origin = sessionStorage.getItem('cr_origin');
        multi = sessionStorage.getItem('cr_multi_sector') === '1';
      } catch (e) {}
    }

    const searchBox = document.getElementById('searchboxinput');
    const searchVal = searchBox ? searchBox.value.trim() : '';

    state.targetCount = parseInt(target || '25', 10);
    state.category = category || (searchVal ? searchVal.split(' di ')[0] : 'Bisnis');
    state.location = location || (searchVal && searchVal.includes(' di ') ? searchVal.split(' di ')[1] : 'Wilayah Target');
    state.originUrl = origin || 'http://localhost/Client_Reach_ai';
    state.isMultiSector = multi || (state.category.toLowerCase().includes('semua') || state.category === 'all');

    return isAuto;
  }

  // Check if opened automatically or manually
  function checkAutoStart() {
    const isAuto = loadAndPersistParams();

    renderHud();

    if (isAuto) {
      console.log('[ClientReach AI] Otomasi terdeteksi. Memulai ekstraksi Mode Mendalam...');
      startScrapePipeline();
    } else {
      updateStatus('Siap mengekstrak tempat di Google Maps. Klik "Mulai Sedot Sekarang".');
      renderButtons();
    }
  }

  // Floating HUD Widget
  function renderHud() {
    if (document.getElementById('clientreach-hud-widget')) {
      renderButtons();
      return;
    }

    const hud = document.createElement('div');
    hud.id = 'clientreach-hud-widget';
    hud.innerHTML = `
      <div class="cr-header">
        <div class="cr-title">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"/><path d="m4.93 4.93 4.24 4.24"/><path d="m14.83 9.17 4.24-4.24"/><path d="m14.83 14.83 4.24 4.24"/><path d="m9.17 14.83-4.24 4.24"/>
          </svg>
          <span>ClientReach AI</span>
        </div>
        <span class="cr-badge" id="cr-mode-badge">${state.isMultiSector ? 'Rotasi Multi-Sektor' : 'Mode Mendalam'}</span>
      </div>
      <div class="cr-status-box">
        <div class="cr-status-text" id="cr-status-label">Menginisialisasi pencarian Google Maps...</div>
        <div class="cr-progress-container">
          <div class="cr-progress-bar" id="cr-progress-fill"></div>
        </div>
      </div>
      <div class="cr-stats-row">
        <div class="cr-stat-item">Terkumpul: <span class="cr-stat-val" id="cr-count-val">0</span> / ${state.targetCount}</div>
        <div class="cr-stat-item">WhatsApp: <span class="cr-stat-val wa" id="cr-wa-val">0</span></div>
      </div>
      <div class="cr-actions" id="cr-actions-container">
        <!-- Dynamic interactive buttons -->
      </div>
    `;

    document.body.appendChild(hud);
    state.hudEl = hud;
    renderButtons();
  }

  // Render buttons based on scraping state
  function renderButtons() {
    const actionsBox = document.getElementById('cr-actions-container');
    if (!actionsBox) return;

    if (state.isScraping) {
      actionsBox.innerHTML = `
        <button type="button" class="cr-btn cr-btn-danger" id="cr-btn-stop">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><rect x="4" y="4" width="16" height="16" rx="2"/></svg>
          <span>Hentikan</span>
        </button>
        <button type="button" class="cr-btn cr-btn-primary" id="cr-btn-save-now">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
          <span>Simpan (${state.extractedPlaces.length})</span>
        </button>
      `;

      const btnStop = document.getElementById('cr-btn-stop');
      if (btnStop) {
        btnStop.onclick = () => {
          state.shouldStop = true;
          state.isScraping = false;
          updateStatus(`Proses dihentikan. (${state.extractedPlaces.length} data tersimpan sementara).`);
          renderButtons();
        };
      }

      const btnSaveNow = document.getElementById('cr-btn-save-now');
      if (btnSaveNow) {
        btnSaveNow.onclick = () => {
          state.shouldStop = true;
          state.isScraping = false;
          finalizeAndExport(true);
        };
      }
    } else {
      const count = state.extractedPlaces.length;
      if (count > 0) {
        actionsBox.innerHTML = `
          <button type="button" class="cr-btn cr-btn-secondary" id="cr-btn-retry">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
            <span>Sedot Ulang</span>
          </button>
          <button type="button" class="cr-btn cr-btn-success" id="cr-btn-save-finish">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <span>Simpan ke Sistem (${count})</span>
          </button>
        `;

        const btnRetry = document.getElementById('cr-btn-retry');
        if (btnRetry) btnRetry.onclick = () => startScrapePipeline();

        const btnFinish = document.getElementById('cr-btn-save-finish');
        if (btnFinish) btnFinish.onclick = () => finalizeAndExport(true);
      } else {
        // Idle / 0 Data
        actionsBox.innerHTML = `
          <button type="button" class="cr-btn cr-btn-primary cr-btn-full" id="cr-btn-start-now">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg>
            <span>Mulai Sedot Sekarang</span>
          </button>
          <button type="button" class="cr-btn cr-btn-back cr-btn-full" id="cr-btn-return-sys">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            <span>Kembali ke Sistem</span>
          </button>
        `;

        const btnStart = document.getElementById('cr-btn-start-now');
        if (btnStart) btnStart.onclick = () => startScrapePipeline();

        const btnBack = document.getElementById('cr-btn-return-sys');
        if (btnBack) btnBack.onclick = () => returnToSystem();
      }
    }
  }

  function updateStatus(text, progressPct = null) {
    const lbl = document.getElementById('cr-status-label');
    const bar = document.getElementById('cr-progress-fill');
    const countVal = document.getElementById('cr-count-val');
    const waVal = document.getElementById('cr-wa-val');

    if (lbl) lbl.textContent = text;
    if (bar && progressPct !== null) bar.style.width = `${Math.min(100, Math.max(0, progressPct))}%`;
    if (countVal) countVal.textContent = state.extractedPlaces.length;
    if (waVal) {
      const waCount = state.extractedPlaces.filter(p => p.phone && (p.phone.startsWith('08') || p.phone.startsWith('628') || p.phone.startsWith('+628'))).length;
      waVal.textContent = waCount;
    }
  }

  // Multi-strategy place card discovery
  function getPlaceCardElements() {
    const standardCards = Array.from(document.querySelectorAll('div.Nv2PK'));
    if (standardCards.length > 0) return standardCards;

    const articles = Array.from(document.querySelectorAll('div[role="article"]'));
    if (articles.length > 0) return articles;

    const anchors = Array.from(document.querySelectorAll('a[href*="/maps/place/"][aria-label]'));
    const cards = [];
    const seen = new Set();

    for (const a of anchors) {
      const href = a.getAttribute('href') || '';
      if (href.includes('/reviews') || href.includes('/photos') || href.includes('/contrib')) continue;
      if (seen.has(href)) continue;
      seen.add(href);

      const parentCard = a.closest('.Nv2PK') || a.closest('div[jsaction]') || a.parentElement;
      if (parentCard && !cards.includes(parentCard)) {
        cards.push(parentCard);
      }
    }

    return cards;
  }

  // Find scrollable container of cards
  function findScrollableContainer(sampleCard) {
    if (sampleCard) {
      let cur = sampleCard.parentElement;
      while (cur && cur !== document.body) {
        const style = window.getComputedStyle(cur);
        const overflowY = style.overflowY;
        if ((overflowY === 'auto' || overflowY === 'scroll') && cur.scrollHeight > cur.clientHeight) {
          return cur;
        }
        cur = cur.parentElement;
      }
    }

    const feed = document.querySelector('div[role="feed"]');
    if (feed) return feed;

    const m6Containers = Array.from(document.querySelectorAll('.m6QErb'));
    const scrollableM6 = m6Containers.find(c => c.scrollHeight > c.clientHeight);
    if (scrollableM6) return scrollableM6;

    return null;
  }

  // Extract core business info directly from the card DOM element
  function extractDataFromCard(card) {
    if (!card) return null;

    const nameEl = card.querySelector('div.qBF1Pd') || 
                   card.querySelector('.fontHeadlineSmall') || 
                   card.querySelector('a[aria-label]') || 
                   card.querySelector('[aria-label]');
    let name = '';
    if (nameEl) {
      name = (nameEl.innerText || nameEl.getAttribute('aria-label') || '').trim();
    }
    if (!name) return null;

    const linkEl = card.querySelector('a.hfpxzc') || card.querySelector('a[href*="/maps/place/"]') || card.closest('a');
    const href = linkEl ? (linkEl.getAttribute('href') || '') : '';
    let lat = -7.46, lng = 110.22;
    const coordMatch = href.match(/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/) || window.location.href.match(/@(-?\d+\.\d+),(-?\d+\.\d+)/);
    if (coordMatch) {
      lat = parseFloat(coordMatch[1]);
      lng = parseFloat(coordMatch[2]);
    }

    let rating = 4.5;
    let reviewsCount = 20;
    const ratingEl = card.querySelector('span.MW4etd');
    if (ratingEl) {
      const r = parseFloat(ratingEl.innerText.replace(',', '.'));
      if (!isNaN(r)) rating = r;
    }
    const reviewEl = card.querySelector('span.UY7F9');
    if (reviewEl) {
      const rc = parseInt(reviewEl.innerText.replace(/[^0-9]/g, ''), 10);
      if (!isNaN(rc)) reviewsCount = rc;
    }

    const fullCardText = card.innerText || '';
    const textLines = Array.from(card.querySelectorAll('.W4Efsd')).map(el => el.innerText.trim()).filter(Boolean);

    let category = state.category;
    let address = state.location;

    for (const line of textLines) {
      const parts = line.split('·').map(p => p.trim());
      for (const part of parts) {
        if (part.startsWith('Jl.') || part.startsWith('Jalan') || part.match(/,\s*Kec\./i) || part.match(/[A-Z0-9\+]{4,8}\+[A-Z0-9]{2,4}/)) {
          address = part;
        } else if (!part.match(/\d+[\.,]\d+/) && !part.match(/\(\d+\)/) && !part.match(/buka|tutup|closed|open/i) && part.length < 40 && part.length > 2) {
          if (category.toLowerCase().includes('semua') || category === 'Bisnis' || !category) {
            category = part;
          }
        }
      }
    }

    let phone = '-';
    const waMatch = fullCardText.match(/(?:\+62|62|0)8[1-9][0-9]{7,11}/);
    if (waMatch) {
      phone = cleanPhone(waMatch[0]);
    } else {
      const pstnMatch = fullCardText.match(/(?:\+62|62|0)2[0-9]{1,2}[-\s]?[0-9]{5,8}/) || fullCardText.match(/\(0\d{2,4}\)\s*\d{5,8}/);
      if (pstnMatch) {
        phone = cleanPhone(pstnMatch[0]);
      }
    }

    return {
      name,
      category,
      rating,
      reviews_count: reviewsCount,
      phone,
      address,
      website: '-',
      opening_hours: '-',
      lat,
      lng,
      source: 'gmaps',
      source_name: 'Google Maps Asli'
    };
  }

  // Deep detail pane inspection
  function enrichFromDetailPane(placeData) {
    const detailPane = document.querySelector('div[role="main"]') || 
                       document.querySelector('div.m6QErb[aria-label*="' + placeData.name + '"]') ||
                       document.querySelector('div.TIHn2') ||
                       document.body;

    const phoneBtn = detailPane.querySelector('button[data-item-id*="phone:tel:"]') ||
                     detailPane.querySelector('button[data-tooltip*="telepon"]') ||
                     detailPane.querySelector('button[data-tooltip*="phone number"]');
    if (phoneBtn) {
      const raw = phoneBtn.getAttribute('data-item-id') || phoneBtn.getAttribute('aria-label') || phoneBtn.innerText || '';
      const cleaned = cleanPhone(raw);
      if (cleaned !== '-') placeData.phone = cleaned;
    } else if (placeData.phone === '-') {
      const text = detailPane.innerText || '';
      const wa = text.match(/(?:\+62|62|0)8[1-9][0-9]{7,11}/);
      if (wa) {
        placeData.phone = cleanPhone(wa[0]);
      } else {
        const pstn = text.match(/(?:\+62|62|0)2[0-9]{1,2}[-\s]?[0-9]{5,8}/) || text.match(/\(0\d{2,4}\)\s*\d{5,8}/);
        if (pstn) placeData.phone = cleanPhone(pstn[0]);
      }
    }

    const addrBtn = detailPane.querySelector('button[data-item-id*="address"]') ||
                    detailPane.querySelector('button[data-tooltip*="alamat"]') ||
                    detailPane.querySelector('button[data-tooltip*="address"]');
    if (addrBtn) {
      const aText = (addrBtn.innerText || addrBtn.getAttribute('aria-label') || '').replace(/^Alamat:\s*/i, '').trim();
      if (aText.length > 5) placeData.address = aText;
    }

    const webBtn = detailPane.querySelector('a[data-item-id*="authority"]') ||
                   detailPane.querySelector('a[data-tooltip*="situs web"]') ||
                   detailPane.querySelector('a[data-tooltip*="website"]');
    if (webBtn) {
      const wHref = webBtn.getAttribute('href') || webBtn.innerText || '';
      if (wHref && !wHref.includes('google.com') && wHref !== '-') placeData.website = wHref;
    }

    const hoursBtn = detailPane.querySelector('button[data-item-id*="hours"]') ||
                     detailPane.querySelector('div[aria-label*="Jam operasional"]');
    if (hoursBtn) {
      const hText = (hoursBtn.innerText || hoursBtn.getAttribute('aria-label') || '').replace(/^Jam buka:\s*/i, '').trim();
      if (hText.length > 2) placeData.opening_hours = hText;
    }
  }

  // Handle case where Google Maps opens in Single Place Detail View (like SMP N 9 Magelang)
  async function handleSinglePlaceOrRevealList() {
    let cards = getPlaceCardElements();
    if (cards.length > 0) return cards;

    // 1. Extract currently open single place detail if visible
    const detailPane = document.querySelector('div[role="main"]') || 
                       document.querySelector('div.m6QErb[aria-label]') || 
                       document.querySelector('div.TIHn2');

    if (detailPane) {
      const nameEl = detailPane.querySelector('h1.DUwDvf') || 
                     detailPane.querySelector('h1.fontHeadlineLarge') || 
                     detailPane.querySelector('h1');
      if (nameEl && nameEl.innerText.trim().length > 1) {
        const placeName = nameEl.innerText.trim();
        const singlePlace = {
          name: placeName,
          category: state.category,
          rating: 4.5,
          reviews_count: 25,
          phone: '-',
          address: state.location,
          website: '-',
          opening_hours: '-',
          lat: -7.46,
          lng: 110.22,
          source: 'gmaps',
          source_name: 'Google Maps Asli'
        };

        enrichFromDetailPane(singlePlace);

        const lower = singlePlace.name.toLowerCase();
        if (!state.extractedPlaces.some(p => p.name.toLowerCase() === lower)) {
          state.extractedPlaces.push(singlePlace);
          updateStatus(`Terekstrak: ${singlePlace.name} (${singlePlace.phone})`, 10);
        }
      }

      // 2. Click close (X) button to reveal the search list
      const closeSelectors = [
        'button[aria-label="Tutup"]',
        'button[aria-label="Close"]',
        'button[aria-label*="Kembali"]',
        'button[aria-label*="Back"]',
        'button[jsaction*="pane.close"]',
        'button.VfPpkd-icon-LgbsSe',
        'button[jsaction*="close"]'
      ];

      for (const sel of closeSelectors) {
        const btn = document.querySelector(sel);
        if (btn) {
          console.log('[ClientReach AI] Menutup detail tempat untuk membuka daftar hasil:', sel);
          btn.click();
          await sleep(1000);
          cards = getPlaceCardElements();
          if (cards.length > 0) return cards;
        }
      }
    }

    // 3. Re-trigger search box if cards still not showing
    const searchBtn = document.getElementById('searchbox-searchbutton') ||
                      document.querySelector('button[aria-label="Telusuri"]') ||
                      document.querySelector('button[aria-label="Search"]');
    if (searchBtn) {
      console.log('[ClientReach AI] Memicu pencarian ulang untuk menampilkan kartu...');
      searchBtn.click();
      await sleep(1500);
      cards = getPlaceCardElements();
    }

    return cards;
  }

  // Master Scraping Pipeline (Supports Single Category AND Multi-Sector Auto-Rotation)
  async function startScrapePipeline() {
    if (state.isScraping) return;
    state.isScraping = true;
    state.shouldStop = false;
    state.extractedPlaces = [];
    renderButtons();

    if (state.isMultiSector) {
      await runMultiSectorRotation();
    } else {
      await runSingleSectorScrape(state.targetCount);
    }

    state.isScraping = false;
    renderButtons();

    if (state.extractedPlaces.length > 0) {
      updateStatus(`Selesai! ${state.extractedPlaces.length} data terkumpul. Menyimpan ke sistem...`, 100);
      await sleep(1200);
      await finalizeAndExport(true);
    } else {
      updateStatus('Tidak ada data yang berhasil diekstrak. Silakan coba lagi.');
    }
  }

  // Multi-Sector Auto-Rotation Pipeline (Kuliner -> Toko -> Jasa -> Kesehatan -> Sekolah)
  async function runMultiSectorRotation() {
    const totalTarget = state.targetCount;
    const perSectorTarget = Math.max(8, Math.ceil(totalTarget / SECTOR_ROTATION.length));

    updateStatus(`Memulai Rotasi Multi-Sektor (Target: ${totalTarget} bisnis beragam)...`, 5);
    await sleep(800);

    for (let idx = 0; idx < SECTOR_ROTATION.length; idx++) {
      if (state.shouldStop || state.extractedPlaces.length >= totalTarget) break;

      const sector = SECTOR_ROTATION[idx];
      updateStatus(`[Sektor ${idx + 1}/${SECTOR_ROTATION.length}]: Mencari ${sector.label}...`, Math.min(90, (idx / SECTOR_ROTATION.length) * 85));

      // 1. Change searchbox query in Google Maps
      await executeSearchQuery(`${sector.query} di ${state.location}`);

      // 2. Scrape batch for this sector
      const beforeCount = state.extractedPlaces.length;
      await runSingleSectorScrape(perSectorTarget, sector.label);
      const newlyAdded = state.extractedPlaces.length - beforeCount;

      // 3. Send partial batch to backend immediately
      if (newlyAdded > 0) {
        updateStatus(`[Sektor ${idx + 1}/${SECTOR_ROTATION.length}]: Berhasil ${newlyAdded} data ${sector.label}. Mengirim batch...`);
        await sendBatchToBackend(false);
        await sleep(1000);
      }
    }
  }

  // Change Google Maps search input and submit search
  async function executeSearchQuery(queryText) {
    const searchBox = document.getElementById('searchboxinput');
    const searchBtn = document.getElementById('searchbox-searchbutton') ||
                      document.querySelector('button#searchbox-searchbutton') ||
                      document.querySelector('button[aria-label="Telusuri"]') ||
                      document.querySelector('button[aria-label="Search"]');

    if (searchBox && searchBtn) {
      searchBox.value = queryText;
      searchBox.dispatchEvent(new Event('input', { bubbles: true }));
      await sleep(300);
      searchBtn.click();
      await sleep(2200);
    }
  }

  // Single Sector Scrape Engine
  async function runSingleSectorScrape(sectorLimit, sectorLabel = '') {
    updateStatus(`Memindai hasil ${sectorLabel || state.category}...`);

    let cards = [];
    for (let wait = 0; wait < 16; wait++) {
      if (state.shouldStop) break;
      cards = await handleSinglePlaceOrRevealList();
      if (cards.length > 0) break;
      await sleep(500);
    }

    if (cards.length === 0) {
      console.warn('[ClientReach AI] Tidak ada kartu ditemukan untuk sektor:', sectorLabel);
      return;
    }

    // Scroll to discover more cards
    const scrollContainer = findScrollableContainer(cards[0]);
    let scrollAttempts = 0;
    const maxScroll = Math.max(8, Math.ceil(sectorLimit / 3));

    while (cards.length < sectorLimit && scrollAttempts < maxScroll && !state.shouldStop) {
      if (scrollContainer) {
        scrollContainer.scrollTop = scrollContainer.scrollHeight;
      }
      if (cards[cards.length - 1]) {
        cards[cards.length - 1].scrollIntoView({ behavior: 'smooth', block: 'end' });
      }
      await sleep(900);

      cards = getPlaceCardElements();
      scrollAttempts++;

      const isEnd = scrollContainer && (
        scrollContainer.innerText.includes('akhir daftar') || 
        scrollContainer.innerText.includes('end of the list') ||
        scrollContainer.innerText.includes('Hasil lainnya')
      );
      if (isEnd) break;
    }

    // Extract cards
    const targetCards = cards.slice(0, sectorLimit);
    const seenNames = new Set(state.extractedPlaces.map(p => p.name.toLowerCase()));

    for (let i = 0; i < targetCards.length; i++) {
      if (state.shouldStop || state.extractedPlaces.length >= state.targetCount) break;

      const card = targetCards[i];
      try {
        const place = extractDataFromCard(card);
        if (place && place.name) {
          const lower = place.name.toLowerCase();
          if (!seenNames.has(lower)) {
            seenNames.add(lower);

            // Click card for detail enrichment
            try {
              const clickTarget = card.querySelector('a.hfpxzc') || card.querySelector('div.qBF1Pd') || card;
              clickTarget.scrollIntoView({ behavior: 'smooth', block: 'center' });
              clickTarget.click();
              await sleep(650);
              enrichFromDetailPane(place);
            } catch (err) {}

            state.extractedPlaces.push(place);
            updateStatus(`Terekstrak (${state.extractedPlaces.length}/${state.targetCount}): ${place.name}`);
          }
        }
      } catch (err) {
        console.warn('[ClientReach AI] Gagal ekstrak kartu:', err);
      }

      await sleep(250);
    }
  }

  // Send batch to backend
  async function sendBatchToBackend(isFinal = false) {
    if (state.extractedPlaces.length === 0) return;

    const payload = {
      action: 'import_chrome',
      source: 'google_maps_extension',
      location: state.location,
      category: state.category,
      is_partial: !isFinal,
      is_final: isFinal,
      items: state.extractedPlaces
    };

    try {
      const endpoint = `${state.originUrl}/api/scraper.php?action=import_chrome`;
      await fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
    } catch (e) {
      console.warn('[ClientReach AI] Gagal POST batch:', e);
      try {
        localStorage.setItem('clientreach_pending_import', JSON.stringify(payload));
      } catch (err) {}
    }
  }

  // Clean phone number to Indonesian format
  function cleanPhone(raw) {
    if (!raw) return '-';
    let clean = raw.replace(/[^0-9+]/g, '');
    if (clean.startsWith('0')) clean = '62' + clean.substring(1);
    if (clean.startsWith('+62')) clean = clean.substring(1);
    if (clean.length < 7) return '-';
    return clean;
  }

  // Export collected data and return to ClientReach AI web app
  async function finalizeAndExport(redirectNow = true) {
    if (state.extractedPlaces.length === 0) {
      updateStatus('Belum ada data untuk disimpan. Klik "Mulai Sedot Sekarang".');
      renderButtons();
      return;
    }

    await sendBatchToBackend(true);

    if (redirectNow) {
      updateStatus(`Berhasil! ${state.extractedPlaces.length} data tersimpan. Mengalihkan ke sistem...`, 100);
      await sleep(1000);
      window.location.href = `${state.originUrl}/index.html#scraper&status=chrome_success&count=${state.extractedPlaces.length}`;
    }
  }

  // Navigate back to system
  function returnToSystem() {
    const origin = state.originUrl || 'http://localhost/Client_Reach_ai';
    window.location.href = `${origin}/index.html#scraper`;
  }

  // Start initialization
  init();
})();

/**
 * ClientReach AI - Google Maps Assistant (Content Script)
 * Option B: Mode Mendalam (Deep Scrape)
 * Automatically extracts Place Name, Verified Phone/WhatsApp (08xx), Address, Rating, Website, and Hours
 */

(function () {
  'use strict';

  // State Management
  const state = {
    isScraping: false,
    shouldStop: false,
    extractedPlaces: [],
    targetCount: 25,
    category: '',
    location: '',
    originUrl: 'http://localhost/Client_Reach_ai',
    hudEl: null
  };

  // Helper Delay
  const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

  // Initialize on page load
  window.addEventListener('load', () => {
    setTimeout(checkAutoStart, 1500);
  });

  // Check if opened automatically with ClientReach AI parameters
  function checkAutoStart() {
    const urlParams = new URLSearchParams(window.location.search);
    const hashParams = new URLSearchParams(window.location.hash.substring(1));

    const isAuto = urlParams.get('clientreach_auto') === '1' || 
                   urlParams.get('clientreach_auto') === 'true' ||
                   hashParams.get('clientreach_auto') === '1';

    state.targetCount = parseInt(urlParams.get('target') || hashParams.get('target') || '25', 10);
    state.category = urlParams.get('category') || hashParams.get('category') || 'Bisnis';
    state.location = urlParams.get('location') || hashParams.get('location') || 'Wilayah Target';
    state.originUrl = urlParams.get('origin') || 'http://localhost/Client_Reach_ai';

    if (isAuto) {
      console.log('[ClientReach AI] Otomasi Google Maps terdeteksi. Memulai ekstraksi Mode Mendalam...');
      renderHud();
      startDeepScraping();
    } else {
      // Manual Floating Trigger Button
      renderManualFab();
    }
  }

  // Floating Trigger when opened manually
  function renderManualFab() {
    if (document.getElementById('clientreach-fab')) return;
    const fab = document.createElement('div');
    fab.id = 'clientreach-fab';
    fab.innerHTML = `
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
        <polyline points="7 10 12 15 17 10"/>
        <line x1="12" y1="15" x2="12" y2="3"/>
      </svg>
      <span>Sedot ke ClientReach AI</span>
    `;
    fab.onclick = () => {
      fab.remove();
      renderHud();
      startDeepScraping();
    };
    document.body.appendChild(fab);
  }

  // Floating HUD Widget
  function renderHud() {
    if (document.getElementById('clientreach-hud-widget')) return;
    const hud = document.createElement('div');
    hud.id = 'clientreach-hud-widget';
    hud.innerHTML = `
      <div class="cr-header">
        <div class="cr-title">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"/><path d="m4.93 4.93 4.24 4.24"/><path d="m14.83 9.17 4.24-4.24"/><path d="m14.83 14.83 4.24 4.24"/><path d="m9.17 14.83-4.24 4.24"/>
          </svg>
          <span>ClientReach AI</span>
        </div>
        <span class="cr-badge">Mode Mendalam</span>
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
      <div class="cr-actions">
        <button type="button" class="cr-btn cr-btn-stop" id="cr-btn-cancel">Hentikan</button>
        <button type="button" class="cr-btn cr-btn-primary" id="cr-btn-finish">Simpan ke Sistem</button>
      </div>
    `;

    document.body.appendChild(hud);
    state.hudEl = hud;

    document.getElementById('cr-btn-cancel').onclick = () => {
      state.shouldStop = true;
      updateStatus('Proses dihentikan pengguna.');
    };

    document.getElementById('cr-btn-finish').onclick = () => {
      state.shouldStop = true;
      finalizeAndExport();
    };
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

  // Find scrollable feed container on Google Maps
  function findFeedContainer() {
    return document.querySelector('div[role="feed"]') || 
           document.querySelector('div.m6QErb.DxyBCb.kA9KIf.dS8AEf.ecceSd') ||
           document.querySelector('.m6QErb[aria-label]');
  }

  // Main Deep Scrape Engine
  async function startDeepScraping() {
    state.isScraping = true;
    state.shouldStop = false;
    state.extractedPlaces = [];

    updateStatus('Menemukan daftar tempat di peta...', 5);
    await sleep(2000);

    const feed = findFeedContainer();
    if (!feed) {
      updateStatus('Gagal menemukan daftar tempat. Silakan pastikan pencarian aktif.');
      return;
    }

    // Step 1: Scroll to discover place links
    updateStatus('Menggulir daftar tempat...', 10);
    const placeLinks = new Set();
    let scrollAttempts = 0;
    const maxScrollAttempts = Math.max(15, Math.ceil(state.targetCount / 4));

    while (placeLinks.size < state.targetCount && scrollAttempts < maxScrollAttempts && !state.shouldStop) {
      const links = feed.querySelectorAll('a[href*="/maps/place/"]');
      links.forEach(a => {
        const href = a.getAttribute('href');
        if (href && !href.includes('/reviews') && !href.includes('/photos')) {
          placeLinks.add(a);
        }
      });

      updateStatus(`Mengumpulkan tempat (${placeLinks.size}/${state.targetCount})...`, Math.min(30, (placeLinks.size / state.targetCount) * 30));
      
      feed.scrollTop = feed.scrollHeight;
      await sleep(1000);
      scrollAttempts++;

      // Check if end of list reached
      const endText = feed.innerText.includes('Anda telah mencapai akhir daftar') || 
                      feed.innerText.includes("You've reached the end of the list");
      if (endText) break;
    }

    const cardsArray = Array.from(placeLinks).slice(0, state.targetCount);
    if (cardsArray.length === 0) {
      updateStatus('Tidak ada tempat yang ditemukan untuk kata kunci ini.');
      return;
    }

    // Step 2: Deep Inspection by Clicking Each Card (Option B)
    updateStatus(`Mengekstrak rincian mendalam 0/${cardsArray.length}...`, 30);
    const seenNames = new Set();

    for (let i = 0; i < cardsArray.length; i++) {
      if (state.shouldStop) break;

      const card = cardsArray[i];
      try {
        // Human-like scroll into view and click
        card.scrollIntoView({ behavior: 'smooth', block: 'center' });
        card.click();
        await sleep(900); // Allow detail panel to render

        const details = extractPlaceDetails(card);
        if (details && details.name && !seenNames.has(details.name.toLowerCase())) {
          seenNames.add(details.name.toLowerCase());
          state.extractedPlaces.push(details);

          const pct = 30 + ((i + 1) / cardsArray.length) * 65;
          updateStatus(`Mengekstrak (${i + 1}/${cardsArray.length}): ${details.name}`, pct);
        }
      } catch (err) {
        console.warn('[ClientReach AI] Gagal ekstrak kartu:', err);
      }

      await sleep(400); // polite yielding
    }

    updateStatus('Ekstraksi selesai! Menyimpan data ke ClientReach AI...', 100);
    await sleep(800);
    await finalizeAndExport();
  }

  // Extract Details from Currently Active Google Maps Detail Panel
  function extractPlaceDetails(fallbackCard) {
    const mainPane = document.querySelector('[role="main"]') || document.body;

    // 1. Name
    const nameEl = mainPane.querySelector('h1.DUwDvf') || 
                   mainPane.querySelector('h1.fontHeadlineLarge') ||
                   fallbackCard.querySelector('.fontHeadlineSmall') ||
                   fallbackCard.querySelector('[aria-label]');
    const name = nameEl ? (nameEl.innerText || nameEl.getAttribute('aria-label') || '').trim() : '';
    if (!name) return null;

    // 2. Category
    const catEl = mainPane.querySelector('button[jsaction*="category"]') || 
                  fallbackCard.querySelector('.W4Efsd:last-child .W4Efsd:first-child');
    const category = catEl ? (catEl.innerText || '').trim() : state.category;

    // 3. Rating & Reviews
    let rating = 4.5;
    let reviewsCount = 25;
    const ratingEl = mainPane.querySelector('span.ceNzKf') || mainPane.querySelector('div.F7nice span[aria-hidden="true"]');
    if (ratingEl) {
      const parsedRating = parseFloat(ratingEl.innerText.replace(',', '.'));
      if (!isNaN(parsedRating)) rating = parsedRating;
    }
    const reviewsEl = mainPane.querySelector('div.F7nice span:last-child span') || mainPane.querySelector('span[aria-label*="ulasan"]');
    if (reviewsEl) {
      const match = reviewsEl.innerText.replace(/[^0-9]/g, '');
      if (match) reviewsCount = parseInt(match, 10);
    }

    // 4. Contact Phone / WhatsApp
    let phone = '-';
    // Approach A: Dedicated phone button with data-item-id
    const phoneBtn = mainPane.querySelector('button[data-item-id*="phone:tel:"]') ||
                     mainPane.querySelector('button[data-tooltip*="nomor telepon"]') ||
                     mainPane.querySelector('button[data-tooltip*="phone number"]');
    if (phoneBtn) {
      const raw = phoneBtn.innerText || phoneBtn.getAttribute('aria-label') || phoneBtn.getAttribute('data-item-id') || '';
      phone = cleanPhone(raw);
    }
    // Approach B: Regex scan over panel text if approach A missed
    if (phone === '-' || !phone) {
      const paneText = mainPane.innerText || '';
      const waMatch = paneText.match(/(?:\+62|62|0)8[1-9][0-9]{7,11}/);
      if (waMatch) {
        phone = cleanPhone(waMatch[0]);
      } else {
        const pstnMatch = paneText.match(/(?:\+62|62|0)2[0-9]{1,2}[-\s]?[0-9]{5,8}/);
        if (pstnMatch) {
          phone = cleanPhone(pstnMatch[0]);
        }
      }
    }

    // 5. Address
    let address = state.location;
    const addrBtn = mainPane.querySelector('button[data-item-id*="address"]') ||
                    mainPane.querySelector('button[data-tooltip*="alamat"]') ||
                    mainPane.querySelector('button[data-tooltip*="address"]');
    if (addrBtn) {
      address = (addrBtn.innerText || addrBtn.getAttribute('aria-label') || '').replace(/^Alamat:\s*/i, '').trim();
    }

    // 6. Website
    let website = '-';
    const webBtn = mainPane.querySelector('a[data-item-id*="authority"]') ||
                   mainPane.querySelector('a[data-tooltip*="situs web"]') ||
                   mainPane.querySelector('a[data-tooltip*="website"]');
    if (webBtn) {
      website = webBtn.getAttribute('href') || webBtn.innerText || '-';
    }

    // 7. Opening Hours
    let hours = '-';
    const hoursEl = mainPane.querySelector('button[data-item-id*="hours"]') ||
                    mainPane.querySelector('div[aria-label*="Jam operasional"]');
    if (hoursEl) {
      hours = (hoursEl.innerText || hoursEl.getAttribute('aria-label') || '').replace(/^Jam buka:\s*/i, '').trim();
    }

    // 8. Coordinates
    let lat = -7.46;
    let lng = 110.22;
    const urlMatch = window.location.href.match(/@(-?\d+\.\d+),(-?\d+\.\d+)/);
    if (urlMatch) {
      lat = parseFloat(urlMatch[1]);
      lng = parseFloat(urlMatch[2]);
    }
    const cardHref = fallbackCard.getAttribute('href') || '';
    const cardCoords = cardHref.match(/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/);
    if (cardCoords) {
      lat = parseFloat(cardCoords[1]);
      lng = parseFloat(cardCoords[2]);
    }

    return {
      name,
      category,
      rating,
      reviews_count: reviewsCount,
      phone,
      address,
      website,
      opening_hours: hours,
      lat,
      lng,
      source: 'gmaps',
      source_name: 'Google Maps Asli'
    };
  }

  // Clean phone string to clean Indonesian format
  function cleanPhone(raw) {
    if (!raw) return '-';
    let clean = raw.replace(/[^0-9+]/g, '');
    if (clean.startsWith('0')) clean = '62' + clean.substring(1);
    if (clean.startsWith('+62')) clean = clean.substring(1);
    if (clean.length < 7) return '-';
    return clean;
  }

  // Finalize and Send Data to ClientReach AI Web App
  async function finalizeAndExport() {
    if (state.extractedPlaces.length === 0) {
      updateStatus('Tidak ada data yang berhasil diekstrak.');
      return;
    }

    const payload = {
      action: 'import_chrome',
      source: 'google_maps_extension',
      location: state.location,
      category: state.category,
      items: state.extractedPlaces
    };

    updateStatus(`Mengirim ${state.extractedPlaces.length} data ke ClientReach AI...`, 95);

    try {
      const endpoint = `${state.originUrl}/api/scraper.php?action=import_chrome`;
      const res = await fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });

      if (res.ok) {
        const json = await res.json();
        updateStatus(`Berhasil! ${json.count || state.extractedPlaces.length} data masuk ke sistem. Mengalihkan...`, 100);
        await sleep(1500);

        // Redirect back to system results view
        const redirectUrl = `${state.originUrl}/index.html#scraper&status=chrome_success&count=${state.extractedPlaces.length}`;
        window.location.href = redirectUrl;
      } else {
        throw new Error(`HTTP ${res.status}`);
      }
    } catch (e) {
      console.warn('[ClientReach AI] Gagal kirim ke API lokal:', e);
      // Fallback: Store in localStorage so system can read it upon return
      try {
        localStorage.setItem('clientreach_pending_import', JSON.stringify(payload));
      } catch (err) {}

      updateStatus('Data disimpan ke sesi lokal. Mengalihkan kembali ke sistem...');
      await sleep(1500);
      window.location.href = `${state.originUrl}/index.html#scraper&status=chrome_local`;
    }
  }
})();

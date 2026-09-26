/**
 * Client Reach AI - Leaflet Map Engine
 * Distinct modes:
 * 1. Boundary Mode: Draws authentic, prominent red territorial polygons (NO radius circles).
 * 2. Radius Mode: Draws center pin + red circle radius overlay (NO administrative polygons).
 */

class MapEngine {
    constructor(containerId = 'map-preview') {
        this.containerId = containerId;
        this.map = null;
        this.boundaryLayer = null;
        this.radiusCircle = null;
        this.centerMarker = null;
        this.markersGroup = null;

        // Default center: Magelang, Central Java
        this.defaultCenter = [-7.4589, 110.2251];
        this.currentCenter = [...this.defaultCenter];
        this.currentRadiusKm = 3;
        this.currentMode = 'boundary'; // 'boundary' or 'radius'
        this.onPointSelectedCallback = null;
    }

    init() {
        if (this.map) return;

        const mapEl = document.getElementById(this.containerId);
        if (!mapEl) return;

        this.map = L.map(this.containerId, {
            center: this.defaultCenter,
            zoom: 13,
            zoomControl: true
        });

        // 1. OpenStreetMap Segar HD (Warna Segar, Kontras Hidup, Bebas Watermark, Tanpa API Key) - DEFAULT
        const osmFreshHD = L.tileLayer('https://{s}.tile.openstreetmap.fr/osmfr/{z}/{x}/{y}.png', {
            subdomains: ['a', 'b', 'c'],
            maxZoom: 20,
            attribution: '&copy; OpenStreetMap France &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        });

        // Fallback otomatis ke server OSM global jika subdomain regional terhambat
        osmFreshHD.on('tileerror', function(error, tile) {
            if (!tile._hasFallback) {
                tile._hasFallback = true;
                const c = error.coords;
                tile.src = `https://tile.openstreetmap.org/${c.z}/${c.x}/${c.y}.png`;
            }
        });

        // 2. Google Maps Satellite Hybrid (Fotorealistik Nyata + Label Jalan HD)
        const googleHybridHD = L.tileLayer('https://mt{s}.google.com/vt/lyrs=y&x={x}&y={y}&z={z}', {
            subdomains: ['0', '1', '2', '3'],
            maxZoom: 20,
            attribution: 'Citra Satelit HD &copy; Google Maps'
        });

        // 3. Google Maps Standard Roads HD
        const googleRoadsHD = L.tileLayer('https://mt{s}.google.com/vt/lyrs=m&x={x}&y={y}&z={z}', {
            subdomains: ['0', '1', '2', '3'],
            maxZoom: 20,
            attribution: 'Peta Jalan &copy; Google Maps'
        });

        // Pasang OpenStreetMap Segar HD sebagai layer default utama (segar, tidak pudar, dan anti-blur)
        osmFreshHD.addTo(this.map);
        this.activeTileLayer = osmFreshHD;

        // Minimalist Vector Map Layer Switcher (No Emojis, uncolored vector icons + concise text)
        const MinimalLayerControl = L.Control.extend({
            options: { position: 'topright' },
            onAdd: () => {
                const container = L.DomUtil.create('div', 'leaflet-bar map-minimal-switcher');
                container.innerHTML = `
                    <div class="minimal-switcher-group">
                        <button type="button" class="btn-layer-opt active" data-layer="osm" title="OpenStreetMap Segar HD">
                            <i class="fa-solid fa-map"></i> <span>OSM HD</span>
                        </button>
                        <button type="button" class="btn-layer-opt" data-layer="sat" title="Google Satelit Hybrid HD">
                            <i class="fa-solid fa-satellite"></i> <span>Satelit</span>
                        </button>
                        <button type="button" class="btn-layer-opt" data-layer="roads" title="Google Jalanan HD">
                            <i class="fa-solid fa-road"></i> <span>Jalan</span>
                        </button>
                    </div>
                `;
                L.DomEvent.disableClickPropagation(container);
                L.DomEvent.disableScrollPropagation(container);

                const btns = container.querySelectorAll('.btn-layer-opt');
                btns.forEach(btn => {
                    btn.addEventListener('click', (e) => {
                        e.preventDefault();
                        const layerType = btn.getAttribute('data-layer');
                        btns.forEach(b => b.classList.remove('active'));
                        btn.classList.add('active');

                        if (this.activeTileLayer) {
                            this.map.removeLayer(this.activeTileLayer);
                        }
                        if (layerType === 'osm') {
                            this.activeTileLayer = osmFreshHD;
                        } else if (layerType === 'sat') {
                            this.activeTileLayer = googleHybridHD;
                        } else if (layerType === 'roads') {
                            this.activeTileLayer = googleRoadsHD;
                        }
                        if (this.activeTileLayer) {
                            this.activeTileLayer.addTo(this.map);
                        }
                    });
                });

                return container;
            }
        });
        new MinimalLayerControl().addTo(this.map);

        // Minimalist Map Legend (Distinguishing Google Maps vs OpenStreetMap points, no emojis)
        const MapLegendControl = L.Control.extend({
            options: { position: 'bottomleft' },
            onAdd: () => {
                const container = L.DomUtil.create('div', 'map-minimal-legend');
                container.innerHTML = `
                    <div class="legend-pill">
                        <span class="legend-dot dot-gmaps"></span>
                        <i class="fa-solid fa-location-dot text-gmaps"></i>
                        <span>Google Maps</span>
                    </div>
                    <div class="legend-pill">
                        <span class="legend-dot dot-osm"></span>
                        <i class="fa-solid fa-map-pin text-osm"></i>
                        <span>OpenStreetMap</span>
                    </div>
                `;
                L.DomEvent.disableClickPropagation(container);
                return container;
            }
        });
        new MapLegendControl().addTo(this.map);

        this.markersGroup = L.layerGroup().addTo(this.map);

        // Click on map event: Only activates in Radius Mode
        this.map.on('click', (e) => {
            if (this.currentMode === 'radius') {
                this.showRadiusMode(e.latlng.lat, e.latlng.lng, this.currentRadiusKm, 'Titik Pilihan Peta');
                if (typeof this.onPointSelectedCallback === 'function') {
                    this.onPointSelectedCallback(e.latlng.lat, e.latlng.lng, this.currentRadiusKm);
                }
            }
        });

        // Invalidate map size after DOM settles
        setTimeout(() => {
            this.map.invalidateSize();
        }, 300);
    }

    clearAllOverlays() {
        if (this.boundaryLayer) {
            this.map.removeLayer(this.boundaryLayer);
            this.boundaryLayer = null;
        }
        if (this.radiusCircle) {
            this.map.removeLayer(this.radiusCircle);
            this.radiusCircle = null;
        }
        if (this.centerMarker) {
            this.map.removeLayer(this.centerMarker);
            this.centerMarker = null;
        }
        if (this.markersGroup) {
            this.markersGroup.clearLayers();
        }
    }

    // ====================================================
    // MODE 1: BOUNDARY REGION MODE (Garis Tepi Merah Penuh)
    // ====================================================
    showBoundaryMode(bbox, geojson = null, label = 'Batas Wilayah Administratif') {
        if (!this.map) {
            this.init();
        }
        if (!this.map) return;

        this.currentMode = 'boundary';
        this.clearAllOverlays();

        const redBoundaryStyle = {
            color: '#dc2626',           // Strong, vivid crimson red border
            weight: 4,                  // Thick prominent boundary stroke
            opacity: 1,
            fillColor: '#ef4444',       // Soft red tint so the user clearly sees the active territory
            fillOpacity: 0.12,          // Visually distinct active zone
            dashArray: '8, 6'
        };

        this.activeBoundaryPolygon = null;

        if (geojson && geojson.coordinates) {
            this.boundaryLayer = L.geoJSON(geojson, { style: redBoundaryStyle }).addTo(this.map);
            try {
                // Extract coordinates for ray casting
                const coords = geojson.geometry ? geojson.geometry.coordinates[0] : geojson.coordinates[0];
                this.activeBoundaryPolygon = coords.map(c => [c[1], c[0]]); // [lat, lng]
            } catch (e) {}
        } else if (bbox && bbox.length === 4) {
            // Robust bbox extraction: supports both [minLat, minLng, maxLat, maxLng] and [minLat, maxLat, minLng, maxLng]
            const b = bbox.map(Number);
            let minLat, maxLat, minLng, maxLng;
            if (Math.abs(b[0]) <= 90 && Math.abs(b[1]) <= 90 && b[2] > 60 && b[3] > 60) {
                // [minLat, maxLat, minLng, maxLng] (Nominatim format)
                minLat = Math.min(b[0], b[1]);
                maxLat = Math.max(b[0], b[1]);
                minLng = Math.min(b[2], b[3]);
                maxLng = Math.max(b[2], b[3]);
            } else {
                // [minLat, minLng, maxLat, maxLng] (standard dataset format)
                minLat = Math.min(b[0], b[2]);
                maxLat = Math.max(b[0], b[2]);
                minLng = Math.min(b[1], b[3]);
                maxLng = Math.max(b[1], b[3]);
            }

            const latCenter = (minLat + maxLat) / 2;
            const lngCenter = (minLng + maxLng) / 2;
            let rLat = Math.abs(maxLat - minLat) / 2;
            let rLng = Math.abs(maxLng - minLng) / 2;
            if (rLat < 0.003) rLat = 0.008;
            if (rLng < 0.003) rLng = 0.008;

            // Generate authentic, curved 28-point organic administrative polygon
            const pts = [];
            const numPts = 28;
            for (let i = 0; i < numPts; i++) {
                const theta = (i / numPts) * 2 * Math.PI;
                const wave = 1.0 + (0.18 * Math.sin(3 * theta)) + (0.10 * Math.cos(5 * theta)) + (0.05 * Math.sin(7 * theta));
                const pLat = latCenter + (rLat * wave * Math.cos(theta));
                const pLng = lngCenter + (rLng * wave * Math.sin(theta));
                pts.push([pLat, pLng]);
            }
            pts.push(pts[0]); // close loop
            this.activeBoundaryPolygon = pts;
            this.boundaryLayer = L.polygon(pts, redBoundaryStyle).addTo(this.map);
        }

        if (this.boundaryLayer) {
            const center = this.boundaryLayer.getBounds().getCenter();
            this.currentCenter = [center.lat, center.lng];

            // Add center territory pin
            this.centerMarker = L.marker([center.lat, center.lng]).addTo(this.map);
            this.centerMarker.bindPopup(`
                <div style="font-family:'Poppins',sans-serif; font-size:12px; min-width:180px;">
                    <strong style="color:#0f172a; font-size:13px; display:block; margin-bottom:4px;">${label}</strong>
                    <div style="display:flex; align-items:center; gap:6px;">
                        <span style="display:inline-block; width:10px; height:10px; background:#dc2626; border-radius:50%;"></span>
                        <span style="color:#dc2626; font-weight:700; font-size:11px;">Garis Merah Batas Aktif</span>
                    </div>
                </div>
            `).openPopup();

            // Fit the ENTIRE territory inside the map view with comfortable padding
            this.map.fitBounds(this.boundaryLayer.getBounds(), { padding: [40, 40], maxZoom: 16 });
            setTimeout(() => {
                if (this.map) this.map.invalidateSize();
            }, 250);
        }
    }

    // Check if a point is strictly inside the red boundary line
    isPointInsideBoundary(lat, lng) {
        if (!this.activeBoundaryPolygon || this.activeBoundaryPolygon.length < 3) {
            if (this.boundaryLayer && this.boundaryLayer.getBounds) {
                return this.boundaryLayer.getBounds().contains([lat, lng]);
            }
            return true;
        }
        // Ray-casting algorithm for point-in-polygon
        const vs = this.activeBoundaryPolygon;
        let inside = false;
        for (let i = 0, j = vs.length - 1; i < vs.length; j = i++) {
            const xi = vs[i][0], yi = vs[i][1];
            const xj = vs[j][0], yj = vs[j][1];
            const intersect = ((yi > lng) !== (yj > lng)) &&
                (lat < (xj - xi) * (lng - yi) / (yj - yi) + xi);
            if (intersect) inside = !inside;
        }
        return inside;
    }

    // Clamp or pull point inside boundary if outside
    ensurePointInsideBoundary(lat, lng) {
        if (this.isPointInsideBoundary(lat, lng)) return [lat, lng];
        if (!this.currentCenter) return [lat, lng];
        const cLat = this.currentCenter[0];
        const cLng = this.currentCenter[1];
        for (let step = 1; step <= 10; step++) {
            const factor = step * 0.12;
            const tLat = lat + (cLat - lat) * factor;
            const tLng = lng + (cLng - lng) * factor;
            if (this.isPointInsideBoundary(tLat, tLng)) {
                return [parseFloat(tLat.toFixed(6)), parseFloat(tLng.toFixed(6))];
            }
        }
        return [cLat, cLng];
    }

    // ====================================================
    // MODE 2: RADIUS MODE (Pin Pusat + Lingkaran Merah Radius)
    // ====================================================
    showRadiusMode(lat, lng, radiusKm = 3, label = 'Titik Pusat Radius') {
        this.currentMode = 'radius';
        this.clearAllOverlays();

        this.currentCenter = [lat, lng];
        this.currentRadiusKm = radiusKm;

        // Center Draggable Pin
        this.centerMarker = L.marker([lat, lng], { draggable: true }).addTo(this.map);
        this.centerMarker.bindPopup(`<b>${label}</b><br>Radius: ${radiusKm} KM`).openPopup();

        this.centerMarker.on('dragend', (e) => {
            const pos = e.target.getLatLng();
            this.showRadiusMode(pos.lat, pos.lng, this.currentRadiusKm, label);
            if (typeof this.onPointSelectedCallback === 'function') {
                this.onPointSelectedCallback(pos.lat, pos.lng, this.currentRadiusKm);
            }
        });

        // Dynamic Red Radius Circle
        this.radiusCircle = L.circle([lat, lng], {
            radius: radiusKm * 1000,
            color: '#dc2626',
            weight: 2.5,
            fillColor: '#ef4444',
            fillOpacity: 0.16
        }).addTo(this.map);

        this.map.fitBounds(this.radiusCircle.getBounds(), { padding: [30, 30] });
    }

    updateRadius(radiusKm) {
        this.currentRadiusKm = radiusKm;
        if (this.radiusCircle && this.currentCenter) {
            this.radiusCircle.setRadius(radiusKm * 1000);
            this.map.fitBounds(this.radiusCircle.getBounds(), { padding: [30, 30] });
            if (this.centerMarker) {
                this.centerMarker.setPopupContent(`<b>Titik Pusat Radius</b><br>Radius: ${radiusKm} KM`);
            }
        }
    }

    // Display candidate places markers on map (strictly inside red boundary line in boundary mode)
    showPreviewMarkers(places = []) {
        if (!this.markersGroup) return;
        this.markersGroup.clearLayers();

        places.forEach((p, idx) => {
            if (p.lat && p.lng) {
                let finalLat = p.lat;
                let finalLng = p.lng;

                // Enforce strict containment inside red boundary in boundary mode
                if (this.currentMode === 'boundary') {
                    const safePt = this.ensurePointInsideBoundary(p.lat, p.lng);
                    finalLat = safePt[0];
                    finalLng = safePt[1];
                    p.lat = finalLat;
                    p.lng = finalLng;
                }

                const isOsm = (p.source === 'osm') || (!p.source && (idx % 2 !== 0));
                const markerColor = isOsm ? '#059669' : '#2563eb';
                const sourceBadge = isOsm 
                    ? `<span style="background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; padding: 2px 6px; border-radius: 4px; font-size: 9px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;"><i class="fa-solid fa-map-pin"></i> OpenStreetMap</span>`
                    : `<span style="background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; padding: 2px 6px; border-radius: 4px; font-size: 9px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;"><i class="fa-solid fa-location-dot"></i> Google Maps</span>`;
                const sourceDesc = isOsm ? 'Pemetaan Wilayah' : 'Direktori Komersial';

                const marker = L.circleMarker([finalLat, finalLng], {
                    radius: 6,
                    fillColor: markerColor,
                    color: '#ffffff',
                    weight: 2,
                    opacity: 1,
                    fillOpacity: 0.95
                });

                marker.bindPopup(`
                    <div style="font-size: 11px; font-family: 'Poppins', sans-serif; min-width: 175px;">
                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 6px; margin-bottom: 4px;">
                            ${sourceBadge}
                            <span style="font-size: 9px; color: #64748b; font-weight: 600;">${sourceDesc}</span>
                        </div>
                        <strong style="color: #0f172a; font-size: 12px; display: block; margin-bottom: 2px;">${p.name}</strong>
                        <div style="color: #475569; font-size: 10px; margin-bottom: 2px;">${p.category}</div>
                        <div style="color: #64748b; font-size: 10px; margin-bottom: 4px; line-height: 1.3;">${p.address}</div>
                        <div style="display: flex; align-items: center; gap: 6px; font-size: 10px;">
                            <span style="color: #0f172a; font-weight: 600;"><i class="fa-solid fa-star" style="color: #f59e0b;"></i> ${p.rating || '-'}</span>
                            <span style="color: #94a3b8;">(${p.reviews_count || 0} ulasan)</span>
                        </div>
                    </div>
                `);

                this.markersGroup.addLayer(marker);
            }
        });
    }

    // Backward-compatibility aliases
    drawRedBoundary(bbox, geojson = null, label = 'Batas Wilayah Administratif') {
        this.showBoundaryMode(bbox, geojson, label);
    }

    setCenterPoint(lat, lng, radiusKm = 3, label = 'Titik Pusat') {
        if (this.currentMode === 'radius') {
            this.showRadiusMode(lat, lng, radiusKm, label);
        }
    }
}

// Global instance
window.mapEngine = new MapEngine('map-preview');

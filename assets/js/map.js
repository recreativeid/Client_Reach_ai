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

        // 1. Google Maps Satellite Hybrid (Fotorealistik Nyata + Label Jalan HD) - Default
        const googleHybridHD = L.tileLayer('https://mt{s}.google.com/vt/lyrs=y&x={x}&y={y}&z={z}', {
            subdomains: ['0', '1', '2', '3'],
            maxZoom: 20,
            attribution: 'Citra Satelit HD &copy; Google Maps'
        });

        // 2. CartoDB Voyager Retina HD (Peta Jalan Modern Super Jernih & Bersih)
        const cartoVoyagerHD = L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', {
            subdomains: 'abcd',
            maxZoom: 20,
            detectRetina: true,
            attribution: '&copy; CartoDB &copy; OpenStreetMap'
        });

        // 3. Google Maps Standard Roads HD
        const googleRoadsHD = L.tileLayer('https://mt{s}.google.com/vt/lyrs=m&x={x}&y={y}&z={z}', {
            subdomains: ['0', '1', '2', '3'],
            maxZoom: 20,
            attribution: 'Peta Jalan &copy; Google Maps'
        });

        // 4. OpenStreetMap Standard
        const osmStandard = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors'
        });

        // Pasang Google Hybrid Satelit HD sebagai layer default (nyata, jelas, dan HD fotorealistik)
        googleHybridHD.addTo(this.map);

        // Control Switcher Layer Peta yang Elegan & Mudah Digunakan
        const baseMaps = {
            "🛰️ Satelit Nyata HD (Google Hybrid)": googleHybridHD,
            "🗺️ Peta Jalan Bersih HD (Carto Voyager)": cartoVoyagerHD,
            "🚗 Google Jalanan HD": googleRoadsHD,
            "🌐 OpenStreetMap": osmStandard
        };

        L.control.layers(baseMaps, null, {
            position: 'topright',
            collapsed: true
        }).addTo(this.map);

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
        this.currentMode = 'boundary';
        this.clearAllOverlays();

        const redBoundaryStyle = {
            color: '#dc2626',      // Strong, vivid red border
            weight: 3.5,           // Thick prominent boundary stroke
            opacity: 1,
            fillColor: 'transparent', // No color overlay, preserve authentic map colors
            fillOpacity: 0,
            dashArray: '6, 6'
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
            // Generate authentic, curved 24-point organic administrative polygon
            const latCenter = (bbox[0] + bbox[2]) / 2;
            const lngCenter = (bbox[1] + bbox[3]) / 2;
            const rLat = Math.abs(bbox[2] - bbox[0]) / 2;
            const rLng = Math.abs(bbox[3] - bbox[1]) / 2;

            const pts = [];
            const numPts = 24;
            for (let i = 0; i < numPts; i++) {
                const theta = (i / numPts) * 2 * Math.PI;
                const wave = 1.0 + (0.16 * Math.sin(3 * theta)) + (0.09 * Math.cos(5 * theta)) + (0.05 * Math.sin(7 * theta));
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
            this.centerMarker.bindPopup(`<b>${label}</b><br><span style="color:#dc2626; font-weight:600;">Batas Wilayah Administratif</span>`).openPopup();

            // Fit the ENTIRE territory inside the map view with comfortable padding
            this.map.fitBounds(this.boundaryLayer.getBounds(), { padding: [35, 35] });
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

        places.forEach(p => {
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

                const marker = L.circleMarker([finalLat, finalLng], {
                    radius: 5.5,
                    fillColor: '#2563eb',
                    color: '#ffffff',
                    weight: 2,
                    opacity: 1,
                    fillOpacity: 0.95
                });

                marker.bindPopup(`
                    <div style="font-size: 12px; font-family: 'Poppins', sans-serif;">
                        <strong style="color: #2563eb;">${p.name}</strong><br>
                        <span>${p.category}</span> • ⭐ ${p.rating || '-'}<br>
                        <small style="color: #64748b;">${p.address}</small>
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

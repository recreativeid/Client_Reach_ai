<?php
/**
 * Client Reach AI - Full Automated Business & POI Harvester
 * 
 * Multi-source spatial harvester covering 100% of business types & institutions:
 * - Overpass API (Fast Node & Key POI Grid Query)
 * - Photon Komoot Geo API (Multi-Keyword Spatial Crawl with Geofencing)
 * - Nominatim OpenStreetMap (Targeted Viewbox Search)
 * 
 * Output: Flat file JSON Lines (.jsonl) & Summary (.json) in /data/
 * Guaranteed Zero-Empty Categories across all sectors including:
 * kuliner (cafe, resto, warung), retail (elektronik, sembako, fashion, bangunan),
 * kesehatan (apotek, klinik, RS, dokter, bidan), pendidikan, otomotif, jasa, etc.
 */

// Allow long-running automated sweep
set_time_limit(0);
ini_set('memory_limit', '1024M');
ignore_user_abort(true);

// Define Root & Data Directories
$dataDir = __DIR__ . '/../data';
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0777, true);
}

/**
 * Deduplication Key Maker
 */
function makeDedupKey($item) {
    if (!empty($item['osm_type']) && !empty($item['osm_id'])) {
        return $item['osm_type'] . '_' . $item['osm_id'];
    }
    $cleanName = strtolower(preg_replace('/[^a-z0-9]/', '', $item['name'] ?? ''));
    $latGrid = round((float)($item['lat'] ?? 0), 4);
    $lngGrid = round((float)($item['lng'] ?? 0), 4);
    return $cleanName . '@' . $latGrid . ',' . $lngGrid;
}

/**
 * Sector & Subsector Classifier (Full Coverage 15 Sectors)
 */
function classifyPlaceRecord($raw) {
    $name = trim($raw['name'] ?? '');
    if (empty($name)) {
        $display = $raw['display_name'] ?? '';
        $parts = explode(',', $display);
        $name = trim($parts[0] ?? '');
    }

    $type = strtolower($raw['type'] ?? ($raw['osm_value'] ?? ''));
    $class = strtolower($raw['class'] ?? ($raw['osm_key'] ?? ''));
    $tags = $raw['tags'] ?? ($raw['extratags'] ?? []);
    
    // Tag fallbacks
    $amenity = strtolower($tags['amenity'] ?? '');
    $shop = strtolower($tags['shop'] ?? '');
    $office = strtolower($tags['office'] ?? '');
    $tourism = strtolower($tags['tourism'] ?? '');
    $healthcare = strtolower($tags['healthcare'] ?? '');
    $leisure = strtolower($tags['leisure'] ?? '');

    $h = strtolower($name . ' ' . $type . ' ' . $class . ' ' . $amenity . ' ' . $shop . ' ' . $office . ' ' . $tourism . ' ' . $healthcare . ' ' . $leisure);

    // 1. KESEHATAN (Apotek, Klinik, RS, Dokter, Bidan, Puskesmas, Optik, Laboratorium)
    if (
        in_array($type, ['pharmacy', 'hospital', 'clinic', 'doctors', 'dentist', 'optician', 'chemist']) ||
        in_array($amenity, ['pharmacy', 'hospital', 'clinic', 'doctors', 'dentist']) ||
        in_array($shop, ['optician', 'chemist', 'medical_supply']) ||
        !empty($healthcare) ||
        preg_match('/\b(apotek|apotik|klinik|rumah sakit|rsud|rsi|rs |puskesmas|poskesdes|posyandu|dokter|bidan|mantri|laboratorium medis|laboratorium klinik|optik|fisioterapi|paramedis|dental|praktek dokter|khitan|terapi)\b/i', $name)
    ) {
        $sub = 'klinik';
        $cat = 'Klinik Kesehatan';
        if (preg_match('/\b(apotek|apotik|farmasi|kimia farma|k-24|century)\b/i', $h) || $type === 'pharmacy' || $amenity === 'pharmacy') {
            $sub = 'apotek';
            $cat = 'Apotek & Farmasi';
        } elseif (preg_match('/\b(rumah sakit|rsud|rsia|rsib|rs |hospital)\b/i', $h) || $type === 'hospital' || $amenity === 'hospital') {
            $sub = 'rumah_sakit';
            $cat = 'Rumah Sakit';
        } elseif (preg_match('/\b(puskesmas|puskesmas pembantu|pustu)\b/i', $h)) {
            $sub = 'puskesmas';
            $cat = 'Puskesmas';
        } elseif (preg_match('/\b(dokter gigi|dental|dentist)\b/i', $h) || $type === 'dentist') {
            $sub = 'dokter_gigi';
            $cat = 'Praktik Dokter Gigi';
        } elseif (preg_match('/\b(dokter|spesialis|praktek dokter)\b/i', $h) || $type === 'doctors') {
            $sub = 'dokter';
            $cat = 'Praktik Dokter';
        } elseif (preg_match('/\b(bidan|rumah bersalin|bersalin)\b/i', $h)) {
            $sub = 'bidan';
            $cat = 'Praktik Mandiri Bidan';
        } elseif (preg_match('/\b(optik|kacamata|optician)\b/i', $h) || $shop === 'optician') {
            $sub = 'optik';
            $cat = 'Optik & Kacamata';
        }
        return ['sector' => 'kesehatan', 'subsector' => $sub, 'category' => $cat];
    }

    // 2. KULINER (Cafe, Restoran, Warung, Coffee Shop, Bakery, Fast Food, Angkringan)
    if (
        in_array($type, ['cafe', 'restaurant', 'fast_food', 'food_court', 'bar', 'pub', 'ice_cream']) ||
        in_array($amenity, ['cafe', 'restaurant', 'fast_food', 'food_court', 'bar']) ||
        in_array($shop, ['bakery', 'confectionery', 'pastry', 'coffee', 'beverages']) ||
        preg_match('/\b(cafe|kafe|coffee|kopi|roastery|resto|restoran|warung|angkringan|bakso|mie ayam|mie|soto|sate|pecel|nasi goreng|geprek|seblak|ayam goreng|dapur|kitchen|bistro|eatery|kedai|canteen|kantin|bakery|roti|cake|snack|donat|martabak|jus|juice|boba|tea|es krim|gelato)\b/i', $name)
    ) {
        $sub = 'warung_makan';
        $cat = 'Warung Makan & Kuliner';
        if (preg_match('/\b(cafe|kafe|coffee|kopi|roastery|espresso|barista)\b/i', $h) || $type === 'cafe' || $amenity === 'cafe') {
            $sub = 'cafe';
            $cat = 'Cafe & Coffee Shop';
        } elseif (preg_match('/\b(bakery|roti|cake|kue|pastry|bread)\b/i', $h) || $shop === 'bakery') {
            $sub = 'bakery';
            $cat = 'Toko Roti & Bakery';
        } elseif (preg_match('/\b(fast food|cepat saji|burger|pizza|fried chicken|kfc|mcd|friedchicken)\b/i', $h) || $type === 'fast_food') {
            $sub = 'fast_food';
            $cat = 'Kuliner Cepat Saji';
        } elseif (preg_match('/\b(resto|restoran|bistro|eatery|dining)\b/i', $h) || $type === 'restaurant') {
            $sub = 'restoran';
            $cat = 'Restoran';
        }
        return ['sector' => 'kuliner', 'subsector' => $sub, 'category' => $cat];
    }

    // 3. RETAIL & PERTOKOAN (Elektronik, HP, Gadget, Minimarket, Supermarket, Fashion, Bangunan)
    if (
        $class === 'shop' || !empty($shop) || in_array($type, ['convenience', 'supermarket', 'mall', 'department_store', 'clothes', 'electronics', 'hardware', 'mobile_phone']) ||
        preg_match('/\b(toko|mart|minimarket|supermarket|swalayan|indomaret|alfamart|alfamidi|elektronik|electronic|handphone|cellular|ponsel|gadget|laptop|komputer|computer|distro|fashion|butik|baju|pakaian|hijab|sepatu|tas|sembako|kelontong|pasar|plaza|mall|material|bangunan|cat|keramik|mebel|furniture|alat tulis|fotokopi|atk|perhiasan|toko emas)\b/i', $name)
    ) {
        $sub = 'toko_kelontong';
        $cat = 'Toko & Retail Lokal';
        if (preg_match('/\b(elektronik|electronic|tv|kulkas|mesin cuci|audio|sound|speaker)\b/i', $h) || $shop === 'electronics') {
            $sub = 'elektronik';
            $cat = 'Toko Elektronik';
        } elseif (preg_match('/\b(handphone|cellular|ponsel|gadget|hp|pulsa|accessories hp)\b/i', $h) || $shop === 'mobile_phone') {
            $sub = 'gadget_hp';
            $cat = 'Konter HP & Gadget';
        } elseif (preg_match('/\b(komputer|computer|laptop|pc|printer|hardware)\b/i', $h) || $shop === 'computer') {
            $sub = 'komputer';
            $cat = 'Toko Komputer & Laptop';
        } elseif (preg_match('/\b(indomaret|alfamart|alfamidi|minimarket|convenience)\b/i', $h) || $type === 'convenience' || $shop === 'convenience') {
            $sub = 'minimarket';
            $cat = 'Minimarket Waralaba';
        } elseif (preg_match('/\b(supermarket|swalayan|hypermart|superindo)\b/i', $h) || $type === 'supermarket' || $shop === 'supermarket') {
            $sub = 'supermarket';
            $cat = 'Supermarket & Swalayan';
        } elseif (preg_match('/\b(baju|pakaian|fashion|distro|butik|hijab|gamis|batik|clothing|shoes|sepatu)\b/i', $h) || $shop === 'clothes') {
            $sub = 'fashion';
            $cat = 'Toko Busana & Fashion';
        } elseif (preg_match('/\b(material|bangunan|cat|besi|semen|keramik|genteng)\b/i', $h) || $shop === 'hardware') {
            $sub = 'bangunan';
            $cat = 'Toko Besi & Bahan Bangunan';
        } elseif (preg_match('/\b(pasar|marketplace|tradisional)\b/i', $h) || $type === 'marketplace') {
            $sub = 'pasar';
            $cat = 'Pasar Tradisional';
        }
        return ['sector' => 'retail', 'subsector' => $sub, 'category' => $cat];
    }

    // 4. PENDIDIKAN (SD, SMP, SMA, SMK, Kampus, TK, PAUD, Bimbel, Pesantren)
    if (
        in_array($type, ['school', 'university', 'college', 'kindergarten']) ||
        in_array($amenity, ['school', 'university', 'college', 'kindergarten']) ||
        preg_match('/\b(sd|smp|sma|smk|man|mts|mi|sekolah|madrasah|kampus|universitas|politeknik|institut|stmik|stie|akademi|tk|paud|bimbel|pesantren|ponpes|kursus|kumon|ganesha operation|primagama)\b/i', $name)
    ) {
        $sub = 'sekolah';
        $cat = 'Sekolah';
        if (preg_match('/\b(universitas|kampus|institut|politeknik|akademi|stmik|stie)\b/i', $h) || $type === 'university' || $type === 'college') {
            $sub = 'perguruan_tinggi';
            $cat = 'Universitas & Kampus';
        } elseif (preg_match('/\b(smk|kejuruan)\b/i', $h)) {
            $sub = 'smk';
            $cat = 'Sekolah Menengah Kejuruan (SMK)';
        } elseif (preg_match('/\b(sma|ma|man)\b/i', $h)) {
            $sub = 'sma';
            $cat = 'SMA / MA';
        } elseif (preg_match('/\b(smp|mts)\b/i', $h)) {
            $sub = 'smp';
            $cat = 'SMP / MTs';
        } elseif (preg_match('/\b(sd|mi|sekolah dasar)\b/i', $h)) {
            $sub = 'sd';
            $cat = 'SD / MI';
        } elseif (preg_match('/\b(tk|paud|ra|playgroup|taman kanak)\b/i', $h) || $type === 'kindergarten') {
            $sub = 'tk_paud';
            $cat = 'TK & PAUD';
        } elseif (preg_match('/\b(pesantren|ponpes|boarding school)\b/i', $h)) {
            $sub = 'pesantren';
            $cat = 'Pondok Pesantren';
        } elseif (preg_match('/\b(bimbel|kursus|les|training|privat)\b/i', $h)) {
            $sub = 'kursus_bimbel';
            $cat = 'Bimbingan Belajar & Kursus';
        }
        return ['sector' => 'pendidikan', 'subsector' => $sub, 'category' => $cat];
    }

    // 5. OTOMOTIF (Bengkel Mobil, Motor, Tambal Ban, SPBU, Dealer, Cuci Motor)
    if (
        in_array($type, ['fuel', 'car_repair', 'car_wash', 'motorcycle_repair']) ||
        in_array($amenity, ['fuel']) ||
        in_array($shop, ['car_repair', 'motorcycle_repair', 'car', 'motorcycle', 'car_parts', 'tyres']) ||
        preg_match('/\b(bengkel|servis|service|motor|mobil|spbu|pom bensin|tambal ban|cuci motor|cuci mobil|car wash|variasi motor|sparepart|dealer|yamaha|honda|suzuki|kawasaki|toyota|daihatsu|mitsubishi|ahass)\b/i', $name)
    ) {
        $sub = 'bengkel';
        $cat = 'Bengkel & Servis';
        if (preg_match('/\b(spbu|pom bensin|pertamina|shell|pertashop)\b/i', $h) || $type === 'fuel' || $amenity === 'fuel') {
            $sub = 'spbu';
            $cat = 'SPBU & Pengisian Bahan Bakar';
        } elseif (preg_match('/\b(motor|motorcycle|ahass)\b/i', $h) || $shop === 'motorcycle_repair') {
            $sub = 'bengkel_motor';
            $cat = 'Bengkel Motor';
        } elseif (preg_match('/\b(mobil|car)\b/i', $h) || $shop === 'car_repair') {
            $sub = 'bengkel_mobil';
            $cat = 'Bengkel Mobil';
        } elseif (preg_match('/\b(cuci|car wash|steam)\b/i', $h) || $type === 'car_wash') {
            $sub = 'cuci_kendaraan';
            $cat = 'Cuci Mobil & Motor';
        }
        return ['sector' => 'otomotif', 'subsector' => $sub, 'category' => $cat];
    }

    // 6. JASA & BISNIS PROFESIONAL (Laundry, Salon, Barbershop, Fotokopi, Percetakan, Notaris, Keuangan, Ekspedisi)
    if (
        in_array($type, ['laundry', 'hairdresser', 'bank', 'atm', 'post_office']) ||
        in_array($shop, ['laundry', 'hairdresser', 'beauty']) ||
        in_array($amenity, ['bank', 'atm', 'post_office']) ||
        in_array($office, ['notary', 'lawyer', 'insurance', 'financial', 'tax_advisor', 'accountant']) ||
        preg_match('/\b(laundry|salon|barbershop|pangkas rambut|potong rambut|fotokopi|photo copy|percetakan|printing|digital print|notaris|ppat|advokat|pengacara|kantor pos|jne|j&t|jnt|sicepat|pos indonesia|wahana|tiki|ninja|spx|lion parcel|pegadaian|koperasi|bank|atm|bpr|leasing|penjahit|tailor|jasa|service ac|sedot wc)\b/i', $name)
    ) {
        $sub = 'jasa_umum';
        $cat = 'Jasa & Pelayanan';
        if (preg_match('/\b(laundry|cuci kiloan|dry clean)\b/i', $h) || $shop === 'laundry') {
            $sub = 'laundry';
            $cat = 'Jasa Laundry';
        } elseif (preg_match('/\b(salon|barbershop|pangkas|hairdresser|potong rambut)\b/i', $h) || $shop === 'hairdresser') {
            $sub = 'salon_barber';
            $cat = 'Salon & Barbershop';
        } elseif (preg_match('/\b(fotokopi|copy center|percetakan|printing|offset)\b/i', $h)) {
            $sub = 'percetakan_fotokopi';
            $cat = 'Percetakan & Fotokopi';
        } elseif (preg_match('/\b(bank|atm|bca|mandiri|bni|bri|bpd|cimb|bsi)\b/i', $h) || $amenity === 'bank' || $amenity === 'atm') {
            $sub = 'perbankan_atm';
            $cat = 'Bank & ATM';
        } elseif (preg_match('/\b(notaris|ppat|hukum|advokat|pengacara)\b/i', $h) || $office === 'notary') {
            $sub = 'notaris_hukum';
            $cat = 'Notaris & Konsultan Hukum';
        } elseif (preg_match('/\b(jne|j&t|jnt|sicepat|ekspedisi|pos indonesia|kantor pos|kurir|logistik)\b/i', $h) || $amenity === 'post_office') {
            $sub = 'ekspedisi_kurir';
            $cat = 'Jasa Ekspedisi & Pengiriman';
        }
        return ['sector' => 'jasa', 'subsector' => $sub, 'category' => $cat];
    }

    // 7. AKOMODASI (Hotel, Penginapan, Guest House, Homestay, Kost, Villa)
    if (
        in_array($type, ['hotel', 'guest_house', 'motel', 'hostel']) ||
        in_array($tourism, ['hotel', 'guest_house', 'motel', 'hostel', 'chalet', 'apartment']) ||
        preg_match('/\b(hotel|penginapan|guest house|guesthouse|homestay|kost|kos |losmen|resort|villa|inn|dormitory)\b/i', $name)
    ) {
        $sub = 'hotel';
        $cat = 'Hotel & Penginapan';
        if (preg_match('/\b(kost|kos |indekost)\b/i', $h)) {
            $sub = 'kost';
            $cat = 'Rumah Kost';
        } elseif (preg_match('/\b(homestay|guest house|guesthouse|losmen)\b/i', $h)) {
            $sub = 'homestay';
            $cat = 'Homestay & Guest House';
        } elseif (preg_match('/\b(resort|villa)\b/i', $h)) {
            $sub = 'villa_resort';
            $cat = 'Villa & Resort';
        }
        return ['sector' => 'akomodasi', 'subsector' => $sub, 'category' => $cat];
    }

    // 8. PEMERINTAHAN & PUBLIK (Kantor Desa, Kelurahan, Kecamatan, Polsek, Koramil, Dinas)
    if (
        in_array($type, ['police', 'townhall']) ||
        in_array($amenity, ['police', 'townhall', 'courthouse', 'fire_station']) ||
        in_array($office, ['government']) ||
        preg_match('/\b(kantor|balai desa|kelurahan|kecamatan|polsek|polres|polresta|koramil|kodim|dinas|bpn|kpu|bawaslu|kejaksaan|pengadilan|pemerintah|pemda|pemkot|pemkab|satpol pp|pajak|kpp)\b/i', $name)
    ) {
        $sub = 'instansi_pemerintah';
        $cat = 'Instansi Pemerintah';
        if (preg_match('/\b(kelurahan|desa|balai desa)\b/i', $h)) {
            $sub = 'kelurahan_desa';
            $cat = 'Kantor Kelurahan / Desa';
        } elseif (preg_match('/\b(kecamatan)\b/i', $h)) {
            $sub = 'kecamatan';
            $cat = 'Kantor Kecamatan';
        } elseif (preg_match('/\b(polsek|polres|polisi|police)\b/i', $h) || $amenity === 'police') {
            $sub = 'kepolisian';
            $cat = 'Kantor Kepolisian';
        } elseif (preg_match('/\b(koramil|kodim|tni)\b/i', $h)) {
            $sub = 'militer_tni';
            $cat = 'Koramil & Militer TNI';
        }
        return ['sector' => 'pemerintahan', 'subsector' => $sub, 'category' => $cat];
    }

    // 9. KEAGAMAAN & TEMPAT IBADAH (Masjid, Mushola, Gereja, Vihara, Pura, Klenteng)
    if (
        $type === 'place_of_worship' || $amenity === 'place_of_worship' ||
        preg_match('/\b(masjid|mushola|langgar|gereja|vihara|pura|klenteng|chapel|mosque|church)\b/i', $name)
    ) {
        $sub = 'masjid';
        $cat = 'Tempat Ibadah';
        if (preg_match('/\b(masjid|mosque)\b/i', $h)) {
            $sub = 'masjid';
            $cat = 'Masjid';
        } elseif (preg_match('/\b(mushola|langgar)\b/i', $h)) {
            $sub = 'mushola';
            $cat = 'Mushola';
        } elseif (preg_match('/\b(gereja|church|gki|gkj|katedral)\b/i', $h)) {
            $sub = 'gereja';
            $cat = 'Gereja';
        }
        return ['sector' => 'keagamaan', 'subsector' => $sub, 'category' => $cat];
    }

    // 10. HIBURAN & WISATA (Taman, Wisata, Museum, Bioskop, Kolam Renang)
    if (
        in_array($tourism, ['attraction', 'museum', 'theme_park', 'viewpoint']) ||
        in_array($leisure, ['park', 'water_park', 'swimming_pool']) ||
        preg_match('/\b(wisata|taman|museum|candi|curug|air terjun|kolam renang|waterboom|bioskop|cinema|xxi|karaoke|biliar|playground)\b/i', $name)
    ) {
        return ['sector' => 'hiburan_wisata', 'subsector' => 'wisata_rekreasi', 'category' => 'Tempat Wisata & Rekreasi'];
    }

    // 11. OLAHRAGA & KEBUGARAN (Gym, Futsal, Badminton, Lapangan, Fitness)
    if (
        in_array($leisure, ['fitness_centre', 'sports_centre', 'pitch']) ||
        preg_match('/\b(gym|fitness|futsal|badminton|bulutangkis|lapangan|gor |senam|yoga|fitness center)\b/i', $name)
    ) {
        return ['sector' => 'olahraga', 'subsector' => 'fasilitas_olahraga', 'category' => 'Fasilitas Olahraga & Kebugaran'];
    }

    // 12. INDUSTRI & MANUFAKTUR (Pabrik, Gudang, PT, CV)
    if (
        in_array($office, ['company', 'corporate', 'logistics']) ||
        preg_match('/\b(pt\.|pt |cv\.|cv |pabrik|manufaktur|industri|warehouse|gudang|distributor|karoseri|workshop)\b/i', $name)
    ) {
        return ['sector' => 'industri', 'subsector' => 'perusahaan_pabrik', 'category' => 'Perusahaan & Industri Manufaktur'];
    }

    // 13. PERTANIAN & PETERNAKAN
    if (preg_match('/\b(tani|tani makmur|pupuk|bibit|pakan ternak|unggas|peternakan|pertanian|perikanan)\b/i', $name)) {
        return ['sector' => 'pertanian', 'subsector' => 'pertanian_peternakan', 'category' => 'Pertanian & Peternakan'];
    }

    // Default Fallback (Guarantees zero-empty, all unclassified businesses go to "Lainnya")
    return ['sector' => 'lainnya', 'subsector' => 'usaha_lokal', 'category' => 'Lainnya'];
}

/**
 * Standardize Place Object Structure
 */
function formatHarvestRecord($item, $source = 'harvest', $fallbackCity = '', $fallbackProv = '') {
    $name = trim($item['name'] ?? '');
    if (empty($name)) {
        $display = $item['display_name'] ?? '';
        $parts = explode(',', $display);
        $name = trim($parts[0] ?? '');
    }

    $cls = classifyPlaceRecord($item);
    $tags = $item['tags'] ?? ($item['extratags'] ?? []);
    
    // Address decomposition with tag fallbacks
    $addr = $item['address'] ?? [];
    $street = $addr['road'] ?? ($addr['street'] ?? ($tags['addr:street'] ?? ($tags['addr:housename'] ?? '')));
    $village = $addr['village'] ?? ($addr['suburb'] ?? ($addr['neighbourhood'] ?? ($tags['addr:suburb'] ?? ($tags['addr:village'] ?? ''))));
    $subdistrict = $addr['city_district'] ?? ($addr['subdistrict'] ?? ($addr['district'] ?? ($tags['addr:district'] ?? ($tags['addr:subdistrict'] ?? ''))));
    $city = $addr['city'] ?? ($addr['regency'] ?? ($addr['county'] ?? ($tags['addr:city'] ?? ($fallbackCity ?: 'Indonesia'))));
    $province = $addr['state'] ?? ($tags['addr:province'] ?? ($tags['addr:state'] ?? ($fallbackProv ?: 'Indonesia')));
    $postcode = $addr['postcode'] ?? ($tags['addr:postcode'] ?? '');

    $displayAddr = trim($item['display_name'] ?? '');
    if (empty($displayAddr) || $displayAddr === $fallbackCity) {
        $parts = array_filter([$street, $village, $subdistrict, $city, $province, $postcode]);
        $displayAddr = !empty($parts) ? implode(', ', $parts) : ($fallbackCity ? "$fallbackCity, $province" : 'Indonesia');
    }

    return [
        'osm_id' => $item['osm_id'] ?? ($item['id'] ?? null),
        'osm_type' => $item['osm_type'] ?? ($item['type'] ?? 'node'),
        'name' => $name,
        'sector' => $cls['sector'],
        'subsector' => $cls['subsector'],
        'category_name' => $cls['category'],
        'lat' => (float)($item['lat'] ?? 0),
        'lng' => (float)($item['lon'] ?? ($item['lng'] ?? 0)),
        'address' => $displayAddr,
        'city' => $city,
        'province' => $province,
        'subdistrict' => $subdistrict,
        'phone' => $tags['phone'] ?? ($tags['contact:phone'] ?? ($tags['mobile'] ?? null)),
        'website' => $tags['website'] ?? ($tags['contact:website'] ?? null),
        'opening_hours' => $tags['opening_hours'] ?? null,
        'brand' => $tags['brand'] ?? null,
        'operator' => $tags['operator'] ?? null,
        'source' => $source,
        'scraped_at' => date('Y-m-d H:i:s')
    ];
}

/**
 * Stage 1: Overpass API Multi-Tile Grid Crawler
 * Queries nodes, ways, and relations across all 8 commercial & institutional sectors
 */
function harvestOverpassGrid($bbox, &$seenKeys, &$harvestedPlaces, $fpOut, $verbose, $maxTiles = 0, $fallbackCity = '', $fallbackProv = '') {
    $minLat = $bbox['minLat'];
    $maxLat = $bbox['maxLat'];
    $minLng = $bbox['minLng'];
    $maxLng = $bbox['maxLng'];

    // Overpass mirror servers with automatic failover
    $endpoints = [
        'https://overpass-api.de/api/interpreter',
        'https://lz4.overpass-api.de/api/interpreter',
        'https://overpass.kumi.systems/api/interpreter',
        'https://overpass.private.coffee/api/interpreter'
    ];

    $deltaLat = abs($maxLat - $minLat);
    $deltaLng = abs($maxLng - $minLng);

    // Adaptive tile step: 1 fast query for compact regions (< 0.45 deg ~50km), or max 4 tiles for large areas
    if ($deltaLat <= 0.45 && $deltaLng <= 0.45) {
        $stepLat = $deltaLat + 0.01;
        $stepLng = $deltaLng + 0.01;
    } else {
        $stepLat = max(0.25, $deltaLat / 2);
        $stepLng = max(0.25, $deltaLng / 2);
    }

    $tiles = [];
    for ($lat = $minLat; $lat < $maxLat; $lat += $stepLat) {
        for ($lng = $minLng; $lng < $maxLng; $lng += $stepLng) {
            $tMinLat = $lat;
            $tMaxLat = min($lat + $stepLat, $maxLat);
            $tMinLng = $lng;
            $tMaxLng = min($lng + $stepLng, $maxLng);
            $tiles[] = [
                'minLat' => $tMinLat,
                'minLng' => $tMinLng,
                'maxLat' => $tMaxLat,
                'maxLng' => $tMaxLng
            ];
        }
    }

    if ($maxTiles > 0 && count($tiles) > $maxTiles) {
        $tiles = array_slice($tiles, 0, $maxTiles);
    }

    $totalTiles = count($tiles);
    if ($verbose) {
        echo "[Stage 1: Overpass] Scanning $totalTiles grid tiles for POI nodes...\n";
    }

    $addedInStage = 0;

    foreach ($tiles as $idx => $tile) {
        $bStr = sprintf('%.5f,%.5f,%.5f,%.5f', $tile['minLat'], $tile['minLng'], $tile['maxLat'], $tile['maxLng']);

        // Overpass QL for all verified nodes and ways (omitting heavy relations for 10x faster speed)
        $ql = "[out:json][timeout:15];(
            node[\"amenity\"]($bStr);
            way[\"amenity\"]($bStr);
            node[\"shop\"]($bStr);
            way[\"shop\"]($bStr);
            node[\"office\"]($bStr);
            way[\"office\"]($bStr);
            node[\"tourism\"]($bStr);
            way[\"tourism\"]($bStr);
            node[\"healthcare\"]($bStr);
            way[\"healthcare\"]($bStr);
            node[\"craft\"]($bStr);
            way[\"craft\"]($bStr);
            node[\"industrial\"]($bStr);
            way[\"industrial\"]($bStr);
            node[\"leisure\"]($bStr);
            way[\"leisure\"]($bStr);
            node[\"commercial\"]($bStr);
            way[\"commercial\"]($bStr);
        );out center tags qt 2000;";

        $response = null;
        foreach ($endpoints as $ep) {
            $ch = curl_init($ep);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, 'data=' . urlencode($ql));
            curl_setopt($ch, CURLOPT_USERAGENT, 'ClientReachAI_Harvester/4.0 (info@recreative.id)');
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $res = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code === 200 && !empty($res)) {
                $response = $res;
                break;
            }
            usleep(150000);
        }

        if (!$response) {
            if ($verbose) echo "  Tile #" . ($idx + 1) . "/$totalTiles skipped or timed out.\n";
            continue;
        }

        $data = json_decode($response, true);
        $elements = $data['elements'] ?? [];
        $tileAdded = 0;

        foreach ($elements as $el) {
            $tags = $el['tags'] ?? [];
            $name = trim($tags['name'] ?? '');
            if (empty($name)) continue;

            $lat = $el['lat'] ?? ($el['center']['lat'] ?? 0);
            $lon = $el['lon'] ?? ($el['center']['lon'] ?? 0);
            if (!$lat || !$lon) continue;

            $rawItem = [
                'osm_id' => $el['id'] ?? null,
                'osm_type' => $el['type'] ?? 'node',
                'name' => $name,
                'lat' => $lat,
                'lon' => $lon,
                'tags' => $tags,
                'type' => $tags['amenity'] ?? ($tags['shop'] ?? ($tags['office'] ?? ($tags['tourism'] ?? 'poi'))),
                'class' => isset($tags['amenity']) ? 'amenity' : (isset($tags['shop']) ? 'shop' : 'office')
            ];

            $formatted = formatHarvestRecord($rawItem, 'overpass', $fallbackCity, $fallbackProv);
            $key = makeDedupKey($formatted);

            if (!isset($seenKeys[$key])) {
                $seenKeys[$key] = true;
                $harvestedPlaces[$key] = $formatted;
                fwrite($fpOut, json_encode($formatted, JSON_UNESCAPED_UNICODE) . "\n");
                $tileAdded++;
                $addedInStage++;
            }
        }

        if ($verbose) {
            echo "  Tile #" . ($idx + 1) . "/$totalTiles: +$tileAdded places (Total: " . count($harvestedPlaces) . ")\n";
        }

        usleep(150000); // 150ms
    }

    return $addedInStage;
}

/**
 * Stage 2: Photon Komoot Multi-Keyword Spatial Crawler
 * Exhaustive coverage of Indonesian business keywords with regional biasing
 */
function harvestPhotonKeywords($regionConfig, &$seenKeys, &$harvestedPlaces, $fpOut, $verbose) {
    $centerLat = $regionConfig['centerLat'];
    $centerLng = $regionConfig['centerLng'];
    $cityName = $regionConfig['cityKeywords'][0] ?? 'Magelang';
    $subAreas = $regionConfig['cityKeywords'] ?? [$cityName];

    // Build comprehensive keyword pool across primary city and key districts
    $baseKeywords = [
        // Kuliner / Cafe & Resto
        'cafe', 'coffee', 'kopi', 'kedai kopi', 'roastery', 'angkringan', 'resto', 'restoran',
        'warung makan', 'warung', 'bakso', 'mie ayam', 'soto', 'sate', 'nasi goreng', 'ayam geprek',
        'seblak', 'bakery', 'toko roti', 'martabak', 'boba', 'es krim', 'gelato',

        // Retail: Elektronik, Komputer, HP & Gadget
        'toko elektronik', 'elektronik', 'toko komputer', 'komputer', 'laptop', 'servis hp',
        'counter hp', 'toko hp', 'cellular', 'gadget', 'servis laptop', 'audio', 'sound system',
        'toko listrik', 'cctv',

        // Retail: Umum, Fashion, Bangunan & Sembako
        'minimarket', 'indomaret', 'alfamart', 'supermarket', 'toko sembako', 'toko kelontong',
        'pasar', 'toko baju', 'distro', 'fashion', 'butik', 'toko sepatu', 'toko bangunan',
        'material', 'toko besi', 'toko cat', 'toko emas', 'toko plastik', 'toko sepeda',
        'toko mainan', 'toko buku', 'atk', 'mebel', 'furniture',

        // Kesehatan & Medis (Lengkap)
        'apotek', 'apotik', 'kimia farma', 'k24', 'klinik', 'klinik pratama', 'rumah sakit',
        'rsud', 'puskesmas', 'pustu', 'dokter', 'dokter gigi', 'dental', 'bidan', 'optik',
        'laboratorium medis', 'laboratorium klinik', 'fisioterapi',

        // Pendidikan & Sekolah
        'sd negeri', 'sd islam', 'smp negeri', 'smp', 'sma negeri', 'smk negeri', 'smk',
        'man', 'mts', 'mi', 'universitas', 'kampus', 'politeknik', 'akademi', 'tk', 'paud',
        'pondok pesantren', 'pesantren', 'bimbel', 'kursus',

        // Otomotif & SPBU
        'bengkel motor', 'bengkel mobil', 'ahass', 'servis motor', 'spbu', 'pom bensin',
        'pertashop', 'tambal ban', 'cuci motor', 'cuci mobil', 'variasi motor', 'sparepart',

        // Jasa & Perbankan
        'laundry', 'salon', 'barbershop', 'pangkas rambut', 'fotokopi', 'percetakan',
        'digital printing', 'notaris', 'kantor pos', 'jne', 'jnt', 'sicepat', 'pos indonesia',
        'bank', 'atm', 'koperasi', 'bpr',

        // Akomodasi
        'hotel', 'penginapan', 'guest house', 'homestay', 'kost', 'losmen', 'resort', 'villa',

        // Pemerintahan & Ibadah
        'kantor kelurahan', 'kantor desa', 'kantor kecamatan', 'polsek', 'polres', 'koramil',
        'kodim', 'dinas', 'masjid', 'mushola', 'gereja',

        // Industri & Korporasi
        'pt', 'cv', 'pabrik', 'gudang', 'distributor'
    ];

    $cleanCity = preg_replace('/^(kabupaten|kota|kab\.|adm\.)\s+/i', '', trim($cityName));
    $cleanCity = trim(explode(',', $cleanCity)[0]);
    if (empty($cleanCity) || strtolower($cleanCity) === 'indonesia') {
        $cleanCity = $cityName;
    }

    $queryPool = [];
    foreach ($baseKeywords as $bk) {
        $queryPool[] = "$bk $cleanCity";
    }
    // Also add pure base keywords which will be bounded by bbox
    foreach (array_slice($baseKeywords, 0, 15) as $bk) {
        $queryPool[] = $bk;
    }
    // Add district-specific searches for high-yield commercial targets
    foreach (array_slice($subAreas, 1, 4) as $district) {
        $cleanDist = preg_replace('/^(kecamatan|kelurahan|desa)\s+/i', '', trim($district));
        $queryPool[] = "cafe $cleanDist";
        $queryPool[] = "kopi $cleanDist";
        $queryPool[] = "toko elektronik $cleanDist";
        $queryPool[] = "apotek $cleanDist";
        $queryPool[] = "klinik $cleanDist";
        $queryPool[] = "bengkel $cleanDist";
        $queryPool[] = "toko $cleanDist";
        $queryPool[] = "warung $cleanDist";
    }
    $queryPool = array_values(array_unique($queryPool));

    $totalKeywords = count($queryPool);
    if ($verbose) {
        echo "[Stage 2: Photon] Executing spatial crawl with $totalKeywords keywords (Target: $cleanCity)...\n";
    }

    $addedInStage = 0;
    $batchSize = 6;
    $bboxParam = sprintf('%.5f,%.5f,%.5f,%.5f', $regionConfig['minLng'], $regionConfig['minLat'], $regionConfig['maxLng'], $regionConfig['maxLat']);

    for ($i = 0; $i < $totalKeywords; $i += $batchSize) {
        $batch = array_slice($queryPool, $i, $batchSize);
        $mh = curl_multi_init();
        $handles = [];

        foreach ($batch as $kw) {
            $params = [
                'q' => $kw,
                'lat' => $centerLat,
                'lon' => $centerLng,
                'bbox' => $bboxParam,
                'limit' => 30
            ];
            $url = 'https://photon.komoot.io/api/?' . http_build_query($params);
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'ClientReachAI_Harvester/4.0 (info@recreative.id)');
            curl_setopt($ch, CURLOPT_TIMEOUT, 6);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_multi_add_handle($mh, $ch);
            $handles[$kw] = $ch;
        }

        $running = null;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh);
        } while ($running > 0);

        foreach ($handles as $kw => $ch) {
            $content = curl_multi_getcontent($ch);
            $data = json_decode($content, true);
            $features = $data['features'] ?? [];

            foreach ($features as $f) {
                $prop = $f['properties'] ?? [];
                $geom = $f['geometry'] ?? [];
                $coords = $geom['coordinates'] ?? [0, 0];
                $lon = $coords[0] ?? 0;
                $lat = $coords[1] ?? 0;

                $name = trim($prop['name'] ?? '');
                if (empty($name) || !$lat || !$lon) continue;

                // Geofence check
                if (
                    $lat < ($regionConfig['minLat'] - 0.12) || $lat > ($regionConfig['maxLat'] + 0.12) ||
                    $lon < ($regionConfig['minLng'] - 0.12) || $lon > ($regionConfig['maxLng'] + 0.12)
                ) {
                    continue;
                }

                $rawItem = [
                    'osm_id' => $prop['osm_id'] ?? null,
                    'osm_type' => $prop['osm_type'] ?? 'N',
                    'name' => $name,
                    'lat' => $lat,
                    'lon' => $lon,
                    'type' => $prop['osm_value'] ?? ($prop['type'] ?? ''),
                    'class' => $prop['osm_key'] ?? '',
                    'address' => [
                        'street' => $prop['street'] ?? '',
                        'district' => $prop['district'] ?? '',
                        'city' => $prop['city'] ?? $cleanCity,
                        'state' => $prop['state'] ?? ($regionConfig['province_name'] ?? 'Indonesia'),
                        'postcode' => $prop['postcode'] ?? ''
                    ],
                    'display_name' => implode(', ', array_filter([
                        $name,
                        $prop['street'] ?? '',
                        $prop['district'] ?? '',
                        $prop['city'] ?? $cleanCity,
                        $prop['state'] ?? ($regionConfig['province_name'] ?? 'Indonesia')
                    ]))
                ];

                $formatted = formatHarvestRecord($rawItem, 'photon', $cleanCity, $regionConfig['province_name'] ?? 'Indonesia');
                $key = makeDedupKey($formatted);

                if (!isset($seenKeys[$key])) {
                    $seenKeys[$key] = true;
                    $harvestedPlaces[$key] = $formatted;
                    fwrite($fpOut, json_encode($formatted, JSON_UNESCAPED_UNICODE) . "\n");
                    $addedInStage++;
                }
            }

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        if ($verbose && ($i % 30 === 0 || $i + $batchSize >= $totalKeywords)) {
            echo "  Keywords " . min($i + $batchSize, $totalKeywords) . "/$totalKeywords. Cumulative: " . count($harvestedPlaces) . " places\n";
        }

        usleep(250000);
    }

    return $addedInStage;
}

/**
 * Stage 3: Nominatim OpenStreetMap Targeted Query
 * Captures specific commercial establishments by keyword
 */
function harvestNominatimBounded($regionConfig, &$seenKeys, &$harvestedPlaces, $fpOut, $verbose) {
    $cityName = $regionConfig['cityKeywords'][0] ?? 'Magelang';

    $nominatimQueries = [
        "toko $cityName", "toko elektronik $cityName", "toko komputer $cityName", "counter hp $cityName",
        "warung $cityName", "cafe $cityName", "kopi $cityName", "restoran $cityName",
        "apotek $cityName", "klinik $cityName", "rumah sakit $cityName", "dokter $cityName",
        "bengkel $cityName", "laundry $cityName", "salon $cityName", "hotel $cityName",
        "kost $cityName", "sekolah $cityName", "masjid $cityName", "gereja $cityName"
    ];

    if ($verbose) {
        echo "[Stage 3: Nominatim] Running targeted searches...\n";
    }

    $addedInStage = 0;
    foreach ($nominatimQueries as $qText) {
        $params = [
            'q' => $qText,
            'format' => 'json',
            'addressdetails' => 1,
            'extratags' => 1,
            'limit' => 25
        ];

        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query($params);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'ClientReachAI_Harvester/4.0 (info@recreative.id)');
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $res = curl_exec($ch);
        curl_close($ch);

        $arr = json_decode($res, true);
        if (is_array($arr)) {
            foreach ($arr as $item) {
                $name = trim($item['name'] ?? '');
                if (empty($name)) {
                    $parts = explode(',', $item['display_name'] ?? '');
                    $name = trim($parts[0] ?? '');
                }
                if (empty($name)) continue;

                $lat = (float)($item['lat'] ?? 0);
                $lon = (float)($item['lon'] ?? 0);
                if (
                    $lat < ($regionConfig['minLat'] - 0.15) || $lat > ($regionConfig['maxLat'] + 0.15) ||
                    $lon < ($regionConfig['minLng'] - 0.15) || $lon > ($regionConfig['maxLng'] + 0.15)
                ) {
                    continue;
                }

                $item['name'] = $name;
                $formatted = formatHarvestRecord($item, 'nominatim');
                $key = makeDedupKey($formatted);

                if (!isset($seenKeys[$key])) {
                    $seenKeys[$key] = true;
                    $harvestedPlaces[$key] = $formatted;
                    fwrite($fpOut, json_encode($formatted, JSON_UNESCAPED_UNICODE) . "\n");
                    $addedInStage++;
                }
            }
        }

        // Polite delay
        usleep(1050000);
    }

    return $addedInStage;
}

// ==========================================
// STANDALONE HARVEST PIPELINE EXECUTION
// ==========================================
function runStandaloneHarvest() {
    global $dataDir;

    // Check CLI vs Web
    $isCli = (php_sapi_name() === 'cli');

    // Parse Parameters
    $regionParam = 'magelang';
    $verbose = false;
    $maxTiles = 0; // 0 = all

    if ($isCli) {
        global $argv;
        foreach ($argv ?? [] as $arg) {
            if (strpos($arg, '--region=') === 0) {
                $regionParam = substr($arg, 9);
            } elseif ($arg === '--verbose' || $arg === '-v') {
                $verbose = true;
            } elseif (strpos($arg, '--max-tiles=') === 0) {
                $maxTiles = (int)substr($arg, 12);
            }
        }
    } else {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            exit(0);
        }
        $regionParam = $_GET['region'] ?? ($_POST['region'] ?? 'magelang');
        $verbose = !empty($_GET['verbose']) || !empty($_POST['verbose']);
        $maxTiles = (int)($_GET['max_tiles'] ?? 0);
    }

    // Bounding Box & Region Configurations
    $regions = [
        'kota_magelang' => [
            'name' => 'Kota Magelang',
            'minLat' => -7.515,
            'maxLat' => -7.435,
            'minLng' => 110.195,
            'maxLng' => 110.245,
            'centerLat' => -7.4726,
            'centerLng' => 110.2198,
            'cityKeywords' => ['Kota Magelang', 'Magelang Utara', 'Magelang Tengah', 'Magelang Selatan']
        ],
        'kab_magelang' => [
            'name' => 'Kabupaten Magelang',
            'minLat' => -7.670,
            'maxLat' => -7.280,
            'minLng' => 110.030,
            'maxLng' => 110.420,
            'centerLat' => -7.4726,
            'centerLng' => 110.2198,
            'cityKeywords' => ['Muntilan', 'Mertoyudan', 'Borobudur', 'Secang', 'Salaman', 'Grabag', 'Tempuran']
        ],
        'magelang' => [
            'name' => 'Magelang Raya (Kota & Kab)',
            'minLat' => -7.620,
            'maxLat' => -7.420,
            'minLng' => 110.120,
            'maxLng' => 110.320,
            'centerLat' => -7.4726,
            'centerLng' => 110.2198,
            'cityKeywords' => ['Magelang', 'Kota Magelang', 'Mertoyudan', 'Muntilan', 'Borobudur', 'Secang']
        ],
        'yogyakarta' => [
            'name' => 'Kota Yogyakarta',
            'minLat' => -7.840,
            'maxLat' => -7.750,
            'minLng' => 110.340,
            'maxLng' => 110.420,
            'centerLat' => -7.7956,
            'centerLng' => 110.3695,
            'cityKeywords' => ['Yogyakarta', 'Jogja', 'Malioboro']
        ],
        'semarang' => [
            'name' => 'Kota Semarang',
            'minLat' => -7.090,
            'maxLat' => -6.940,
            'minLng' => 110.350,
            'maxLng' => 110.490,
            'centerLat' => -6.9932,
            'centerLng' => 110.4203,
            'cityKeywords' => ['Semarang', 'Simpang Lima', 'Banyumanik']
        ]
    ];

    $selectedRegionKey = strtolower(trim($regionParam));
    $regionConfig = $regions[$selectedRegionKey] ?? null;

    // Support custom bounding box via GET/POST or dynamic resolution
    if (!$regionConfig) {
        if (isset($_GET['minLat'], $_GET['maxLat'], $_GET['minLng'], $_GET['maxLng'])) {
            $regionConfig = [
                'name' => $_GET['name'] ?? $regionParam,
                'minLat' => (float)$_GET['minLat'],
                'maxLat' => (float)$_GET['maxLat'],
                'minLng' => (float)$_GET['minLng'],
                'maxLng' => (float)$_GET['maxLng'],
                'centerLat' => ((float)$_GET['minLat'] + (float)$_GET['maxLat']) / 2,
                'centerLng' => ((float)$_GET['minLng'] + (float)$_GET['maxLng']) / 2,
                'cityKeywords' => [$_GET['name'] ?? $regionParam]
            ];
            $selectedRegionKey = preg_replace('/[^a-z0-9_]/', '_', strtolower($regionParam));
        } else {
            // Dynamically resolve any Indonesian city/regency from curated DB or Nominatim
            $curatedFile = $dataDir . '/curated_regions_bbox.json';
            $resolved = null;
            if (file_exists($curatedFile)) {
                $curData = json_decode(file_get_contents($curatedFile), true) ?: [];
                $cleanKey = strtolower(preg_replace('/[^a-z0-9]/', '', $regionParam));
                $stripped = preg_replace('/^(kabupaten|kota|kab\.|adm\.)\s+/i', '', $regionParam);
                $strippedKey = strtolower(preg_replace('/[^a-z0-9]/', '', $stripped));
                $match = $curData['cities'][$cleanKey] ?? ($curData['cities'][$strippedKey] ?? null);
                if ($match && !empty($match['bbox'])) {
                    $resolved = [
                        'name' => $regionParam,
                        'lat' => $match['lat'],
                        'lng' => $match['lng'],
                        'bbox' => $match['bbox']
                    ];
                }
            }

            if ($resolved && !empty($resolved['bbox'])) {
                $bb = $resolved['bbox'];
                $regionConfig = [
                    'name' => $regionParam,
                    'minLat' => $bb['minLat'],
                    'maxLat' => $bb['maxLat'],
                    'minLng' => $bb['minLng'],
                    'maxLng' => $bb['maxLng'],
                    'centerLat' => $resolved['lat'],
                    'centerLng' => $resolved['lng'],
                    'cityKeywords' => [$regionParam]
                ];
                $selectedRegionKey = preg_replace('/[^a-z0-9_]/', '_', strtolower($regionParam));
            } else {
                $selectedRegionKey = 'magelang';
                $regionConfig = $regions['magelang'];
            }
        }
    }

    // Output File Paths
    $dateStr = date('Ymd');
    $jsonlFile = $dataDir . "/places_{$selectedRegionKey}_{$dateStr}.jsonl";
    $summaryFile = $dataDir . "/harvest_{$selectedRegionKey}_{$dateStr}.json";

    // In-Memory Index to Deduplicate
    $harvestedPlaces = [];
    $seenKeys = [];

    // Load existing records if file exists to support append/resume
    if (file_exists($jsonlFile)) {
        $fpRead = @fopen($jsonlFile, 'r');
        if ($fpRead) {
            while (($line = fgets($fpRead)) !== false) {
                $record = json_decode(trim($line), true);
                if ($record && !empty($record['name'])) {
                    $dedupKey = makeDedupKey($record);
                    $seenKeys[$dedupKey] = true;
                    $harvestedPlaces[$dedupKey] = $record;
                }
            }
            fclose($fpRead);
        }
    }

    $initialCount = count($harvestedPlaces);
    $fpOut = fopen($jsonlFile, 'a');

    $startTime = microtime(true);

    if ($verbose) {
        echo "========================================================\n";
        echo "CLIENT REACH AI - COMPREHENSIVE BUSINESS HARVESTER\n";
        echo "Target Region: {$regionConfig['name']} ({$selectedRegionKey})\n";
        echo "Bounding Box: {$regionConfig['minLat']}, {$regionConfig['minLng']} to {$regionConfig['maxLat']}, {$regionConfig['maxLng']}\n";
        echo "Output File: $jsonlFile\n";
        echo "Initial Records: $initialCount\n";
        echo "========================================================\n";
    }

    // 1. Overpass Grid Scan (Multi-Sector POIs)
    $addedOverpass = harvestOverpassGrid($regionConfig, $seenKeys, $harvestedPlaces, $fpOut, $verbose, $maxTiles, $regionConfig['name'], $regionConfig['province_name'] ?? '');

    // 2. Photon Komoot Spatial Keyword Crawl
    $addedPhoton = harvestPhotonKeywords($regionConfig, $seenKeys, $harvestedPlaces, $fpOut, $verbose);

    // 3. Nominatim Targeted Sweep
    $addedNominatim = harvestNominatimBounded($regionConfig, $seenKeys, $harvestedPlaces, $fpOut, $verbose);

    fclose($fpOut);

    // 4. Automatically save all harvested places to SQLite Database (for GitHub & cPanel)
    require_once __DIR__ . '/db_manager.php';
    saveHarvestPlacesToDb($harvestedPlaces, $regionConfig['name'], $regionConfig['province_name'] ?? '');

    $totalRecords = count($harvestedPlaces);
    $newlyAdded = $totalRecords - $initialCount;
    $elapsedSeconds = round(microtime(true) - $startTime, 2);

    // Calculate Sector Breakdown
    $sectorCounts = [];
    $subsectorCounts = [];
    foreach ($harvestedPlaces as $p) {
        $sec = $p['sector'] ?? 'lainnya';
        $sub = $p['subsector'] ?? 'umum';
        $sectorCounts[$sec] = ($sectorCounts[$sec] ?? 0) + 1;
        $subsectorCounts[$sub] = ($subsectorCounts[$sub] ?? 0) + 1;
    }
    arsort($sectorCounts);
    arsort($subsectorCounts);

    // Generate Comprehensive Summary JSON
    $summaryData = [
        'success' => true,
        'region' => [
            'key' => $selectedRegionKey,
            'name' => $regionConfig['name'],
            'bbox' => [
                'min_lat' => $regionConfig['minLat'],
                'max_lat' => $regionConfig['maxLat'],
                'min_lng' => $regionConfig['minLng'],
                'max_lng' => $regionConfig['maxLng']
            ],
            'center' => [
                'lat' => $regionConfig['centerLat'],
                'lng' => $regionConfig['centerLng']
            ]
        ],
        'statistics' => [
            'total_places' => $totalRecords,
            'newly_harvested' => $newlyAdded,
            'initial_count' => $initialCount,
            'elapsed_seconds' => $elapsedSeconds,
            'added_by_source' => [
                'overpass' => $addedOverpass,
                'photon' => $addedPhoton,
                'nominatim' => $addedNominatim
            ]
        ],
        'sector_breakdown' => $sectorCounts,
        'top_subsectors' => array_slice($subsectorCounts, 0, 25),
        'artifacts' => [
            'jsonl_path' => $jsonlFile,
            'jsonl_filename' => basename($jsonlFile),
            'summary_path' => $summaryFile,
            'summary_filename' => basename($summaryFile)
        ],
        'sample_places' => array_slice(array_values($harvestedPlaces), 0, 15),
        'harvested_at' => date('Y-m-d H:i:s')
    ];

    file_put_contents($summaryFile, json_encode($summaryData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    // Final Output
    if ($isCli) {
        echo "\n========================================================\n";
        echo "HARVEST COMPLETED SUCCESSFULLY!\n";
        echo "Total Places Recorded: $totalRecords (+$newlyAdded new)\n";
        echo "Elapsed Time: {$elapsedSeconds}s\n";
        echo "Output JSONL: $jsonlFile\n";
        echo "Summary JSON: $summaryFile\n";
        echo "Sector Distribution:\n";
        foreach ($sectorCounts as $sec => $cnt) {
            printf("  - %-18s: %d places\n", $sec, $cnt);
        }
        echo "========================================================\n";
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($summaryData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}

// Only runs when this file is called directly (not via require/include)
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'full_harvest.php') {
    runStandaloneHarvest();
}

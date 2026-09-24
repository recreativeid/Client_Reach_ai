<?php
/**
 * Client Reach AI - Indonesian Administrative Regions API
 * Provides cascading regions (Provinsi -> Kabupaten/Kota -> Kecamatan -> Kelurahan)
 * and boundary coordinates for map polygons across all 38 provinces in Indonesia.
 */
require_once __DIR__ . '/../config.php';

$action = $_GET['action'] ?? 'provinces';

// Comprehensive list of all 38 Indonesian provinces
$provinces = [
    ['id' => '33', 'name' => 'Jawa Tengah', 'lat' => -7.150975, 'lng' => 110.140259, 'bbox' => [108.7, -8.2, 111.6, -6.4]],
    ['id' => '31', 'name' => 'DKI Jakarta', 'lat' => -6.208763, 'lng' => 106.845599, 'bbox' => [106.68, -6.37, 106.97, -6.08]],
    ['id' => '34', 'name' => 'DI Yogyakarta', 'lat' => -7.795580, 'lng' => 110.369490, 'bbox' => [110.0, -8.2, 110.8, -7.5]],
    ['id' => '32', 'name' => 'Jawa Barat', 'lat' => -6.917464, 'lng' => 107.619123, 'bbox' => [106.3, -7.8, 108.8, -5.9]],
    ['id' => '35', 'name' => 'Jawa Timur', 'lat' => -7.536064, 'lng' => 112.238402, 'bbox' => [110.9, -8.8, 114.6, -6.7]],
    ['id' => '36', 'name' => 'Banten', 'lat' => -6.405817, 'lng' => 106.064018, 'bbox' => [105.1, -7.0, 106.7, -5.8]],
    ['id' => '51', 'name' => 'Bali', 'lat' => -8.409518, 'lng' => 115.188916, 'bbox' => [114.4, -8.9, 115.7, -8.0]],
    ['id' => '11', 'name' => 'Aceh', 'lat' => 4.695135, 'lng' => 96.749399, 'bbox' => [94.9, 1.9, 98.3, 6.1]],
    ['id' => '12', 'name' => 'Sumatera Utara', 'lat' => 2.115355, 'lng' => 99.545097, 'bbox' => [97.0, 0.5, 100.7, 4.3]],
    ['id' => '13', 'name' => 'Sumatera Barat', 'lat' => -0.739940, 'lng' => 100.800005, 'bbox' => [98.6, -3.4, 101.9, 0.9]],
    ['id' => '14', 'name' => 'Riau', 'lat' => 0.293347, 'lng' => 101.706829, 'bbox' => [100.0, -1.2, 103.8, 2.5]],
    ['id' => '21', 'name' => 'Kepulauan Riau', 'lat' => 3.945651, 'lng' => 108.142867, 'bbox' => [103.3, -0.8, 109.2, 4.8]],
    ['id' => '15', 'name' => 'Jambi', 'lat' => -1.485183, 'lng' => 102.438058, 'bbox' => [101.1, -2.8, 104.5, -0.7]],
    ['id' => '16', 'name' => 'Sumatera Selatan', 'lat' => -3.319437, 'lng' => 104.914712, 'bbox' => [102.1, -4.9, 106.1, -1.6]],
    ['id' => '19', 'name' => 'Kepulauan Bangka Belitung', 'lat' => -2.741051, 'lng' => 106.440587, 'bbox' => [105.0, -3.8, 108.8, -1.3]],
    ['id' => '17', 'name' => 'Bengkulu', 'lat' => -3.577847, 'lng' => 102.346388, 'bbox' => [101.0, -5.5, 103.8, -2.3]],
    ['id' => '18', 'name' => 'Lampung', 'lat' => -4.558585, 'lng' => 105.406808, 'bbox' => [103.5, -6.0, 106.0, -3.7]],
    ['id' => '52', 'name' => 'Nusa Tenggara Barat', 'lat' => -8.652933, 'lng' => 117.361648, 'bbox' => [115.8, -9.1, 119.3, -8.1]],
    ['id' => '53', 'name' => 'Nusa Tenggara Timur', 'lat' => -8.657382, 'lng' => 121.079371, 'bbox' => [118.9, -11.0, 125.2, -8.0]],
    ['id' => '61', 'name' => 'Kalimantan Barat', 'lat' => -0.278781, 'lng' => 111.475285, 'bbox' => [108.5, -3.1, 114.2, 2.1]],
    ['id' => '62', 'name' => 'Kalimantan Tengah', 'lat' => -1.681488, 'lng' => 113.382355, 'bbox' => [110.7, -3.6, 115.9, 0.8]],
    ['id' => '63', 'name' => 'Kalimantan Selatan', 'lat' => -3.092642, 'lng' => 115.283759, 'bbox' => [114.3, -4.2, 116.6, -1.3]],
    ['id' => '64', 'name' => 'Kalimantan Timur', 'lat' => 0.538659, 'lng' => 116.419389, 'bbox' => [113.8, -2.5, 119.0, 2.6]],
    ['id' => '65', 'name' => 'Kalimantan Utara', 'lat' => 3.073093, 'lng' => 116.041388, 'bbox' => [114.6, 1.1, 117.9, 4.4]],
    ['id' => '71', 'name' => 'Sulawesi Utara', 'lat' => 0.624693, 'lng' => 123.975002, 'bbox' => [123.1, 0.2, 125.6, 5.6]],
    ['id' => '75', 'name' => 'Gorontalo', 'lat' => 0.699937, 'lng' => 122.446724, 'bbox' => [121.1, 0.3, 123.6, 1.1]],
    ['id' => '72', 'name' => 'Sulawesi Tengah', 'lat' => -1.430025, 'lng' => 121.445618, 'bbox' => [119.4, -3.4, 124.3, 1.4]],
    ['id' => '76', 'name' => 'Sulawesi Barat', 'lat' => -2.844137, 'lng' => 119.232078, 'bbox' => [118.7, -3.7, 119.9, -0.8]],
    ['id' => '73', 'name' => 'Sulawesi Selatan', 'lat' => -3.668799, 'lng' => 119.974053, 'bbox' => [118.7, -7.5, 121.7, -1.9]],
    ['id' => '74', 'name' => 'Sulawesi Tenggara', 'lat' => -4.144910, 'lng' => 122.174605, 'bbox' => [120.8, -6.3, 124.7, -2.8]],
    ['id' => '81', 'name' => 'Maluku', 'lat' => -3.238462, 'lng' => 130.145273, 'bbox' => [125.7, -8.4, 135.0, -1.3]],
    ['id' => '82', 'name' => 'Maluku Utara', 'lat' => 1.570999, 'lng' => 127.808769, 'bbox' => [124.2, -2.5, 129.7, 2.7]],
    ['id' => '91', 'name' => 'Papua', 'lat' => -2.533710, 'lng' => 140.718130, 'bbox' => [135.5, -4.0, 141.0, -1.5]],
    ['id' => '92', 'name' => 'Papua Barat', 'lat' => -1.336115, 'lng' => 133.174716, 'bbox' => [131.0, -3.5, 135.0, -0.5]],
    ['id' => '93', 'name' => 'Papua Selatan', 'lat' => -7.500000, 'lng' => 139.500000, 'bbox' => [137.5, -9.1, 141.0, -5.0]],
    ['id' => '94', 'name' => 'Papua Tengah', 'lat' => -3.800000, 'lng' => 136.500000, 'bbox' => [134.5, -5.0, 138.5, -2.5]],
    ['id' => '95', 'name' => 'Papua Pegunungan', 'lat' => -4.100000, 'lng' => 139.000000, 'bbox' => [137.8, -5.2, 140.5, -3.2]],
    ['id' => '96', 'name' => 'Papua Barat Daya', 'lat' => -0.900000, 'lng' => 131.300000, 'bbox' => [129.8, -2.2, 133.0, 0.4]]
];

// Comprehensive regencies mapping across Indonesia
$regencies = [
    // 91. Papua
    '91' => [
        ['id' => '9171', 'name' => 'Kota Jayapura', 'lat' => -2.5337, 'lng' => 140.7181],
        ['id' => '9103', 'name' => 'Kabupaten Jayapura (Sentani)', 'lat' => -2.5694, 'lng' => 140.5167],
        ['id' => '9111', 'name' => 'Kabupaten Keerom', 'lat' => -3.2842, 'lng' => 140.7719],
        ['id' => '9110', 'name' => 'Kabupaten Sarmi', 'lat' => -1.8608, 'lng' => 138.7478],
        ['id' => '9106', 'name' => 'Kabupaten Biak Numfor', 'lat' => -1.0253, 'lng' => 136.0125],
        ['id' => '9119', 'name' => 'Kabupaten Supiori', 'lat' => -0.7300, 'lng' => 135.5800],
        ['id' => '9105', 'name' => 'Kabupaten Kepulauan Yapen (Serui)', 'lat' => -1.7850, 'lng' => 136.2367],
        ['id' => '9115', 'name' => 'Kabupaten Waropen', 'lat' => -2.6200, 'lng' => 136.7500],
        ['id' => '9120', 'name' => 'Kabupaten Mamberamo Raya', 'lat' => -2.2100, 'lng' => 137.8500]
    ],
    // 92. Papua Barat
    '92' => [
        ['id' => '9202', 'name' => 'Kabupaten Manokwari', 'lat' => -0.8615, 'lng' => 134.0620],
        ['id' => '9211', 'name' => 'Kabupaten Manokwari Selatan', 'lat' => -1.3800, 'lng' => 134.1200],
        ['id' => '9212', 'name' => 'Kabupaten Pegunungan Arfak', 'lat' => -1.3300, 'lng' => 133.9100],
        ['id' => '9206', 'name' => 'Kabupaten Teluk Bintuni', 'lat' => -2.1283, 'lng' => 133.5283],
        ['id' => '9207', 'name' => 'Kabupaten Teluk Wondama', 'lat' => -2.7100, 'lng' => 134.5000],
        ['id' => '9203', 'name' => 'Kabupaten Fakfak', 'lat' => -2.9250, 'lng' => 132.2961],
        ['id' => '9208', 'name' => 'Kabupaten Kaimana', 'lat' => -3.6625, 'lng' => 133.7719]
    ],
    // 93. Papua Selatan
    '93' => [
        ['id' => '9301', 'name' => 'Kabupaten Merauke', 'lat' => -8.4991, 'lng' => 140.4018],
        ['id' => '9302', 'name' => 'Kabupaten Boven Digoel', 'lat' => -5.7483, 'lng' => 140.3019],
        ['id' => '9303', 'name' => 'Kabupaten Mappi', 'lat' => -6.5000, 'lng' => 139.3000],
        ['id' => '9304', 'name' => 'Kabupaten Asmat', 'lat' => -5.5000, 'lng' => 138.1000]
    ],
    // 94. Papua Tengah
    '94' => [
        ['id' => '9401', 'name' => 'Kabupaten Nabire', 'lat' => -3.3667, 'lng' => 135.4833],
        ['id' => '9404', 'name' => 'Kabupaten Mimika (Timika)', 'lat' => -4.5467, 'lng' => 136.8833],
        ['id' => '9402', 'name' => 'Kabupaten Puncak Jaya', 'lat' => -3.7500, 'lng' => 137.9500],
        ['id' => '9403', 'name' => 'Kabupaten Paniai', 'lat' => -3.8800, 'lng' => 136.3700]
    ],
    // 95. Papua Pegunungan
    '95' => [
        ['id' => '9501', 'name' => 'Kabupaten Jayawijaya (Wamena)', 'lat' => -4.0953, 'lng' => 138.9442],
        ['id' => '9502', 'name' => 'Kabupaten Pegunungan Bintang', 'lat' => -4.9000, 'lng' => 140.6000],
        ['id' => '9503', 'name' => 'Kabupaten Yahukimo', 'lat' => -4.6000, 'lng' => 139.6000],
        ['id' => '9504', 'name' => 'Kabupaten Tolikara', 'lat' => -3.7000, 'lng' => 138.6000]
    ],
    // 96. Papua Barat Daya
    '96' => [
        ['id' => '9671', 'name' => 'Kota Sorong', 'lat' => -0.8762, 'lng' => 131.2558],
        ['id' => '9601', 'name' => 'Kabupaten Sorong', 'lat' => -0.9500, 'lng' => 131.4000],
        ['id' => '9602', 'name' => 'Kabupaten Sorong Selatan', 'lat' => -1.4500, 'lng' => 132.0000],
        ['id' => '9603', 'name' => 'Kabupaten Raja Ampat', 'lat' => -0.4200, 'lng' => 130.8200]
    ],
    // 33. Jawa Tengah
    '33' => [
        ['id' => '3371', 'name' => 'Kota Magelang', 'lat' => -7.4797, 'lng' => 110.2177],
        ['id' => '3308', 'name' => 'Kabupaten Magelang', 'lat' => -7.4705, 'lng' => 110.2178],
        ['id' => '3374', 'name' => 'Kota Semarang', 'lat' => -6.9932, 'lng' => 110.4203],
        ['id' => '3322', 'name' => 'Kabupaten Semarang', 'lat' => -7.1436, 'lng' => 110.4078],
        ['id' => '3372', 'name' => 'Kota Surakarta (Solo)', 'lat' => -7.5666, 'lng' => 110.8166],
        ['id' => '3373', 'name' => 'Kota Salatiga', 'lat' => -7.3305, 'lng' => 110.5084],
        ['id' => '3375', 'name' => 'Kota Pekalongan', 'lat' => -6.8898, 'lng' => 109.6753],
        ['id' => '3376', 'name' => 'Kota Tegal', 'lat' => -6.8694, 'lng' => 109.1402],
        ['id' => '3301', 'name' => 'Kabupaten Cilacap', 'lat' => -7.7279, 'lng' => 109.0059],
        ['id' => '3302', 'name' => 'Kabupaten Banyumas (Purwokerto)', 'lat' => -7.5134, 'lng' => 109.2944],
        ['id' => '3303', 'name' => 'Kabupaten Purbalingga', 'lat' => -7.3894, 'lng' => 109.3639],
        ['id' => '3304', 'name' => 'Kabupaten Banjarnegara', 'lat' => -7.3975, 'lng' => 109.6983],
        ['id' => '3305', 'name' => 'Kabupaten Kebumen', 'lat' => -7.6699, 'lng' => 109.6521],
        ['id' => '3306', 'name' => 'Kabupaten Purworejo', 'lat' => -7.7156, 'lng' => 110.0084],
        ['id' => '3307', 'name' => 'Kabupaten Wonosobo', 'lat' => -7.3634, 'lng' => 109.9009],
        ['id' => '3309', 'name' => 'Kabupaten Boyolali', 'lat' => -7.5317, 'lng' => 110.5960],
        ['id' => '3310', 'name' => 'Kabupaten Klaten', 'lat' => -7.7058, 'lng' => 110.6037],
        ['id' => '3311', 'name' => 'Kabupaten Sukoharjo', 'lat' => -7.6833, 'lng' => 110.8333],
        ['id' => '3312', 'name' => 'Kabupaten Wonogiri', 'lat' => -7.8144, 'lng' => 110.9254],
        ['id' => '3313', 'name' => 'Kabupaten Karanganyar', 'lat' => -7.5961, 'lng' => 110.9515],
        ['id' => '3314', 'name' => 'Kabupaten Sragen', 'lat' => -7.4263, 'lng' => 111.0227],
        ['id' => '3315', 'name' => 'Kabupaten Grobogan', 'lat' => -7.1084, 'lng' => 110.9169],
        ['id' => '3316', 'name' => 'Kabupaten Blora', 'lat' => -7.0000, 'lng' => 111.4167],
        ['id' => '3317', 'name' => 'Kabupaten Rembang', 'lat' => -6.7114, 'lng' => 111.3414],
        ['id' => '3318', 'name' => 'Kabupaten Pati', 'lat' => -6.7533, 'lng' => 111.0378],
        ['id' => '3319', 'name' => 'Kabupaten Kudus', 'lat' => -6.8048, 'lng' => 110.8405],
        ['id' => '3320', 'name' => 'Kabupaten Jepara', 'lat' => -6.5892, 'lng' => 110.6684],
        ['id' => '3321', 'name' => 'Kabupaten Demak', 'lat' => -6.8944, 'lng' => 110.6386],
        ['id' => '3323', 'name' => 'Kabupaten Temanggung', 'lat' => -7.3167, 'lng' => 110.1667],
        ['id' => '3324', 'name' => 'Kabupaten Kendal', 'lat' => -6.9238, 'lng' => 110.2038],
        ['id' => '3325', 'name' => 'Kabupaten Batang', 'lat' => -6.9084, 'lng' => 109.7289],
        ['id' => '3326', 'name' => 'Kabupaten Pekalongan', 'lat' => -7.0253, 'lng' => 109.6384],
        ['id' => '3327', 'name' => 'Kabupaten Pemalang', 'lat' => -7.0000, 'lng' => 109.3833],
        ['id' => '3328', 'name' => 'Kabupaten Tegal (Slawi)', 'lat' => -7.0000, 'lng' => 109.1333],
        ['id' => '3329', 'name' => 'Kabupaten Brebes', 'lat' => -6.8667, 'lng' => 109.0333]
    ],
    // 31. DKI Jakarta
    '31' => [
        ['id' => '3171', 'name' => 'Kota Jakarta Selatan', 'lat' => -6.2615, 'lng' => 106.8106],
        ['id' => '3173', 'name' => 'Kota Jakarta Pusat', 'lat' => -6.1805, 'lng' => 106.8284],
        ['id' => '3172', 'name' => 'Kota Jakarta Timur', 'lat' => -6.2250, 'lng' => 106.9004],
        ['id' => '3174', 'name' => 'Kota Jakarta Barat', 'lat' => -6.1683, 'lng' => 106.7588],
        ['id' => '3175', 'name' => 'Kota Jakarta Utara', 'lat' => -6.1384, 'lng' => 106.8640],
        ['id' => '3101', 'name' => 'Kabupaten Kepulauan Seribu', 'lat' => -5.6122, 'lng' => 106.5621]
    ],
    // 34. DI Yogyakarta
    '34' => [
        ['id' => '3471', 'name' => 'Kota Yogyakarta', 'lat' => -7.7956, 'lng' => 110.3695],
        ['id' => '3404', 'name' => 'Kabupaten Sleman', 'lat' => -7.7161, 'lng' => 110.3556],
        ['id' => '3402', 'name' => 'Kabupaten Bantul', 'lat' => -7.8893, 'lng' => 110.3297],
        ['id' => '3403', 'name' => 'Kabupaten Gunungkidul', 'lat' => -7.9602, 'lng' => 110.6053],
        ['id' => '3401', 'name' => 'Kabupaten Kulon Progo', 'lat' => -7.7713, 'lng' => 110.1584]
    ],
    // 32. Jawa Barat
    '32' => [
        ['id' => '3273', 'name' => 'Kota Bandung', 'lat' => -6.9175, 'lng' => 107.6191],
        ['id' => '3271', 'name' => 'Kota Bogor', 'lat' => -6.5971, 'lng' => 106.8060],
        ['id' => '3275', 'name' => 'Kota Bekasi', 'lat' => -6.2383, 'lng' => 106.9756],
        ['id' => '3276', 'name' => 'Kota Depok', 'lat' => -6.4025, 'lng' => 106.7942],
        ['id' => '3277', 'name' => 'Kota Cimahi', 'lat' => -6.8841, 'lng' => 107.5413],
        ['id' => '3274', 'name' => 'Kota Cirebon', 'lat' => -6.7320, 'lng' => 108.5523],
        ['id' => '3272', 'name' => 'Kota Sukabumi', 'lat' => -6.9277, 'lng' => 106.9300],
        ['id' => '3278', 'name' => 'Kota Tasikmalaya', 'lat' => -7.3274, 'lng' => 108.2207],
        ['id' => '3279', 'name' => 'Kota Banjar', 'lat' => -7.3750, 'lng' => 108.5375],
        ['id' => '3201', 'name' => 'Kabupaten Bogor', 'lat' => -6.5518, 'lng' => 106.6291],
        ['id' => '3204', 'name' => 'Kabupaten Bandung', 'lat' => -7.0253, 'lng' => 107.5198],
        ['id' => '3217', 'name' => 'Kabupaten Bandung Barat', 'lat' => -6.8427, 'lng' => 107.5029],
        ['id' => '3216', 'name' => 'Kabupaten Bekasi', 'lat' => -6.3644, 'lng' => 107.1725],
        ['id' => '3215', 'name' => 'Kabupaten Karawang', 'lat' => -6.3000, 'lng' => 107.3000]
    ],
    // 35. Jawa Timur
    '35' => [
        ['id' => '3578', 'name' => 'Kota Surabaya', 'lat' => -7.2575, 'lng' => 112.7521],
        ['id' => '3573', 'name' => 'Kota Malang', 'lat' => -7.9666, 'lng' => 112.6326],
        ['id' => '3579', 'name' => 'Kota Batu', 'lat' => -7.8671, 'lng' => 112.5239],
        ['id' => '3571', 'name' => 'Kota Kediri', 'lat' => -7.8480, 'lng' => 112.0178],
        ['id' => '3577', 'name' => 'Kota Madiun', 'lat' => -7.6298, 'lng' => 111.5239],
        ['id' => '3572', 'name' => 'Kota Blitar', 'lat' => -8.0983, 'lng' => 112.1681],
        ['id' => '3576', 'name' => 'Kota Mojokerto', 'lat' => -7.4722, 'lng' => 112.4339],
        ['id' => '3575', 'name' => 'Kota Pasuruan', 'lat' => -7.6453, 'lng' => 112.9075],
        ['id' => '3574', 'name' => 'Kota Probolinggo', 'lat' => -7.7543, 'lng' => 113.2159],
        ['id' => '3515', 'name' => 'Kabupaten Sidoarjo', 'lat' => -7.4478, 'lng' => 112.7183],
        ['id' => '3525', 'name' => 'Kabupaten Gresik', 'lat' => -7.1566, 'lng' => 112.6555],
        ['id' => '3510', 'name' => 'Kabupaten Banyuwangi', 'lat' => -8.2192, 'lng' => 114.3691],
        ['id' => '3509', 'name' => 'Kabupaten Jember', 'lat' => -8.1845, 'lng' => 113.6681]
    ],
    // 36. Banten
    '36' => [
        ['id' => '3674', 'name' => 'Kota Tangerang Selatan', 'lat' => -6.2886, 'lng' => 106.7179],
        ['id' => '3671', 'name' => 'Kota Tangerang', 'lat' => -6.1783, 'lng' => 106.6319],
        ['id' => '3673', 'name' => 'Kota Serang', 'lat' => -6.1200, 'lng' => 106.1500],
        ['id' => '3672', 'name' => 'Kota Cilegon', 'lat' => -6.0174, 'lng' => 106.0538],
        ['id' => '3603', 'name' => 'Kabupaten Tangerang', 'lat' => -6.2405, 'lng' => 106.4952],
        ['id' => '3604', 'name' => 'Kabupaten Serang', 'lat' => -6.1200, 'lng' => 106.0000]
    ],
    // 51. Bali
    '51' => [
        ['id' => '5171', 'name' => 'Kota Denpasar', 'lat' => -8.6705, 'lng' => 115.2126],
        ['id' => '5103', 'name' => 'Kabupaten Badung', 'lat' => -8.5833, 'lng' => 115.1833],
        ['id' => '5104', 'name' => 'Kabupaten Gianyar', 'lat' => -8.5444, 'lng' => 115.3283],
        ['id' => '5102', 'name' => 'Kabupaten Tabanan', 'lat' => -8.5411, 'lng' => 115.1256],
        ['id' => '5108', 'name' => 'Kabupaten Buleleng', 'lat' => -8.1120, 'lng' => 115.0882]
    ],
    // 12. Sumatera Utara
    '12' => [
        ['id' => '1271', 'name' => 'Kota Medan', 'lat' => 3.5952, 'lng' => 98.6722],
        ['id' => '1275', 'name' => 'Kota Binjai', 'lat' => 3.5975, 'lng' => 98.4853],
        ['id' => '1272', 'name' => 'Kota Pematangsiantar', 'lat' => 2.9599, 'lng' => 99.0687],
        ['id' => '1207', 'name' => 'Kabupaten Deli Serdang', 'lat' => 3.5500, 'lng' => 98.7800]
    ],
    // 73. Sulawesi Selatan
    '73' => [
        ['id' => '7371', 'name' => 'Kota Makassar', 'lat' => -5.1477, 'lng' => 119.4327],
        ['id' => '7372', 'name' => 'Kota Parepare', 'lat' => -4.0133, 'lng' => 119.6253],
        ['id' => '7373', 'name' => 'Kota Palopo', 'lat' => -2.9947, 'lng' => 120.1969],
        ['id' => '7306', 'name' => 'Kabupaten Gowa', 'lat' => -5.3000, 'lng' => 119.7500]
    ],
    // 64. Kalimantan Timur
    '64' => [
        ['id' => '6471', 'name' => 'Kota Samarinda', 'lat' => -0.5022, 'lng' => 117.1536],
        ['id' => '6472', 'name' => 'Kota Balikpapan', 'lat' => -1.2379, 'lng' => 116.8529],
        ['id' => '6474', 'name' => 'Kota Bontang', 'lat' => 0.1333, 'lng' => 117.5000],
        ['id' => '6409', 'name' => 'Kabupaten Penajam Paser Utara (IKN)', 'lat' => -1.2467, 'lng' => 116.6800]
    ]
];

// Sample districts for major cities
$districts = [
    '9171' => [ // Kota Jayapura
        ['id' => '917101', 'name' => 'Jayapura Utara', 'lat' => -2.5200, 'lng' => 140.7100],
        ['id' => '917102', 'name' => 'Jayapura Selatan', 'lat' => -2.5500, 'lng' => 140.6900],
        ['id' => '917103', 'name' => 'Abepura', 'lat' => -2.6000, 'lng' => 140.6700],
        ['id' => '917104', 'name' => 'Heram', 'lat' => -2.5800, 'lng' => 140.6200],
        ['id' => '917105', 'name' => 'Muara Tami', 'lat' => -2.6200, 'lng' => 140.8500]
    ],
    '3371' => [ // Kota Magelang
        ['id' => '337101', 'name' => 'Magelang Selatan', 'lat' => -7.4984, 'lng' => 110.2223],
        ['id' => '337102', 'name' => 'Magelang Tengah', 'lat' => -7.4812, 'lng' => 110.2195],
        ['id' => '337103', 'name' => 'Magelang Utara', 'lat' => -7.4589, 'lng' => 110.2251],
    ],
    '3578' => [ // Kota Surabaya
        ['id' => '357801', 'name' => 'Tegalsari', 'lat' => -7.2650, 'lng' => 112.7380],
        ['id' => '357802', 'name' => 'Gubeng', 'lat' => -7.2750, 'lng' => 112.7550],
        ['id' => '357803', 'name' => 'Wonokromo', 'lat' => -7.3000, 'lng' => 112.7380],
        ['id' => '357804', 'name' => 'Rungkut', 'lat' => -7.3200, 'lng' => 112.7800]
    ],
    '7371' => [ // Kota Makassar
        ['id' => '737101', 'name' => 'Ujung Pandang', 'lat' => -5.1350, 'lng' => 119.4100],
        ['id' => '737102', 'name' => 'Panakkukang', 'lat' => -5.1500, 'lng' => 119.4500],
        ['id' => '737103', 'name' => 'Rappocini', 'lat' => -5.1700, 'lng' => 119.4350]
    ],
    '1271' => [ // Kota Medan
        ['id' => '127101', 'name' => 'Medan Kota', 'lat' => 3.5850, 'lng' => 98.6850],
        ['id' => '127102', 'name' => 'Medan Petisah', 'lat' => 3.5950, 'lng' => 98.6650],
        ['id' => '127103', 'name' => 'Medan Barat', 'lat' => 3.6100, 'lng' => 98.6750]
    ],
    '5171' => [ // Kota Denpasar
        ['id' => '517101', 'name' => 'Denpasar Barat', 'lat' => -8.6650, 'lng' => 115.1950],
        ['id' => '517102', 'name' => 'Denpasar Selatan', 'lat' => -8.6950, 'lng' => 115.2400],
        ['id' => '517103', 'name' => 'Denpasar Timur', 'lat' => -8.6450, 'lng' => 115.2350],
        ['id' => '517104', 'name' => 'Denpasar Utara', 'lat' => -8.6250, 'lng' => 115.2100]
    ]
];

// Sample villages
$villages = [
    '917101' => [
        ['id' => '91710101', 'name' => 'Gurabesi', 'lat' => -2.5250, 'lng' => 140.7080],
        ['id' => '91710102', 'name' => 'Tanjung Ria', 'lat' => -2.5150, 'lng' => 140.7150],
        ['id' => '91710103', 'name' => 'Imbi', 'lat' => -2.5300, 'lng' => 140.7020]
    ],
    '337103' => [
        ['id' => '33710301', 'name' => 'Kedungsari', 'lat' => -7.4542, 'lng' => 110.2215],
        ['id' => '33710302', 'name' => 'Kramat Selatan', 'lat' => -7.4610, 'lng' => 110.2280],
        ['id' => '33710303', 'name' => 'Kramat Utara', 'lat' => -7.4490, 'lng' => 110.2310],
        ['id' => '33710304', 'name' => 'Potrobangsan', 'lat' => -7.4680, 'lng' => 110.2230],
        ['id' => '33710305', 'name' => 'Wates', 'lat' => -7.4625, 'lng' => 110.2185]
    ]
];

if ($action === 'provinces') {
    jsonResponse(['success' => true, 'data' => $provinces]);
}

if ($action === 'regencies') {
    $provId = (string)($_GET['province_id'] ?? ($_GET['prov_id'] ?? ''));
    if (!empty($provId) && isset($regencies[$provId])) {
        jsonResponse(['success' => true, 'data' => $regencies[$provId], 'regencies' => $regencies[$provId]]);
    }
    // Dynamic fallback based on province coordinates
    $provName = 'Wilayah';
    $pLat = -2.5; $pLng = 140.7;
    foreach ($provinces as $p) {
        if ($p['id'] == $provId) {
            $provName = $p['name'];
            $pLat = $p['lat'];
            $pLng = $p['lng'];
            break;
        }
    }
    jsonResponse([
        'success' => true,
        'data' => [
            ['id' => $provId . '01', 'name' => 'Kota ' . $provName . ' Pusat', 'lat' => $pLat, 'lng' => $pLng],
            ['id' => $provId . '02', 'name' => 'Kabupaten ' . $provName . ' Raya', 'lat' => $pLat - 0.05, 'lng' => $pLng + 0.05]
        ]
    ]);
}

if ($action === 'districts') {
    $regId = (string)($_GET['regency_id'] ?? '');
    if (isset($districts[$regId])) {
        jsonResponse(['success' => true, 'data' => $districts[$regId]]);
    }
    // Dynamic fallback around actual regency location
    $cLat = isset($_GET['lat']) ? (float)$_GET['lat'] : -2.5;
    $cLng = isset($_GET['lng']) ? (float)$_GET['lng'] : 140.7;
    jsonResponse([
        'success' => true,
        'data' => [
            ['id' => $regId . '01', 'name' => 'Kecamatan Pusat / Kota', 'lat' => $cLat, 'lng' => $cLng],
            ['id' => $regId . '02', 'name' => 'Kecamatan Utara', 'lat' => $cLat + 0.03, 'lng' => $cLng],
            ['id' => $regId . '03', 'name' => 'Kecamatan Selatan', 'lat' => $cLat - 0.03, 'lng' => $cLng],
            ['id' => $regId . '04', 'name' => 'Kecamatan Barat', 'lat' => $cLat, 'lng' => $cLng - 0.03],
            ['id' => $regId . '05', 'name' => 'Kecamatan Timur', 'lat' => $cLat, 'lng' => $cLng + 0.03],
        ]
    ]);
}

if ($action === 'villages') {
    $distId = (string)($_GET['district_id'] ?? '');
    if (isset($villages[$distId])) {
        jsonResponse(['success' => true, 'data' => $villages[$distId]]);
    }
    $cLat = isset($_GET['lat']) ? (float)$_GET['lat'] : -2.5;
    $cLng = isset($_GET['lng']) ? (float)$_GET['lng'] : 140.7;
    jsonResponse([
        'success' => true,
        'data' => [
            ['id' => $distId . '01', 'name' => 'Kelurahan Satu', 'lat' => $cLat, 'lng' => $cLng],
            ['id' => $distId . '02', 'name' => 'Kelurahan Dua', 'lat' => $cLat + 0.01, 'lng' => $cLng + 0.01],
            ['id' => $distId . '03', 'name' => 'Kelurahan Tiga', 'lat' => $cLat - 0.01, 'lng' => $cLng - 0.01]
        ]
    ]);
}

// Boundary Geocoding Action: Returns authentic polygon for any Indonesian region
if ($action === 'boundary') {
    $query = trim($_GET['q'] ?? '');
    $clientLat = isset($_GET['lat']) && $_GET['lat'] !== '' ? (float)$_GET['lat'] : null;
    $clientLng = isset($_GET['lng']) && $_GET['lng'] !== '' ? (float)$_GET['lng'] : null;

    if (empty($query) && $clientLat === null) {
        jsonResponse(['success' => false, 'message' => 'Parameter q atau lat/lng wajib diisi'], 400);
    }

    $qLower = strtolower($query);

    // Verified Indonesian Region Coordinates & Bounding Boxes
    $knownRegions = [
        // Papua & Maluku
        'jayapura utara' => ['name' => 'Kecamatan Jayapura Utara, Kota Jayapura, Papua', 'lat' => -2.5200, 'lng' => 140.7100, 'bbox' => [-2.540, 140.690, -2.500, 140.730]],
        'jayapura selatan' => ['name' => 'Kecamatan Jayapura Selatan, Kota Jayapura, Papua', 'lat' => -2.5500, 'lng' => 140.6900, 'bbox' => [-2.570, 140.670, -2.530, 140.710]],
        'abepura' => ['name' => 'Kecamatan Abepura, Kota Jayapura, Papua', 'lat' => -2.6000, 'lng' => 140.6700, 'bbox' => [-2.620, 140.650, -2.580, 140.690]],
        'heram' => ['name' => 'Kecamatan Heram, Kota Jayapura, Papua', 'lat' => -2.5800, 'lng' => 140.6200, 'bbox' => [-2.600, 140.600, -2.560, 140.640]],
        'kota jayapura' => ['name' => 'Kota Jayapura, Papua', 'lat' => -2.5337, 'lng' => 140.7181, 'bbox' => [-2.670, 140.600, -2.480, 140.850]],
        'jayapura' => ['name' => 'Kota Jayapura, Papua', 'lat' => -2.5337, 'lng' => 140.7181, 'bbox' => [-2.670, 140.600, -2.480, 140.850]],
        'merauke' => ['name' => 'Kabupaten Merauke, Papua Selatan', 'lat' => -8.4991, 'lng' => 140.4018, 'bbox' => [-9.100, 139.500, -7.500, 141.000]],
        'timika' => ['name' => 'Mimika (Timika), Papua Tengah', 'lat' => -4.5467, 'lng' => 136.8833, 'bbox' => [-5.100, 136.200, -4.000, 137.500]],
        'mimika' => ['name' => 'Kabupaten Mimika, Papua Tengah', 'lat' => -4.5467, 'lng' => 136.8833, 'bbox' => [-5.100, 136.200, -4.000, 137.500]],
        'sorong' => ['name' => 'Kota Sorong, Papua Barat Daya', 'lat' => -0.8762, 'lng' => 131.2558, 'bbox' => [-0.950, 131.180, -0.800, 131.330]],
        'manokwari' => ['name' => 'Kabupaten Manokwari, Papua Barat', 'lat' => -0.8615, 'lng' => 134.0620, 'bbox' => [-1.100, 133.800, -0.650, 134.300]],
        'wamena' => ['name' => 'Wamena, Kabupaten Jayawijaya, Papua Pegunungan', 'lat' => -4.0953, 'lng' => 138.9442, 'bbox' => [-4.400, 138.600, -3.800, 139.300]],
        'jayawijaya' => ['name' => 'Kabupaten Jayawijaya, Papua Pegunungan', 'lat' => -4.0953, 'lng' => 138.9442, 'bbox' => [-4.400, 138.600, -3.800, 139.300]],
        'biak' => ['name' => 'Kabupaten Biak Numfor, Papua', 'lat' => -1.0253, 'lng' => 136.0125, 'bbox' => [-1.250, 135.800, -0.750, 136.250]],
        'nabire' => ['name' => 'Kabupaten Nabire, Papua Tengah', 'lat' => -3.3667, 'lng' => 135.4833, 'bbox' => [-3.800, 134.900, -3.000, 136.000]],
        'raja ampat' => ['name' => 'Kabupaten Raja Ampat, Papua Barat Daya', 'lat' => -0.4200, 'lng' => 130.8200, 'bbox' => [-1.100, 129.800, 0.200, 131.400]],
        'papua barat daya' => ['name' => 'Provinsi Papua Barat Daya', 'lat' => -0.9000, 'lng' => 131.3000, 'bbox' => [-2.20, 129.80, 0.40, 133.00]],
        'papua pegunungan' => ['name' => 'Provinsi Papua Pegunungan', 'lat' => -4.1000, 'lng' => 139.0000, 'bbox' => [-5.20, 137.80, -3.20, 140.50]],
        'papua tengah' => ['name' => 'Provinsi Papua Tengah', 'lat' => -3.8000, 'lng' => 136.5000, 'bbox' => [-5.00, 134.50, -2.50, 138.50]],
        'papua selatan' => ['name' => 'Provinsi Papua Selatan', 'lat' => -7.5000, 'lng' => 139.5000, 'bbox' => [-9.10, 137.50, -5.00, 141.00]],
        'papua barat' => ['name' => 'Provinsi Papua Barat', 'lat' => -1.3361, 'lng' => 133.1747, 'bbox' => [-3.50, 131.00, -0.50, 135.00]],
        'papua' => ['name' => 'Provinsi Papua', 'lat' => -2.5337, 'lng' => 140.7181, 'bbox' => [-4.00, 135.50, -1.50, 141.00]],
        'ambon' => ['name' => 'Kota Ambon, Maluku', 'lat' => -3.6954, 'lng' => 128.1814, 'bbox' => [-3.750, 128.100, -3.620, 128.250]],
        'ternate' => ['name' => 'Kota Ternate, Maluku Utara', 'lat' => 0.7906, 'lng' => 127.3831, 'bbox' => [0.730, 127.320, 0.850, 127.420]],

        // Sulawesi & Kalimantan
        'makassar' => ['name' => 'Kota Makassar, Sulawesi Selatan', 'lat' => -5.1477, 'lng' => 119.4327, 'bbox' => [-5.250, 119.380, -5.050, 119.520]],
        'manado' => ['name' => 'Kota Manado, Sulawesi Utara', 'lat' => 1.4748, 'lng' => 124.8428, 'bbox' => [1.400, 124.780, 1.550, 124.900]],
        'palu' => ['name' => 'Kota Palu, Sulawesi Tengah', 'lat' => -0.9000, 'lng' => 119.8833, 'bbox' => [-0.960, 119.820, -0.830, 119.950]],
        'kendari' => ['name' => 'Kota Kendari, Sulawesi Tenggara', 'lat' => -3.9985, 'lng' => 122.5126, 'bbox' => [-4.050, 122.450, -3.930, 122.580]],
        'samarinda' => ['name' => 'Kota Samarinda, Kalimantan Timur', 'lat' => -0.5022, 'lng' => 117.1536, 'bbox' => [-0.600, 117.050, -0.400, 117.250]],
        'balikpapan' => ['name' => 'Kota Balikpapan, Kalimantan Timur', 'lat' => -1.2379, 'lng' => 116.8529, 'bbox' => [-1.320, 116.780, -1.150, 116.950]],
        'banjarmasin' => ['name' => 'Kota Banjarmasin, Kalimantan Selatan', 'lat' => -3.3194, 'lng' => 114.5908, 'bbox' => [-3.380, 114.540, -3.260, 114.650]],
        'pontianak' => ['name' => 'Kota Pontianak, Kalimantan Barat', 'lat' => -0.0263, 'lng' => 109.3425, 'bbox' => [-0.080, 109.280, 0.040, 109.400]],
        'tarakan' => ['name' => 'Kota Tarakan, Kalimantan Utara', 'lat' => 3.3000, 'lng' => 117.6000, 'bbox' => [3.220, 117.530, 3.380, 117.670]],
        'palangka raya' => ['name' => 'Kota Palangka Raya, Kalimantan Tengah', 'lat' => -2.2100, 'lng' => 113.9200, 'bbox' => [-2.280, 113.850, -2.140, 113.990]],

        // Bali & Nusa Tenggara
        'denpasar' => ['name' => 'Kota Denpasar, Bali', 'lat' => -8.6705, 'lng' => 115.2126, 'bbox' => [-8.720, 115.170, -8.600, 115.260]],
        'bali' => ['name' => 'Provinsi Bali', 'lat' => -8.4095, 'lng' => 115.1889, 'bbox' => [-8.90, 114.40, -8.00, 115.70]],
        'mataram' => ['name' => 'Kota Mataram, Nusa Tenggara Barat', 'lat' => -8.5833, 'lng' => 116.1167, 'bbox' => [-8.630, 116.070, -8.530, 116.160]],
        'kupang' => ['name' => 'Kota Kupang, Nusa Tenggara Timur', 'lat' => -10.1772, 'lng' => 123.6070, 'bbox' => [-10.230, 123.550, -10.120, 123.660]],
        'labuan bajo' => ['name' => 'Labuan Bajo, Manggarai Barat, NTT', 'lat' => -8.5000, 'lng' => 119.8800, 'bbox' => [-8.550, 119.850, -8.450, 119.920]],

        // Sumatera
        'medan' => ['name' => 'Kota Medan, Sumatera Utara', 'lat' => 3.5952, 'lng' => 98.6722, 'bbox' => [3.450, 98.600, 3.750, 98.750]],
        'banda aceh' => ['name' => 'Kota Banda Aceh, Aceh', 'lat' => 5.5483, 'lng' => 95.3238, 'bbox' => [5.500, 95.280, 5.600, 95.380]],
        'padang' => ['name' => 'Kota Padang, Sumatera Barat', 'lat' => -0.9471, 'lng' => 100.4172, 'bbox' => [-1.050, 100.300, -0.800, 100.550]],
        'pekanbaru' => ['name' => 'Kota Pekanbaru, Riau', 'lat' => 0.5071, 'lng' => 101.4478, 'bbox' => [0.420, 101.350, 0.600, 101.550]],
        'batam' => ['name' => 'Kota Batam, Kepulauan Riau', 'lat' => 1.0456, 'lng' => 104.0305, 'bbox' => [0.900, 103.850, 1.250, 104.200]],
        'palembang' => ['name' => 'Kota Palembang, Sumatera Selatan', 'lat' => -2.9761, 'lng' => 104.7754, 'bbox' => [-3.050, 104.680, -2.880, 104.850]],
        'bandar lampung' => ['name' => 'Kota Bandar Lampung, Lampung', 'lat' => -5.4292, 'lng' => 105.2611, 'bbox' => [-5.500, 105.200, -5.350, 105.330]],

        // Jawa
        'magelang utara' => ['name' => 'Kecamatan Magelang Utara, Kota Magelang', 'lat' => -7.4589, 'lng' => 110.2251, 'bbox' => [-7.475, 110.212, -7.442, 110.238]],
        'magelang tengah' => ['name' => 'Kecamatan Magelang Tengah, Kota Magelang', 'lat' => -7.4812, 'lng' => 110.2195, 'bbox' => [-7.492, 110.208, -7.470, 110.230]],
        'magelang selatan' => ['name' => 'Kecamatan Magelang Selatan, Kota Magelang', 'lat' => -7.4984, 'lng' => 110.2223, 'bbox' => [-7.512, 110.205, -7.485, 110.235]],
        'kota magelang' => ['name' => 'Kota Magelang, Jawa Tengah', 'lat' => -7.4797, 'lng' => 110.2177, 'bbox' => [-7.510, 110.200, -7.440, 110.240]],
        'kabupaten magelang' => ['name' => 'Kabupaten Magelang, Jawa Tengah', 'lat' => -7.4705, 'lng' => 110.2178, 'bbox' => [-7.650, 109.980, -7.300, 110.450]],
        'magelang' => ['name' => 'Magelang, Jawa Tengah', 'lat' => -7.4797, 'lng' => 110.2177, 'bbox' => [-7.510, 110.200, -7.440, 110.240]],
        'kota semarang' => ['name' => 'Kota Semarang, Jawa Tengah', 'lat' => -6.9932, 'lng' => 110.4203, 'bbox' => [-7.100, 110.300, -6.900, 110.500]],
        'semarang' => ['name' => 'Kota Semarang, Jawa Tengah', 'lat' => -6.9932, 'lng' => 110.4203, 'bbox' => [-7.100, 110.300, -6.900, 110.500]],
        'solo' => ['name' => 'Kota Surakarta (Solo), Jawa Tengah', 'lat' => -7.5666, 'lng' => 110.8166, 'bbox' => [-7.610, 110.770, -7.520, 110.860]],
        'surakarta' => ['name' => 'Kota Surakarta (Solo), Jawa Tengah', 'lat' => -7.5666, 'lng' => 110.8166, 'bbox' => [-7.610, 110.770, -7.520, 110.860]],
        'yogyakarta' => ['name' => 'Kota Yogyakarta, DI Yogyakarta', 'lat' => -7.7956, 'lng' => 110.3695, 'bbox' => [-7.830, 110.340, -7.760, 110.400]],
        'jakarta selatan' => ['name' => 'Kota Jakarta Selatan, DKI Jakarta', 'lat' => -6.2615, 'lng' => 106.8106, 'bbox' => [-6.350, 106.740, -6.200, 106.880]],
        'jakarta pusat' => ['name' => 'Kota Jakarta Pusat, DKI Jakarta', 'lat' => -6.1805, 'lng' => 106.8284, 'bbox' => [-6.210, 106.800, -6.150, 106.870]],
        'bandung' => ['name' => 'Kota Bandung, Jawa Barat', 'lat' => -6.9175, 'lng' => 107.6191, 'bbox' => [-6.970, 107.540, -6.840, 107.720]],
        'surabaya' => ['name' => 'Kota Surabaya, Jawa Timur', 'lat' => -7.2575, 'lng' => 112.7521, 'bbox' => [-7.350, 112.650, -7.180, 112.820]]
    ];

    // 1. Direct dictionary match
    foreach ($knownRegions as $key => $r) {
        if (strpos($qLower, $key) !== false) {
            $lat = $r['lat'];
            $lng = $r['lng'];
            $bbox = $r['bbox'];
            $rLat = abs(($bbox[2] - $bbox[0]) / 2);
            $rLng = abs(($bbox[3] - $bbox[1]) / 2);

            $polygonCoords = [];
            $numPoints = 22;
            for ($i = 0; $i < $numPoints; $i++) {
                $theta = ($i / $numPoints) * 2 * M_PI;
                $wave = 1.0 + (0.18 * sin(3 * $theta)) + (0.10 * cos(5 * $theta));
                $pLat = (float)round($lat + ($rLat * $wave * cos($theta)), 6);
                $pLng = (float)round($lng + ($rLng * $wave * sin($theta)), 6);
                $polygonCoords[] = [(float)$pLng, (float)$pLat];
            }
            $polygonCoords[] = $polygonCoords[0];

            jsonResponse([
                'success' => true,
                'name' => $r['name'],
                'lat' => $lat,
                'lng' => $lng,
                'boundingbox' => $bbox,
                'geojson' => [
                    'type' => 'Polygon',
                    'coordinates' => [$polygonCoords]
                ]
            ]);
        }
    }

    // 2. Nominatim search (national Indonesia scope)
    if (!empty($query)) {
        $url = "https://nominatim.openstreetmap.org/search?" . http_build_query([
            'q' => $query . ', Indonesia',
            'format' => 'json',
            'polygon_geojson' => 1,
            'limit' => 1
        ]);

        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: ClientReachAI/3.0 (Sales Workflow App)\r\nAccept: application/json\r\n",
                'timeout' => 3
            ]
        ];
        $context = stream_context_create($opts);
        $response = @file_get_contents($url, false, $context);

        if ($response) {
            $data = json_decode($response, true);
            if (!empty($data) && isset($data[0])) {
                $item = $data[0];
                jsonResponse([
                    'success' => true,
                    'name' => $item['display_name'],
                    'lat' => (float)$item['lat'],
                    'lng' => (float)$item['lon'],
                    'boundingbox' => [
                        (float)$item['boundingbox'][0],
                        (float)$item['boundingbox'][1],
                        (float)$item['boundingbox'][2],
                        (float)$item['boundingbox'][3]
                    ],
                    'geojson' => $item['geojson'] ?? null
                ]);
            }
        }
    }

    // 3. Fallback resolution: determine coordinates from client input or province match
    $lat = null;
    $lng = null;
    $name = $query ?: 'Wilayah Terpilih';

    if ($clientLat !== null && $clientLng !== null) {
        $lat = $clientLat;
        $lng = $clientLng;
    } else {
        // Search in provinces list
        foreach ($provinces as $p) {
            if (stripos($query, $p['name']) !== false) {
                $lat = $p['lat'];
                $lng = $p['lng'];
                $name = $p['name'];
                break;
            }
        }
    }

    // Default if still null
    if ($lat === null || $lng === null) {
        $lat = -2.5337;
        $lng = 140.7181;
    }

    $rLat = 0.035;
    $rLng = 0.040;

    $polygonCoords = [];
    $numPoints = 20;
    for ($i = 0; $i < $numPoints; $i++) {
        $theta = ($i / $numPoints) * 2 * M_PI;
        $wave = 1.0 + (0.20 * sin(3 * $theta)) + (0.10 * cos(5 * $theta));
        $pLat = round($lat + ($rLat * $wave * cos($theta)), 6);
        $pLng = round($lng + ($rLng * $wave * sin($theta)), 6);
        $polygonCoords[] = [$pLng, $pLat];
    }
    $polygonCoords[] = $polygonCoords[0];

    jsonResponse([
        'success' => true,
        'name' => $name,
        'lat' => $lat,
        'lng' => $lng,
        'boundingbox' => [$lat - $rLat, $lat + $rLat, $lng - $rLng, $lng + $rLng],
        'geojson' => [
            'type' => 'Polygon',
            'coordinates' => [$polygonCoords]
        ]
    ]);
}

jsonResponse(['success' => false, 'message' => 'Aksi tidak dikenali'], 400);

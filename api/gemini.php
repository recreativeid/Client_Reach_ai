<?php
/**
 * Client Reach AI - Google Gemini AI Sales Engine
 * Generates personalized, high-converting WhatsApp cold outreach pitches
 * Features:
 * - Text-based personalization (Target business name, category, rating, location)
 * - Multimodal Vision Support (Upload flyer/promo image -> AI reads promo details & weaves them into the pitch)
 * - Psychological triggers: Warm polite introduction, customer pain points, creating genuine need, attractive closing CTA.
 */
require_once __DIR__ . '/../config.php';

// Set response headers
header('Content-Type: application/json; charset=utf-8');

$rawInput = file_get_contents('php://input');
$jsonInput = !empty($rawInput) ? json_decode($rawInput, true) : [];
$action = $_GET['action'] ?? ($jsonInput['action'] ?? ($_POST['action'] ?? 'generate_pitch'));
$apiKey = defined('GEMINI_API_KEY') && !empty(GEMINI_API_KEY) ? GEMINI_API_KEY : (getenv('GEMINI_API_KEY') ?: '');


if ($action === 'generate_pitch') {
    $input = !empty($jsonInput) ? $jsonInput : $_POST;

    $businessName = trim($input['business_name'] ?? 'Kopi Senja');
    $category     = trim($input['category'] ?? 'Cafe & Coffee Shop');
    $address      = trim($input['address'] ?? 'Magelang');
    $rating       = trim($input['rating'] ?? '4.8');
    $reviewsCount = trim($input['reviews_count'] ?? '85');
    $hasWebsite   = !empty($input['has_website']) ? 'Ya' : 'Belum Ada';
    
    // User's service & campaign parameters
    $myService    = trim($input['my_service'] ?? 'Jasa Optimasi Google Maps & Review Booster');
    $myOffer      = trim($input['my_offer'] ?? 'Free Audit Profil Google Maps & Review Booster');
    $painPoint    = trim($input['pain_point'] ?? 'Peringkat pencarian di Google Maps belum optimal & ingin hemat biaya iklan');
    $tone         = trim($input['tone'] ?? 'Hangat, Sopan & Profesional');
    $customNotes  = trim($input['custom_notes'] ?? '');

    // Multimodal Image (Base64)
    $imageBase64  = $input['image_base64'] ?? null;
    $imageMime    = $input['image_mime'] ?? 'image/jpeg';

    // If an image was uploaded via $_FILES
    if (isset($_FILES['promo_image']) && $_FILES['promo_image']['error'] === UPLOAD_ERR_OK) {
        $tmpPath = $_FILES['promo_image']['tmp_name'];
        $imageMime = mime_content_type($tmpPath) ?: 'image/jpeg';
        $imageBase64 = base64_encode(file_get_contents($tmpPath));
    }

    // Clean up base64 header if sent with data:image/...;base64,
    if ($imageBase64 && strpos($imageBase64, 'base64,') !== false) {
        $parts = explode('base64,', $imageBase64);
        $imageBase64 = $parts[1];
    }

    // Build the AI Prompt following high-converting sales psychology
    $prompt = "Kamu adalah Master AI Sales Copywriter untuk B2B dan UMKM Indonesia.
Tugasmu adalah membuat 1 naskah pesan WhatsApp cold outreach yang SANGAT PERSUASIF, SOPAN, HANGAT, dan MENGGERAKKAN MINAT CLOSING untuk pemilik/manajemen bisnis sasaran berikut:

--- DATA TARGET CALON KLIEN ---
- Nama Bisnis: {$businessName}
- Kategori Usaha: {$category}
- Alamat/Lokasi: {$address}
- Rating Google Maps: {$rating} ⭐ ({$reviewsCount} ulasan)
- Status Website: {$hasWebsite}

--- PENAWARAN & SOLUSI KITA ---
- Layanan/Produk Kita: {$myService}
- Tawaran Spesial / Diskon / Lead Magnet: {$myOffer}
- Titik Masalah Target (Pain Point): {$painPoint}
- Gaya Bahasa / Tone: {$tone} (Bahasa Indonesia santun, ramah, bersahabat, tidak kaku, dan TIDAK terkesan spam robotik)
" . ($customNotes ? "- Catatan Tambahan Pengguna: {$customNotes}\n" : "");

    if ($imageBase64) {
        $prompt .= "\n--- INSTRUKSI KHUSUS GAMBAR PROMOSI ---
Pengguna melampirkan sebuah gambar promosi / flyer / brosur penawaran.
Tolong BACA dan ANALISIS teks serta isi promosi pada gambar tersebut!
Sertakan poin penting dari gambar tersebut (misalnya diskon, bonus, keunggulan, atau penawaran terbatas di gambar) ke dalam pesan WhatsApp secara sangat mulus dan menggoda selera pemilik toko, sehingga mereka merasa promo ini sangat relevan dan sayang untuk dilewatkan.";
    }

    $prompt .= "\n\n--- STRUKTUR & PSIKOLOGI PESAN YANG WAJIB DIIKUTI ---
1. **AWALAN PERKENALAN YANG BAIK & RESPEK**:
   - Sapa dengan ramah menyebut nama toko *{$businessName}* secara spesifik.
   - Berikan pujian/apresiasi tulus atas bisnis mereka di {$address} atau reputasi ulasannya ({$rating} ⭐).
2. **MEMBUAT PELANGGAN MERASA BUTUH (CREATE NEED & PAIN POINT)**:
   - Singgung secara halus tantangan atau potensi tersembunyi bisnis mereka ({$painPoint}).
   - Buat mereka sadar bahwa kompetitor terus bergerak dan mereka berpotensi kehilangan pelanggan lokal jika tidak segera memanfaatkan peluang ini.
3. **SOLUSI & PENAWARAN MENARIK**:
   - Jelaskan bagaimana layanan kita ({$myService}) dan penawaran ({$myOffer}) bisa membantu mereka menambah pelanggan dan menghemat anggaran iklan secara nyata.
   " . ($imageBase64 ? "- Sebutkan bahwa ada materi/flyer promo menarik yang sedang kita siapkan untuk *{$businessName}*." : "") . "
4. **CLOSING RAMAH TANPA PAKSAAN (LOW-FRICTION SOFT CTA)**:
   - Ajak diskusi santai yang mudah dijawab (misal: 'Kira-kira jika kami kirimkan ulasan/audit singkatnya via WhatsApp ini, apakah berkenan kak? Murni berbagi insight tanpa biaya 🙏').
5. **FORMATTING**:
   - Gunakan format WhatsApp (gunakan *bold* untuk nama bisnis dan penawaran utama, serta emoji secukupnya agar estetik dan hangat).
   - Panjang ideal: 3-4 paragraf ringkas yang enak dibaca di layar HP (mobile-friendly).
   - HANYA keluarkan naskah pesan WhatsApp siap kirim, jangan tambahkan kalimat pembuka/penutup seperti 'Berikut adalah pesan...'.";

    $aiResponseText = null;

    // Build Gemini contents payload
    $parts = [
        ['text' => $prompt]
    ];

    if ($imageBase64) {
        $parts[] = [
            'inlineData' => [
                'mimeType' => $imageMime,
                'data' => $imageBase64
            ]
        ];
    }

    // Primary and fallback models
    $models = ['gemini-3.6-flash', 'gemini-flash-latest', 'gemini-3.5-flash-lite'];

    foreach ($models as $modelName) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$modelName}:generateContent?key={$apiKey}";
        $postData = json_encode([
            'contents' => [
                ['parts' => $parts]
            ],
            'generationConfig' => [
                'temperature' => 0.75,
                'maxOutputTokens' => 1000
            ]
        ]);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $res) {
            $data = json_decode($res, true);
            if (isset($data['candidates'][0]['content']['parts'])) {
                foreach ($data['candidates'][0]['content']['parts'] as $p) {
                    if (isset($p['text']) && !empty(trim($p['text']))) {
                        $aiResponseText = trim($p['text']);
                        break 2;
                    }
                }
            }
        }
    }

    // High quality intelligent fallback if Gemini is offline
    if (!$aiResponseText) {
        $aiResponseText = "Halo kak, salam hangat untuk tim manajemen *{$businessName}* di {$address} 👋\n\n"
            . "Kami sempat melihat profil {$businessName} di Google Maps dengan reputasi yang luar biasa (Rating {$rating} ⭐). Kami sangat mengapresiasi kualitas pelayanan kakak yang sudah dipercaya oleh banyak pelanggan.\n\n"
            . "Di era sekarang, persaingan usaha {$category} di sekitar area ini semakin ketat. Kami melihat ada potensi besar bagi *{$businessName}* untuk mendominasi peringkat teratas dan menjaring lebih banyak pembeli lokal baru tanpa harus boros biaya iklan berbayar.\n\n"
            . "Kebetulan tim cliento sedang membuka program *{$myOffer}* khusus pelaku usaha pilihan di kawasan ini. Kami ingin membantu mengoptimalkan visibilitas bisnis kakak agar calon pelanggan di Google langsung memilih *{$businessName}* ketimbang kompetitor.\n\n"
            . "Kira-kira jika kami kirimkan rincian insight singkatnya via WhatsApp ini, boleh kak? Tidak ada ikatan apa pun, murni sharing untuk kemajuan {$businessName} 🙏\n\n"
            . "Terima kasih banyak atas waktunya kak, sukses dan laris selalu usahanya!";
    }

    jsonResponse([
        'success' => true,
        'business_name' => $businessName,
        'category' => $category,
        'pitch' => $aiResponseText,
        'has_image' => !empty($imageBase64)
    ]);
}

jsonResponse(['success' => false, 'message' => 'Action tidak dikenali'], 400);

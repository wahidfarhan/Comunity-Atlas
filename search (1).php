<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Convert English digits to Bangla digits
function en2bn($num) {
    $en = ['0','1','2','3','4','5','6','7','8','9'];
    $bn = ['০','১','২','৩','৪','৫','৬','৭','৮','৯'];
    return str_replace($en, $bn, $num);
}

// Convert Bangla digits to English digits
function bn2en($num) {
    $bn = ['০','১','২','৩','৪','৫','৬','৭','৮','৯'];
    $en = ['0','1','2','3','4','5','6','7','8','9'];
    return str_replace($bn, $en, $num);
}

// Translate text using Google Translate API
function googleTranslate($text) {
    if (empty($text)) return "";
    $url = "https://translate.googleapis.com/translate_a/single?client=gtx&sl=en&tl=bn&dt=t&q=" . urlencode($text);
    $res = @file_get_contents($url);
    if ($res === false) return $text;
    $res = json_decode($res, true);
    $trans = "";
    if (isset($res[0])) {
        foreach ($res[0] as $line) {
            $trans .= $line[0];
        }
    }
    return $trans;
}

// Get weather data for a location
function getWeatherData($query) {
    $geo_url = "https://geocoding-api.open-meteo.com/v1/search?name=" . urlencode($query) . "&count=1&language=en&format=json";
    $geo_res = @file_get_contents($geo_url);
    $geo_data = json_decode($geo_res, true);
    if (isset($geo_data['results'][0])) {
        $lat = $geo_data['results'][0]['latitude'];
        $lon = $geo_data['results'][0]['longitude'];
        $weather_url = "https://api.open-meteo.com/v1/forecast?latitude=$lat&longitude=$lon&current_weather=true";
        $weather_res = @file_get_contents($weather_url);
        return json_decode($weather_res, true);
    }
    return null;
}

// Get latest news for a location
function getLiveGoogleNews($query) {
    $rss_url = "https://news.google.com/rss/search?q=" . urlencode($query . " bangla news") . "&hl=bn&gl=BD&ceid=BD:bn";
    $xml = @simplexml_load_file($rss_url);
    $news_items = [];
    if ($xml) {
        foreach ($xml->channel->item as $item) {
            $description = (string)$item->description;
            preg_match('/<img[^>]+src="([^">]+)"/i', $description, $matches);
            $img_url = isset($matches[1]) ? $matches[1] : '';

            $news_items[] = [
                'title' => (string)$item->title,
                'link' => (string)$item->link,
                'date' => (string)$item->pubDate,
                'thumb' => $img_url
            ];
            if (count($news_items) >= 4) break;
        }
    }
    return $news_items;
}

// Main logic starts here
if (isset($_GET['subarea'])) {
    $subarea = htmlspecialchars(strip_tags($_GET['subarea']));
    $fullTitle = $subarea . " Upazila";

    // Fetch data from Wikipedia
    $wiki_url = "https://en.wikipedia.org/w/api.php?action=query&format=json&prop=extracts|revisions|pageimages|images&explaintext&rvprop=content&pithumbsize=1000&titles=" . rawurlencode($fullTitle) . "&redirects=1";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $wiki_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    $wiki_res = curl_exec($ch);
    curl_close($ch);

    $wiki_data = json_decode($wiki_res, true);
    $pages = $wiki_data['query']['pages'] ?? [];
    $pageId = key($pages);

    if ($pageId == -1) {
        die("<div style='text-align:center; padding:50px;'><h2>তথ্য পাওয়া যায়নি</h2></div>");
    }

    $rawText = $pages[$pageId]['extract'] ?? '';
    $source = $pages[$pageId]['revisions'][0]['*'] ?? '';
    $main_image = $pages[$pageId]['thumbnail']['source'] ?? '';

    // Extract statistics (area, population, households)
    $stats = ['area' => '---', 'pop' => '---', 'households' => '---'];
    if (preg_match('/area_total_km2\s*=\s*([\d,\.]+)/i', $source, $m)) $stats['area'] = $m[1];
    if (preg_match('/population_total\s*=\s*([\d,]+)/i', $source, $m)) $stats['pop'] = $m[1];
    if (preg_match('/households\s*=\s*([\d,]+)/i', $source, $m)) $stats['households'] = $m[1];
    if (preg_match('/households\s*=\s*([\d,]+)/i', $source, $m)) {
        $stats['households'] = $m[1];
    } elseif (preg_match('/units\s*=\s*([\d,]+)/i', $source, $m)) {
        $stats['households'] = $m[1];
    } elseif (preg_match('/number of households\s*[:=]?\s*([\d,]+)/i', $rawText, $m)) {
        $stats['households'] = $m[1];
    } elseif (preg_match('/([\d,০-৯]+)\s*(?:টি পরিবারের একক|টি পরিবার|পরিবার|households)/iu', $rawText, $m)) {
        $stats['households'] = bn2en($m[1]);
    } elseif (preg_match('/খানার সংখ্যা\s*[:=]?\s*([\d,০-৯]+)/iu', $rawText, $m)) {
        $stats['households'] = bn2en($m[1]);
    }

    // Fetch weather and news data
    $weather = getWeatherData($subarea);
    $live_news_data = getLiveGoogleNews($subarea);
    $sections = preg_split('/(==+.*?==+)/', $rawText, -1, PREG_SPLIT_DELIM_CAPTURE);
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($subarea); ?> উপজেলা</title>
    <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
    /* Popup Styles */
.lang-popup {
    position: fixed;
    top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(0,0,0,0.8);
    display: flex; align-items: center; justify-content: center;
    z-index: 9999;
}
.popup-content {
    background: #fff; padding: 30px; border-radius: 15px;
    text-align: center; max-width: 300px;
}
.lang-btn {
    display: block; width: 100%; padding: 12px;
    margin: 10px 0; border: none; border-radius: 8px;
    cursor: pointer; font-weight: bold;
}
        :root { --primary: #064e3b; --accent: #10b981; }
        body { background: #f1f5f9; font-family: 'Hind Siliguri', sans-serif; margin: 0; }
        .sidebar-content { width: 100%; max-width: 600px; margin: auto; background: #fff; box-shadow: 0 0 20px rgba(0,0,0,0.1); }
        .hero-box { position: relative; height: 250px; background: #222; overflow: hidden; }
        .hero-img { width: 100%; height: 100%; object-fit: cover; opacity: 0.7; }
        .hero-overlay { position: absolute; bottom: 0; width: 100%; padding: 20px; background: linear-gradient(transparent, rgba(0,0,0,0.9)); text-align: center; color: #fff; }
        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; padding: 15px; margin-top: -30px; position: relative; }
        .stat-item { background: #fff; padding: 15px 5px; border-radius: 12px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); text-align: center; border-bottom: 4px solid var(--accent); }
        .stat-item h4 { margin: 0; font-size: 16px; color: var(--primary); }
        .news-item-card { display: flex; gap: 12px; background: #fdf2f2; border-radius: 12px; padding: 12px; margin-bottom: 12px; border-left: 5px solid #e53e3e; transition: all 0.3s ease; cursor: pointer; text-decoration: none; }
        .news-item-card:hover { transform: translateY(-3px); box-shadow: 0 8px 15px rgba(229, 62, 62, 0.15); background: #fff5f5; }
        .news-thumb { width: 85px; height: 65px; object-fit: cover; border-radius: 8px; flex-shrink: 0; background: #eee; }
        .info-card { background: #f0fdf4; border: 1px solid #dcfce7; padding: 15px; border-radius: 12px; margin-bottom: 15px; }
        .info-card h5 { margin: 0 0 8px; color: var(--primary); font-size: 16px; display: flex; align-items: center; gap: 8px; }
        .info-card ul { padding-left: 18px; margin: 0; }
        .info-card li { margin-bottom: 8px; font-size: 14px; line-height: 1.5; color: #334155; }
        .sec-title { font-size: 19px; font-weight: 700; color: var(--primary); margin: 25px 0 12px; display: flex; align-items: center; }
        .sec-title:before { content: ""; width: 8px; height: 18px; background: var(--accent); margin-right: 10px; border-radius: 2px; }
        .links-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .link-btn { background: #f8fafc; padding: 10px; border-radius: 8px; text-decoration: none; color: #334155; font-size: 13px; font-weight: 600; text-align: center; border: 1px solid #e2e8f0; transition: 0.2s; }
        .link-btn:hover { background: var(--accent); color: #fff; }
    </style>
</head>
<body>
    
    <div class="sidebar-content">
        <div class="hero-box">
            <?php if ($weather): ?>
                <div style="position: absolute; top: 15px; right: 15px; background: rgba(0,0,0,0.5); padding: 5px 12px; border-radius: 20px; font-size: 13px; color: #fff; backdrop-filter: blur(5px); display: flex; align-items: center; gap: 8px; z-index: 10;">
                    <span>🌡️ <?php echo en2bn($weather['current_weather']['temperature']); ?>°C</span>
                    <span style="opacity: 0.6;">|</span>
                    <span>🕒 <?php echo en2bn(date("h:i A")); ?></span>
                </div>
            <?php endif; ?>
            <img src="<?php echo $main_image ?: 'https://images.unsplash.com/photo-1596434449293-64478160473a?q=80&w=1000'; ?>" class="hero-img">
            <div class="hero-overlay">
                <h1 style="margin:0;"><?php echo googleTranslate($subarea); ?> উপজেলা</h1>
                <p style="font-size: 14px; opacity: 0.9;">ঐতিহ্য ও সমৃদ্ধির প্রশাসনিক অঞ্চল</p>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-item"><small>📏 আয়তন</small><h4><?php echo en2bn($stats['area']); ?> কিমি²</h4></div>
            <div class="stat-item"><small>👥 জনসংখ্যা</small><h4><?php echo en2bn($stats['pop']); ?> জন</h4></div>
            <div class="stat-item"><small>🏠 পরিবার</small><h4><?php echo en2bn($stats['households']); ?> টি</h4></div>
        </div>

        <div style="padding: 15px;">
            <!-- Hospitals Section -->
            <div class='info-card' style='background:#f0f9ff; border-color:#bae6fd;'>
                <h5>🏥 গুরুত্বপূর্ণ হাসপাতালসমূহ</h5>
                <div style='display: flex; flex-direction: column; gap: 12px; margin-top: 10px;'>
                    <?php
                    $hospitals = [
                        ['name' => googleTranslate($subarea) . ' উপজেলা স্বাস্থ্য কমপ্লেক্স', 'phone' => '01300000000'],
                        ['name' => 'জরুরি মেডিকেল সেবা', 'phone' => '16263']
                    ];
                    foreach ($hospitals as $hosp) {
                        echo "
                        <div style='display: flex; align-items: center; justify-content: space-between; background: #fff; padding: 10px; border-radius: 12px; border: 1px solid #e0f2fe; transition: 0.3s;'>
                            <div style='display: flex; align-items: center; gap: 10px;'>
                                <div style='background: #e0f2fe; padding: 8px; border-radius: 50%;'>
                                    <img src='https://cdn-icons-png.flaticon.com/512/684/684262.png' style='width: 25px; height: 25px;' alt='Hosp'>
                                </div>
                                <div>
                                    <div style='font-size: 14px; font-weight: 600; color: #0369a1;'>{$hosp['name']}</div>
                                    <small style='color: #64748b;'>২৪ ঘণ্টা খোলা</small>
                                </div>
                            </div>
                            <a href='tel:{$hosp['phone']}' 
                               style='background: #0ea5e9; color: #fff; padding: 8px 15px; border-radius: 8px; text-decoration: none; font-size: 13px; font-weight: bold; transition: all 0.3s ease; display: inline-block; box-shadow: 0 2px 4px rgba(14, 165, 233, 0.2);'
                               onmouseover=\"this.style.backgroundColor='#0369a1'; this.style.transform='scale(1.05)'; this.style.boxShadow='0 4px 8px rgba(3, 105, 161, 0.3)';\" 
                               onmouseout=\"this.style.backgroundColor='#0ea5e9'; this.style.transform='scale(1)'; this.style.boxShadow='0 2px 4px rgba(14, 165, 233, 0.2)';\">
                               📞 কল দিন
                            </a>
                        </div>";
                    }
                    ?>
                </div>
            </div>

            <!-- Clubs Section -->
            <div class='info-card' style='background:#f8fafc; border-color:#e2e8f0;'>
                <h5 style='color:#1e293b; display:flex; align-items:center; gap:8px;'>🏟️ সামাজিক ও যুব ক্লাবসমূহ</h5>
                <div style='display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 15px;'>
                    <?php
                    $clubs = [
                        ['name' => googleTranslate($subarea) . ' স্কাউটস', 'icon' => '⚜️', 'bg' => '#f5f3ff', 'btn' => '#7c3aed', 'phone' => '01711000000'],
                        ['name' => googleTranslate($subarea) . ' বয়েজ ক্লাব', 'icon' => '⚽', 'bg' => '#eff6ff', 'btn' => '#2563eb', 'phone' => '01811000000'],
                        ['name' => 'গার্লস গাইড দল', 'icon' => '🌸', 'bg' => '#fdf2f8', 'btn' => '#db2777', 'phone' => '01911000000'],
                        ['name' => 'যুব রেড ক্রিসেন্ট', 'icon' => '⛑️', 'bg' => '#fff1f2', 'btn' => '#e11d48', 'phone' => '01611000000']
                    ];
                    foreach ($clubs as $club) {
                        echo "
                        <div style='background: {$club['bg']}; padding: 15px; border-radius: 16px; border: 1px solid rgba(0,0,0,0.05); transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); display: flex; flex-direction: column; align-items: center; text-align: center; position: relative; overflow: hidden;'
                             onmouseover=\"this.style.transform='translateY(-8px)'; this.style.boxShadow='0 10px 20px rgba(0,0,0,0.1)';\" 
                             onmouseout=\"this.style.transform='translateY(0)'; this.style.boxShadow='none';\">
                            <div style='font-size: 30px; margin-bottom: 8px; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.1));'>{$club['icon']}</div>
                            <div style='font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 12px; height: 32px; overflow: hidden;'>{$club['name']}</div>
                            <a href='tel:{$club['phone']}' 
                               style='background: {$club['btn']}; color: #fff; width: 100%; padding: 8px 0; border-radius: 10px; text-decoration: none; font-size: 12px; font-weight: bold; transition: all 0.3s ease; display: block; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);'
                               onmouseover=\"this.style.filter='brightness(1.2)'; this.style.letterSpacing='1px';\" 
                               onmouseout=\"this.style.filter='brightness(1)'; this.style.letterSpacing='normal';\">
                               📞 যোগাযোগ
                            </a>
                        </div>";
                    }
                    ?>
                </div>
            </div>

            <!-- Education Section -->
            <?php if (preg_match('/==\s*(Education|Educational institutions)\s*==\s*(.*?)(?===|$)/is', $rawText, $match)) {
                $edu_text = trim($match[2]);
                echo "<div class='info-card'><h5>🎓 শিক্ষা প্রতিষ্ঠান ও বিশ্ববিদ্যালয়</h5><ul>";
                $edu_lines = explode("\n", $edu_text);
                foreach (array_slice($edu_lines, 0, 5) as $line) {
                    $line = trim(str_replace('*', '', $line));
                    if (!empty($line)) echo "<li>" . googleTranslate($line) . "</li>";
                }
                echo "</ul></div>";
            } ?>

            <!-- Notable People Section -->
            <?php if (preg_match('/==\s*(Notable people|Notable residents|Personalities)\s*==\s*(.*?)(?===|$)/is', $rawText, $match)) {
                $people_text = trim($match[2]);
                echo "<div class='info-card' style='background:#fffbeb; border-color:#fef08a;'><h5>🌟 কৃতি ব্যক্তিত্ব ও অবদান</h5><ul>";
                $people_lines = explode("\n", $people_text);
                foreach ($people_lines as $line) {
                    $line = trim(str_replace('*', '', $line));
                    if (!empty($line) && strlen($line) > 5) {
                        echo "<li>" . googleTranslate($line) . "</li>";
                    }
                }
                echo "</ul></div>";
            } ?>

            <!-- News Section -->
            <div class="sec-title">📰 সাম্প্রতিক সংবাদ</div>
            <?php if (!empty($live_news_data)): ?>
                <?php foreach ($live_news_data as $news): ?>
                    <a href="<?php echo $news['link']; ?>" target="_blank" class="news-item-card">
                        <?php if (!empty($news['thumb'])): ?>
                            <img src="<?php echo $news['thumb']; ?>" class="news-thumb">
                        <?php endif; ?>
                        <div style="flex:1;">
                            <div style="color:#1a202c; font-size:14px; font-weight:600; line-height:1.4;"><?php echo $news['title']; ?></div>
                            <small style="display:block; color:#718096; margin-top:8px;">⏳ <?php echo en2bn(date("d M, Y", strtotime($news['date']))); ?></small>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- Quick Links -->
            <div class="sec-title">🔗 দ্রুত লিঙ্ক</div>
            <div class="links-grid">
                <a href="https://www.google.com/maps/search/<?php echo urlencode($subarea); ?>+Upazila" target="_blank" class="link-btn">📍 ম্যাপে দেখুন</a>
                <a href="https://www.youtube.com/results?search_query=<?php echo urlencode($subarea." tourist places"); ?>" target="_blank" class="link-btn">📽️ ভিডিও ট্যুর</a>
                <a href="https://<?php echo strtolower($subarea); ?>.gov.bd" target="_blank" class="link-btn">🌐 সরকারি তথ্য</a>
                <a href="tel:999" class="link-btn" style="color:red;">🚨 জরুরি (৯৯৯)</a>
            </div>

            <!-- Wikipedia Sections -->
            <?php
            foreach ($sections as $text) {
                $text = trim($text);
                if (preg_match('/^==+(.*?)==+$/', $text, $match)) {
                    $t = strtolower(trim($match[1]));
                    if (in_array($t, ['notes', 'references', 'external links', 'education', 'notable people', 'notable residents'])) continue;
                    echo "<div class='sec-title'>" . googleTranslate($match[1]) . "</div>";
                } else {
                    if (!empty($text) && strlen($text) > 50) {
                        echo "<p style='font-size:15px; color:#333; line-height:1.8; text-align:justify;'>" . nl2br(googleTranslate($text)) . "</p>";
                    }
                }
            }
            ?>
        </div>
    </div>
 

</body>
</html>
<?php } ?>
